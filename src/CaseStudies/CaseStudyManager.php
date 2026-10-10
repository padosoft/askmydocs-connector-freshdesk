<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\UrlPolicy;
use Padosoft\AskMyDocsConnectorFreshdesk\Support\Markdown;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SourceState;

/** Local derived documents only. This service never receives a Freshdesk HTTP client. */
final readonly class CaseStudyManager
{
    public function __construct(private ConnectorIngestionContract $ingestion, private TenantContext $tenants) {}

    /**
     * @param  array<string|int,mixed>  $ticket
     * @param  list<array<string|int,mixed>>  $conversations
     */
    public function schedule(ConnectorInstallation $installation, array $ticket, array $conversations, bool $changed): void
    {
        if (! config('connector-freshdesk.case_studies.enabled', false)) {
            return;
        }
        $this->assertTenant($installation);
        $ticketId = (string) $ticket['id'];
        $source = $this->source($installation, $ticketId);
        $case = $this->cases()->where('installation_id', $installation->id)->where('ticket_id', $ticketId)->first();
        // Do not create case sheets for the existing backlog during an ordinary sync.
        if ($source === null || (! $changed && $case === null) || (! $changed && in_array($case->status, ['ready', 'cancelled', 'queued', 'running'], true))) {
            return;
        }
        if ($case === null || $case->source_fingerprint !== $source->fingerprint || $case->status === 'cancelled') {
            $this->removeDocument($installation, $ticketId);
            $sources = [$this->snapshotSource('description', null, Markdown::body($ticket), $ticket['created_at'] ?? null)];
            foreach ($conversations as $conversation) {
                $id = (string) ($conversation['id'] ?? '');
                if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1 || in_array('conversation:'.$id, array_column($sources, 'key'), true)) {
                    throw new \UnexpectedValueException('Invalid Freshdesk conversation identifier.');
                }
                $sources[] = $this->snapshotSource('conversation:'.$id, $id, Markdown::body($conversation), $conversation['created_at'] ?? null);
            }
            $project = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));
            $input = new CaseStudyInput($installation->tenant_id, $installation->id, $ticketId, (string) $project,
                $this->ingestion->redactContent((string) ($ticket['subject'] ?? 'Ticket')),
                'https://'.UrlPolicy::domain((string) data_get($installation->config_json, 'connection.domain')).'/a/tickets/'.$ticketId, $sources);
            $case = CaseStudy::query()->updateOrCreate(
                ['tenant_id' => $installation->tenant_id, 'installation_id' => $installation->id, 'ticket_id' => $ticketId],
                ['source_fingerprint' => $source->fingerprint, 'private_notes_included' => (bool) data_get($installation->config_json, 'include_private_notes', true),
                    'status' => 'queued', 'snapshot' => $input->toArray(), 'checkpoint' => ['version' => 1, 'next_segment' => 0, 'candidates' => []], 'result' => null, 'error' => null],
            );
        } else {
            $case->forceFill(['status' => 'queued', 'error' => null])->save();
        }
        try {
            GenerateCaseStudy::dispatch($case->id, $case->tenant_id, $case->source_fingerprint);
        } catch (\Throwable $exception) {
            $this->cases()->whereKey($case->id)->where('source_fingerprint', $case->source_fingerprint)->where('status', 'queued')
                ->update(['status' => 'failed', 'error' => 'Case queue dispatch failed; resume on the next ticket sync.']);
            throw $exception;
        }
    }

    /** A checkpoint per source segment bounds work and prevents paid extraction calls being repeated on retry. */
    public function batch(int $caseId, string $fingerprint): ?int
    {
        $case = $this->cases()->whereKey($caseId)->where('source_fingerprint', $fingerprint)->first();
        if ($case === null || in_array($case->status, ['ready', 'cancelled'], true)) {
            return null;
        }
        $lock = Cache::lock('freshdesk-case:'.hash('sha256', $case->tenant_id.':'.$case->installation_id.':'.$case->ticket_id), 660);
        if (! $lock->get()) {
            return 10;
        }
        try {
            $case->refresh();
            if ($case->source_fingerprint !== $fingerprint || in_array($case->status, ['ready', 'cancelled'], true)) {
                return null;
            }
            if (! $this->current($case)) {
                $this->cancelCase($case);

                return null;
            }
            $case->forceFill(['status' => 'running', 'error' => null])->save();
            $input = CaseStudyInput::fromArray($case->snapshot);
            $checkpoint = $case->checkpoint;
            $segments = $input->segments();
            $next = (int) ($checkpoint['next_segment'] ?? 0);
            $generator = app(CaseStudyGeneratorContract::class);
            if (isset($segments[$next])) {
                $candidates = $generator->extract($input, $segments[$next]);
                foreach ($candidates as $candidate) {
                    CaseStudyResult::validateCandidate($input, $candidate);
                    foreach ($candidate['evidence'] as $evidence) {
                        $provided = array_filter($segments[$next], fn ($s) => $s['key'] === $evidence['source_key'] && str_contains($s['text'], $evidence['quote']));
                        if ($provided === []) {
                            throw new \UnexpectedValueException('Evidence was not present in the extraction segment.');
                        }
                    }
                }
                $checkpoint['candidates'] = array_merge($checkpoint['candidates'] ?? [], $candidates);
                $checkpoint['next_segment'] = $next + 1;
                $this->saveCheckpoint($case, $checkpoint);

                return 1;
            }
            if (! isset($checkpoint['compiled'])) {
                $result = $generator->compile($input, $checkpoint['candidates'] ?? []);
                $result->validate($input);
                $checkpoint['compiled'] = ['fields' => $result->fields, 'usage' => $result->usage];
                $this->saveCheckpoint($case, $checkpoint);
            }
            $result = new CaseStudyResult($checkpoint['compiled']['fields'], $checkpoint['compiled']['usage']);
            $result->validate($input);
            DB::transaction(function () use ($case, $fingerprint, $input, $result): void {
                $query = $this->cases()->whereKey($case->id);
                $query->lockForUpdate();
                $latest = $query->first();
                if ($latest === null || $latest->source_fingerprint !== $fingerprint || $latest->status === 'cancelled' || ! $this->current($latest, true)) {
                    return;
                }
                $latest->forceFill(['status' => 'ready', 'result' => ['fields' => $result->fields, 'usage' => $result->usage], 'error' => null])->save();
                $this->publish($latest, $input, $result);
            }, 3);

            return null;
        } catch (\Throwable $exception) {
            $this->cases()->whereKey($caseId)->where('source_fingerprint', $fingerprint)->where('status', '!=', 'cancelled')
                ->update(['status' => 'failed', 'error' => 'Case generation or evidence verification failed; source ticket remains available.']);
            throw new \RuntimeException('Freshdesk case generation failed.', 0, $exception);
        } finally {
            $lock->release();
        }
    }

    /** Validate tenant, installation policy and parent revision again at indexing and retrieval boundaries. */
    public function current(CaseStudy $case, bool $lock = false): bool
    {
        if (! config('connector-freshdesk.case_studies.enabled', false) || $case->tenant_id !== $this->tenants->current()) {
            return false;
        }
        $query = ConnectorInstallation::query()->where('tenant_id', $case->tenant_id)->whereKey($case->installation_id);
        $installation = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($installation === null || $installation->connector_name !== 'freshdesk' || ! in_array($installation->status, ['active', 'errored'], true)
            || $case->private_notes_included !== (bool) data_get($installation->config_json, 'include_private_notes', true)) {
            return false;
        }
        $project = $installation->project_key ?: data_get($installation->config_json, 'project_key', config('kb.ingest.default_project', 'default'));
        $url = 'https://'.UrlPolicy::domain((string) data_get($installation->config_json, 'connection.domain')).'/a/tickets/'.$case->ticket_id;
        $source = $this->source($installation, $case->ticket_id, $lock);

        return $source !== null && $source->fingerprint === $case->source_fingerprint
            && $project === ($case->snapshot['projectKey'] ?? null) && $url === ($case->snapshot['sourceUrl'] ?? null);
    }

    /** @param array<string,mixed> $metadata */
    public function validMetadata(array $metadata, bool $lock = false): ?CaseStudy
    {
        $query = $this->cases()->whereKey($metadata['freshdesk_case_id'] ?? 0);
        $case = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($case === null || $case->status !== 'ready' || ($metadata['installation_id'] ?? null) !== $case->installation_id
            || ($metadata['case_source_fingerprint'] ?? null) !== $case->source_fingerprint
            || ($metadata['case_result_hash'] ?? null) !== hash('sha256', json_encode($case->result, JSON_THROW_ON_ERROR))
            || ! $this->current($case, $lock)) {
            return null;
        }

        return $case;
    }

    public function cancel(ConnectorInstallation $installation, string $ticketId): void
    {
        $this->assertTenant($installation);
        $this->cases()->where('installation_id', $installation->id)->where('ticket_id', $ticketId)
            ->update(['status' => 'cancelled', 'snapshot' => '[]', 'checkpoint' => '[]', 'result' => null, 'error' => null]);
        $this->removeDocument($installation, $ticketId);
    }

    private function cancelCase(CaseStudy $case): void
    {
        $case->forceFill(['status' => 'cancelled', 'snapshot' => [], 'checkpoint' => [], 'result' => null])->save();
        $installation = ConnectorInstallation::query()->where('tenant_id', $case->tenant_id)->find($case->installation_id);
        if ($installation !== null) {
            $this->removeDocument($installation, $case->ticket_id);
        }
    }

    /** @param array<string,mixed> $checkpoint */
    private function saveCheckpoint(CaseStudy $case, array $checkpoint): void
    {
        $this->cases()->whereKey($case->id)->where('source_fingerprint', $case->source_fingerprint)->where('status', 'running')
            ->update(['status' => 'queued', 'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR)]);
    }

    private function publish(CaseStudy $case, CaseStudyInput $input, CaseStudyResult $result): void
    {
        $installation = ConnectorInstallation::query()->where('tenant_id', $case->tenant_id)->findOrFail($case->installation_id);
        $source = $this->source($installation, $case->ticket_id);
        $relative = preg_replace('~/ticket/[0-9]+\.md$~D', '/case_study/'.$case->ticket_id.'.md', (string) $source?->relative_path);
        if ($source === null || $relative === null || $relative === $source->relative_path) {
            throw new \RuntimeException('Missing Freshdesk ticket source path.');
        }
        $paths = $this->ingestion->resolveKbSourcePath($relative);
        $body = $result->markdown($input);
        if (! Storage::disk($paths['disk'])->put($paths['absolute'], $body)) {
            throw new \RuntimeException('Unable to write the local Freshdesk case sheet.');
        }
        $metadata = ['connector' => 'freshdesk', 'installation_id' => $case->installation_id,
            'freshdesk_remote_id' => $case->installation_id.':case_study:'.$case->ticket_id, 'source_kind' => 'case_study',
            'source_url' => $input->sourceUrl, 'ticket_id' => $case->ticket_id, 'freshdesk_case_id' => $case->id,
            'case_source_fingerprint' => $case->source_fingerprint, 'case_result_hash' => hash('sha256', json_encode($case->result, JSON_THROW_ON_ERROR)),
            'original_source_path' => $source->relative_path];
        $this->ingestion->dispatchIngestion($input->projectKey, $paths['relative'], $paths['disk'], 'Caso Freshdesk #'.$case->ticket_id.' — '.$input->title, $metadata, CaseStudyResult::MIME, $case->tenant_id);
        SourceState::query()->updateOrCreate(['tenant_id' => $case->tenant_id, 'installation_id' => $case->installation_id, 'kind' => 'case_study', 'remote_id' => $case->ticket_id],
            ['parent_id' => $case->ticket_id, 'fingerprint' => hash('sha256', $body), 'relative_path' => $relative, 'last_seen_run_id' => $source->last_seen_run_id]);
        $this->ingestion->emitAudit('freshdesk', 'case_study_generated', $installation->id, ['ticket_id' => $case->ticket_id, 'version' => 1, 'usage' => $result->usage]);
    }

    private function removeDocument(ConnectorInstallation $installation, string $ticketId): void
    {
        $query = SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id)->where('kind', 'case_study')->where('remote_id', $ticketId);
        if ($query->exists()) {
            $this->ingestion->softDeleteByRemoteId($installation, 'freshdesk_remote_id', $installation->id.':case_study:'.$ticketId);
            $query->delete();
        }
    }

    /** @return array<string,mixed> */
    private function snapshotSource(string $key, ?string $conversationId, string $text, mixed $date): array
    {
        $text = $this->ingestion->redactContent($text);

        return ['key' => $key, 'conversation_id' => $conversationId, 'created_at' => $date, 'text' => $text, 'sha256' => hash('sha256', $text)];
    }

    /** @return Builder<CaseStudy> */
    private function cases(): Builder
    {
        return CaseStudy::query()->where('tenant_id', $this->tenants->current());
    }

    private function source(ConnectorInstallation $installation, string $ticketId, bool $lock = false): ?SourceState
    {
        $query = SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id)->where('kind', 'ticket')->where('remote_id', $ticketId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function assertTenant(ConnectorInstallation $installation): void
    {
        if ($installation->tenant_id !== $this->tenants->current()) {
            throw new \RuntimeException('Freshdesk case tenant mismatch.');
        }
    }
}
