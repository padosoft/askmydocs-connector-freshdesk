<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tools;

use Closure;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\FreshdeskApiException;
use Padosoft\AskMyDocsConnectorFreshdesk\Support\Markdown;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;

final readonly class FreshdeskTools
{
    public function __construct(private TenantContext $tenants, private SyncManager $sync) {}

    /**
     * @return array<string|int,mixed>
     */
    public function catalog(?string $projectKey = null): array
    {
        if (! config('connector-freshdesk.chat_tools.enabled', true)) {
            return [];
        }
        $tools = [];
        $installations = ConnectorInstallation::query()->where('tenant_id', $this->tenants->current())->where('connector_name', 'freshdesk')->where('status', 'active')->get();
        foreach ($installations as $installation) {
            if (! $this->matchesProject($installation, $projectKey)) {
                continue;
            }
            foreach ($this->definitions() as $action => $definition) {
                $tools[] = [
                    'name' => 'freshdesk_'.$installation->id.'_'.$action,
                    'description' => $definition['description'].' Account: '.$installation->label.'.',
                    'inputSchema' => $definition['schema'],
                    'outputSchema' => $this->outputSchema($action),
                    'capability' => $this->capability($action, (int) $installation->id),
                    'installation_id' => $installation->id, 'action' => $action,
                    'provenance' => ['source' => 'freshdesk', 'installation_id' => $installation->id],
                    'account_label' => $installation->label,
                ];
            }
        }

        return $tools;
    }

    /**
     * @param  array<string|int,mixed>  $arguments
     * @return array<string|int,mixed>
     */
    public function execute(string $name, array $arguments, ?string $projectKey = null, ?Closure $beforeRequest = null): array
    {
        $tool = collect($this->catalog($projectKey))->firstWhere('name', $name);
        if ($tool === null) {
            throw new \RuntimeException('Freshdesk tool is no longer available in this project.');
        }
        $action = $tool['action'];
        $schema = $this->definitions()[$action]['schema'];
        foreach (array_keys($arguments) as $key) {
            if (! isset($schema['properties'][$key])) {
                throw new \InvalidArgumentException('Unknown Freshdesk tool argument.');
            }
        }
        foreach ($schema['required'] ?? [] as $required) {
            if (! isset($arguments[$required])) {
                throw new \InvalidArgumentException('Missing Freshdesk tool argument: '.$required);
            }
        }
        foreach ($arguments as $key => $value) {
            $type = $schema['properties'][$key]['type'];
            if (($type === 'integer' && (! is_int($value) || $value < 1)) || ($type === 'string' && (! is_string($value) || trim($value) === '' || strlen($value) > 512))) {
                throw new \InvalidArgumentException('Invalid Freshdesk tool argument: '.$key);
            }
        }
        $arguments = FreshdeskArguments::normalize($action, $arguments);
        $installation = $this->sync->installation((int) $tool['installation_id']);
        $client = $this->sync->client($installation, $beforeRequest);
        $page = (int) ($arguments['page'] ?? 1);
        $id = (int) ($arguments['id'] ?? 0);
        try {
            if (($action === 'search_tickets' && ! FreshdeskArguments::validQuery($arguments['query']))
                || ($action === 'list_tickets' && isset($arguments['requester_id'], $arguments['email']))) {
                return ['error' => 'Use a structured Freshdesk filter, or resolve the contact and list tickets by requester_id or email.',
                    'error_code' => 'invalid_query', 'http_status' => null, 'retryable' => false, 'validation_fields' => [],
                    'physical_request_count' => 0, 'provenance' => $tool['provenance']];
            }
            if ($page > ($action === 'search_tickets' ? 10 : 300)) {
                throw new \InvalidArgumentException('Freshdesk page exceeds the API limit.');
            }
            $data = match ($action) {
                'list_tickets' => $client->get('/tickets', [...$arguments, 'include' => 'description', 'updated_since' => $arguments['updated_since'] ?? now()->subDays(90)->toIso8601ZuluString()]),
                'search_tickets' => $client->get('/search/tickets', ['query' => '"'.trim((string) $arguments['query'], '"').'"', 'page' => $page]),
                'search_contacts' => $client->get('/contacts/autocomplete', ['term' => $arguments['term']]),
                'get_contact' => $client->get('/contacts/'.$id),
                'search_agents' => $client->get('/agents/autocomplete', ['term' => $arguments['term']]),
                'get_ticket' => $client->get('/tickets/'.$id),
                'list_conversations' => $client->get('/tickets/'.$id.'/conversations', ['page' => $page, 'per_page' => 100]),
                'search_articles' => $client->get('/search/solutions', ['term' => $arguments['term']]),
                'get_article' => $client->get('/solutions/articles/'.$id),
            };
            $total = $action === 'search_tickets' ? ($data['total'] ?? null) : null;
            if ($action === 'search_tickets') {
                $data = $data['results'] ?? [];
            }
            if (array_is_list($data)) {
                $rows = array_values(array_filter($data, fn ($row) => $this->visible($row, $action, $installation)));
            } else {
                $rows = $this->visible($data, $action, $installation) ? [$data] : [];
            }
            $records = array_map(fn ($row) => $this->record($row, $action, $client->domain()), $rows);
            $perPage = $action === 'list_tickets' ? $arguments['per_page'] : ($action === 'search_tickets' ? 30 : 100);
            $paginated = in_array($action, ['list_tickets', 'search_tickets', 'list_conversations'], true);
            $hasMore = $paginated && ($total !== null ? $total > $page * $perPage : count($data) >= $perPage);
            $personLookup = in_array($action, ['search_contacts', 'search_agents'], true);
            $normalizeName = static fn (string $name): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
            $exact = count($records) === 1 && ($normalizeName((string) ($records[0]['name'] ?? '')) === $normalizeName((string) ($arguments['term'] ?? ''))
                || ($action === 'search_agents' && mb_strtolower((string) ($records[0]['email'] ?? '')) === mb_strtolower((string) ($arguments['term'] ?? ''))));
            $result = ['records' => [], 'page' => $page, 'truncated' => false, 'has_more' => $hasMore,
                'meta' => ['ambiguous' => $personLookup && $records !== [] && ! $exact,
                    'term_hash' => $personLookup ? hash('sha256', $normalizeName($arguments['term'])) : null],
                'coverage' => ['updated_since' => $arguments['updated_since'] ?? ($action === 'list_tickets' ? now()->subDays(90)->toIso8601ZuluString() : null),
                    'requester_id' => $arguments['requester_id'] ?? null, 'email' => $arguments['email'] ?? null,
                    'email_hash' => isset($arguments['email']) ? hash('sha256', mb_strtolower($arguments['email'])) : null,
                    'filter' => $arguments['query'] ?? null, 'page_limit' => $paginated ? ($action === 'search_tickets' ? 10 : 300) : null,
                    'scope' => $paginated ? 'api_enumerable' : 'lookup', 'complete' => ! $hasMore,
                    'limitations' => $paginated ? array_values(array_filter([str_contains($action, 'tickets') ? 'archived_tickets_not_enumerable' : null, $hasMore && $page >= ($action === 'search_tickets' ? 10 : 300) ? 'pagination_limit' : null])) : []]];
            if ($total !== null) {
                $result['total'] = $total;
            }
            foreach ($records as $record) {
                if ($record['body_truncated'] ?? false) {
                    $result['truncated'] = true;
                    $result['coverage']['complete'] = false;
                }
                $next = $result;
                $next['records'][] = $record;
                if (strlen(json_encode($next, JSON_THROW_ON_ERROR)) > (int) config('connector-freshdesk.chat_tools.max_result_bytes', 65536)) {
                    $result['truncated'] = true;
                    $result['coverage']['complete'] = false;
                    break;
                }
                $result = $next;
            }

            return ['data' => $result, 'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage(), ...($exception instanceof FreshdeskApiException ? $exception->diagnostics() : ['error_code' => 'request_failed', 'http_status' => null, 'retryable' => ! ($exception instanceof \InvalidArgumentException), 'validation_fields' => []]), 'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
        }
    }

    private function matchesProject(ConnectorInstallation $installation, ?string $project): bool
    {
        $scope = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));

        return $project !== null && $project !== '' && $scope === $project;
    }

    /**
     * @param  array<string|int,mixed>  $row
     */
    private function visible(array $row, string $action, ConnectorInstallation $installation): bool
    {
        if (str_contains($action, 'article')) {
            return (int) ($row['status'] ?? 1) === 2;
        }

        return $action !== 'list_conversations' || data_get($installation->config_json, 'include_private_notes', true) || ! ($row['private'] ?? false);
    }

    /**
     * @param  array<string|int,mixed>  $row
     * @return array<string|int,mixed>
     */
    private function record(array $row, string $action, string $domain): array
    {
        $fields = array_intersect_key($row, array_flip(['id', 'name', 'email', 'company_id', 'requester_id', 'responder_id', 'subject', 'title', 'status', 'priority', 'created_at', 'updated_at', 'private', 'incoming', 'tags', 'category_id', 'folder_id']));
        $body = Markdown::body($row);
        $fields['body'] = mb_substr($body, 0, 16000);
        if (mb_strlen($body) > 16000) {
            $fields['body_truncated'] = true;
        }
        $fields['attachments'] = array_map(fn ($file) => array_intersect_key($file, array_flip(['id', 'name', 'content_type', 'file_size'])), (array) ($row['attachments'] ?? []));
        if ($action !== 'list_conversations' && isset($row['id'])) {
            $fields['url'] = 'https://'.$domain.(str_contains($action, 'article') ? '/support/solutions/articles/' : (str_contains($action, 'contact') ? '/a/contacts/' : (str_contains($action, 'agent') ? '/a/admin/agents/' : '/a/tickets/'))).(int) $row['id'];
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    private function capability(string $action, int $installationId): array
    {
        $entity = str_contains($action, 'contact') ? 'contact' : (str_contains($action, 'agent') ? 'agent' : (str_contains($action, 'article') ? 'article' : 'ticket'));
        $operation = str_starts_with($action, 'search') ? 'search' : (str_starts_with($action, 'list') ? 'list' : 'get');
        $next = match ($action) {
            'search_contacts' => ['get_contact', 'list_tickets'],
            'get_contact' => ['list_tickets'],
            'search_agents' => ['search_tickets'],
            'list_tickets', 'search_tickets' => ['get_ticket'],
            'get_ticket' => ['list_conversations'],
            default => [],
        };

        return ['entity' => $entity, 'operation' => $operation, 'collection_path' => 'records', 'identity_fields' => ['id'],
            'next_tools' => array_map(static fn ($name) => 'freshdesk_'.$installationId.'_'.$name, $next),
            'query_semantics' => $action === 'search_tickets' ? 'structured_filter' : 'text'];
    }

    /** @return array<string,mixed> */
    private function outputSchema(string $action): array
    {
        $properties = ['id' => ['type' => 'integer']];
        foreach (['name', 'email', 'subject', 'body', 'url'] as $field) {
            $properties[$field] = ['type' => 'string'];
        }
        foreach (['requester_id', 'responder_id', 'status', 'priority'] as $field) {
            $properties[$field] = ['type' => ['integer', 'null']];
        }

        return ['type' => 'object', 'properties' => ['records' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $properties]],
            'has_more' => ['type' => 'boolean'], 'truncated' => ['type' => 'boolean'], 'coverage' => ['type' => 'object'], 'meta' => ['type' => 'object']]];
    }

    /**
     * @return array<string|int,mixed>
     */
    private function definitions(): array
    {
        $integer = ['type' => 'integer', 'minimum' => 1];
        $string = ['type' => 'string', 'maxLength' => 512];
        $make = static fn (string $description, array $properties, array $required = []) => ['description' => $description, 'schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]];

        return [
            'list_tickets' => $make('List tickets for a verified requester_id OR email. Requester lookups default to all enumerable history, including closed tickets; generic lists default to 90 days. Continue pages while has_more=true.', ['requester_id' => $integer, 'email' => $string, 'updated_since' => $string, 'page' => $integer, 'per_page' => $integer]),
            'search_tickets' => $make('Filter Freshdesk tickets with a Freshdesk query expression, for example status:2 or priority:>2. This is structured filtering, not full text search.', ['query' => $string, 'page' => $integer], ['query']),
            'search_contacts' => $make('Resolve a customer/requester by name before listing their tickets. Proceed only for a unique exact name or a user selection. An ambiguous result requires selection.', ['term' => $string], ['term']),
            'get_contact' => $make('Read a verified contact by numeric ID, including name and email.', ['id' => $integer], ['id']),
            'search_agents' => $make('Resolve an assigned support agent by name or email only when the user explicitly asks for assigned tickets. Then use search_tickets with agent_id:<verified ID>.', ['term' => $string], ['term']),
            'get_ticket' => $make('Read a Freshdesk ticket by its numeric ID.', ['id' => $integer], ['id']),
            'list_conversations' => $make('Read a page of ticket conversations, including private notes when enabled.', ['id' => $integer, 'page' => $integer], ['id']),
            'search_articles' => $make('Search published Freshdesk knowledge base articles by keyword.', ['term' => $string], ['term']),
            'get_article' => $make('Read a published Freshdesk knowledge base article by ID.', ['id' => $integer], ['id']),
        ];
    }
}
