<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tools;

use Closure;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
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
        $installation = $this->sync->installation((int) $tool['installation_id']);
        $client = $this->sync->client($installation, $beforeRequest);
        $page = min($action === 'search_tickets' ? 10 : 300, (int) ($arguments['page'] ?? 1));
        $id = (int) ($arguments['id'] ?? 0);
        try {
            $data = match ($action) {
                'list_tickets' => $client->get('/tickets', ['page' => $page, 'per_page' => min(100, (int) ($arguments['per_page'] ?? 30)), 'include' => 'description', 'updated_since' => $arguments['updated_since'] ?? now()->subDays(90)->toIso8601ZuluString()]),
                'search_tickets' => $client->get('/search/tickets', ['query' => '"'.trim((string) $arguments['query'], '"').'"', 'page' => $page]),
                'get_ticket' => $client->get('/tickets/'.$id),
                'list_conversations' => $client->get('/tickets/'.$id.'/conversations', ['page' => $page, 'per_page' => 100]),
                'search_articles' => $client->get('/search/solutions', ['term' => $arguments['term']]),
                'get_article' => $client->get('/solutions/articles/'.$id),
            };
            if ($action === 'search_tickets') {
                $data = $data['results'] ?? [];
            }
            if (array_is_list($data)) {
                $rows = array_values(array_filter($data, fn ($row) => $this->visible($row, $action, $installation)));
            } else {
                $rows = $this->visible($data, $action, $installation) ? [$data] : [];
            }
            $records = array_map(fn ($row) => $this->record($row, $action, $client->domain()), $rows);
            $result = ['records' => [], 'page' => $page, 'truncated' => false];
            foreach ($records as $record) {
                $next = $result;
                $next['records'][] = $record;
                if (strlen(json_encode($next, JSON_THROW_ON_ERROR)) > (int) config('connector-freshdesk.chat_tools.max_result_bytes', 65536)) {
                    $result['truncated'] = true;
                    break;
                }
                $result = $next;
            }

            return ['data' => $result, 'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage(), 'physical_request_count' => $client->physicalRequests, 'provenance' => $tool['provenance']];
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
        $fields = array_intersect_key($row, array_flip(['id', 'subject', 'title', 'status', 'priority', 'created_at', 'updated_at', 'private', 'incoming', 'tags', 'category_id', 'folder_id']));
        $fields['body'] = mb_substr(Markdown::body($row), 0, 16000);
        $fields['attachments'] = array_map(fn ($file) => array_intersect_key($file, array_flip(['id', 'name', 'content_type', 'file_size'])), (array) ($row['attachments'] ?? []));
        if ($action !== 'list_conversations' && isset($row['id'])) {
            $fields['url'] = 'https://'.$domain.(str_contains($action, 'article') ? '/support/solutions/articles/' : '/a/tickets/').(int) $row['id'];
        }

        return $fields;
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
            'list_tickets' => $make('List Freshdesk tickets updated since an ISO 8601 timestamp. Defaults to 90 days.', ['updated_since' => $string, 'page' => $integer, 'per_page' => $integer]),
            'search_tickets' => $make('Filter Freshdesk tickets with a Freshdesk query expression, for example status:2 or priority:>2. This is structured filtering, not full text search.', ['query' => $string, 'page' => $integer], ['query']),
            'get_ticket' => $make('Read a Freshdesk ticket by its numeric ID.', ['id' => $integer], ['id']),
            'list_conversations' => $make('Read a page of ticket conversations, including private notes when enabled.', ['id' => $integer, 'page' => $integer], ['id']),
            'search_articles' => $make('Search published Freshdesk knowledge base articles by keyword.', ['term' => $string], ['term']),
            'get_article' => $make('Read a published Freshdesk knowledge base article by ID.', ['id' => $integer], ['id']),
        ];
    }
}
