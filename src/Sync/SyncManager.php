<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Sync;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\FreshdeskApiException;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;

final readonly class SyncManager
{
    public function __construct(private OAuthCredentialVault $vault, private TenantContext $tenants, private DocumentImporter $importer, private ConnectorIngestionContract $ingestion) {}

    public function installation(int $id): ConnectorInstallation
    {
        return ConnectorInstallation::query()->where('tenant_id', $this->tenants->current())->where('connector_name', 'freshdesk')->whereKey($id)->firstOrFail();
    }

    public function client(ConnectorInstallation $installation, ?Closure $beforeRequest = null): FreshdeskClient
    {
        if ($installation->tenant_id !== $this->tenants->current() || $installation->connector_name !== 'freshdesk') {
            throw new \RuntimeException('Freshdesk installation scope mismatch.');
        }
        $key = $this->vault->getAccessToken($installation->id);
        if ($key === null) {
            throw new \RuntimeException('Freshdesk API key is missing.');
        }

        return new FreshdeskClient((string) data_get($installation->config_json, 'connection.domain'), $key, $beforeRequest);
    }

    public function start(ConnectorInstallation $installation, bool $history = false): SyncRun
    {
        $installation = $this->installation($installation->id);
        if (! in_array($installation->status, ['active', 'errored'], true)) {
            throw new \RuntimeException('Enable the Freshdesk installation before importing.');
        }
        if (config('queue.default') === 'sync') {
            throw new \RuntimeException('Freshdesk imports require an asynchronous queue connection.');
        }
        $mode = $history ? 'history' : 'window';
        $dispatch = false;
        $run = DB::transaction(function () use ($installation, $mode, $history, &$dispatch): SyncRun {
            ConnectorInstallation::query()->whereKey($installation->id)->where('tenant_id', $installation->tenant_id)->lockForUpdate()->firstOrFail();
            $query = $this->runs($installation);
            $query->whereIn('status', ['queued', 'running', 'failed']);
            $query->latest('id');
            $existing = $query->get()->first(fn ($candidate) => ! $history || $candidate->mode === 'history');
            if ($existing !== null) {
                if ($existing->status === 'failed') {
                    $existing->forceFill(['status' => 'queued', 'error' => null])->save();
                    $dispatch = true;
                }

                return $existing;
            }
            $window = max(1, min(36500, (int) data_get($installation->config_json, 'date_window_days', 90)));
            $since = $history ? Carbon::create(1970, 1, 1, 0, 0, 0, 'UTC') : Carbon::now('UTC')->subDays($window);
            $previous = $this->runs($installation)->where('status', 'completed')->latest('scan_started_at')->first();
            $projection = hash('sha256', json_encode([
                'private' => data_get($installation->config_json, 'include_private_notes', true),
                'attachments' => data_get($installation->config_json, 'attachments', []),
                'ocr' => config('kb.ocr.enabled', false),
            ], JSON_THROW_ON_ERROR));
            $refresh = $previous !== null && ($previous->checkpoint['projection_hash'] ?? null) !== $projection;
            if (! $history && $previous !== null && (int) ($previous->checkpoint['window_days'] ?? 0) >= $window) {
                $since = $since->max(Carbon::parse($previous->scan_started_at)->subSeconds((int) config('connector-freshdesk.sync.overlap_seconds', 120)));
            }

            $dispatch = true;

            return SyncRun::query()->create([
                'tenant_id' => $installation->tenant_id, 'installation_id' => $installation->id, 'mode' => $mode,
                'status' => 'queued', 'scan_started_at' => now(), 'counts' => [],
                'checkpoint' => ['phase' => 'tickets', 'since' => $since->toIso8601ZuluString(), 'initial_since' => $since->toIso8601ZuluString(), 'page' => 1, 'pending' => [], 'window_days' => $history ? 36500 : $window, 'projection_hash' => $projection, 'refresh_tickets' => $refresh, 'refresh_cursor' => 0],
            ]);
        });
        if ($dispatch) {
            ProcessSyncBatch::dispatch($run->id, $installation->tenant_id);
        }

        return $run;
    }

    public function latest(ConnectorInstallation $installation): ?SyncRun
    {
        $installation = $this->installation($installation->id);

        return $this->runs($installation)->latest('id')->first();
    }

    public function cancel(ConnectorInstallation $installation): void
    {
        $this->runs($this->installation($installation->id))->whereIn('status', ['queued', 'running', 'failed'])->update(['status' => 'cancelled']);
    }

    /** Returns seconds before the next batch; null means finished. */
    public function batch(int $runId): ?int
    {
        $run = SyncRun::query()->where('tenant_id', $this->tenants->current())->whereKey($runId)->firstOrFail();
        $lock = Cache::lock('freshdesk-sync:'.$run->tenant_id.':'.$run->installation_id, 660);
        if (! $lock->get()) {
            return 30;
        }
        try {
            $run->refresh();
            if (in_array($run->status, ['completed', 'cancelled'], true)) {
                return null;
            }
            $installation = $this->installation($run->installation_id);
            if (! in_array($installation->status, ['active', 'errored'], true)) {
                $run->forceFill(['status' => 'cancelled'])->save();

                return null;
            }
            $run->forceFill(['status' => 'running', 'error' => null])->save();
            $client = $this->client($installation);
            $started = microtime(true);
            $items = max(1, min(100, (int) config('connector-freshdesk.sync.batch_items', 5)));
            for ($i = 0; $i < $items && microtime(true) - $started < (int) config('connector-freshdesk.sync.batch_seconds', 60); $i++) {
                if ($run->fresh()->status === 'cancelled' || ! in_array($installation->fresh()->status, ['active', 'errored'], true)) {
                    $run->forceFill(['status' => 'cancelled'])->save();

                    return null;
                }
                if ($this->step($installation, $run, $client)) {
                    $run->forceFill(['status' => 'completed', 'finished_at' => now(), 'error' => null])->save();
                    $lastSync = $installation->fresh()->last_sync_at;
                    ConnectorInstallation::query()->where('tenant_id', $installation->tenant_id)->whereKey($installation->id)
                        ->whereIn('status', ['active', 'errored'])->update([
                            'last_sync_at' => $lastSync === null ? $run->scan_started_at : Carbon::parse($lastSync)->max($run->scan_started_at),
                            'error_json' => null, 'status' => 'active',
                        ]);
                    $this->ingestion->emitAudit('freshdesk', 'sync_completed', $installation->id, $run->summary());

                    return null;
                }
                $run->save();
            }
            $run->forceFill(['status' => 'queued'])->save();

            return 1;
        } catch (FreshdeskApiException $exception) {
            $run->forceFill(['status' => $exception->status === 429 ? 'queued' : 'failed', 'error' => $exception->getMessage()])->save();
            if ($exception->status === 429) {
                return $exception->retryAfter;
            }
            $this->recordFailure($run);
            throw $exception;
        } catch (\Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();
            $this->recordFailure($run);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function recordFailure(SyncRun $run): void
    {
        $installation = $this->installation($run->installation_id);
        if (in_array($installation->status, ['active', 'errored'], true)) {
            $installation->forceFill(['status' => 'errored', 'error_json' => ['message' => $run->error, 'recorded_at' => now()->toIso8601String()]])->save();
        }
    }

    private function step(ConnectorInstallation $installation, SyncRun $run, FreshdeskClient $client): bool
    {
        $cp = $run->checkpoint;
        if ($cp['refresh_tickets'] ?? false) {
            $state = SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id)
                ->where('kind', 'ticket')->where('id', '>', $cp['refresh_cursor'])->orderBy('id')->first();
            if ($state === null) {
                $cp['refresh_tickets'] = false;
            } else {
                $ticket = $client->get('/tickets/'.(int) $state->remote_id);
                if ($ticket['deleted'] ?? false) {
                    $this->importer->delete($installation, $run, 'ticket', (string) $ticket['id']);
                } else {
                    $this->importer->ticket($installation, $run, $client, $ticket);
                }
                $cp['refresh_cursor'] = $state->id;
            }
            $run->checkpoint = $cp;

            return false;
        }
        $phase = $cp['phase'];
        if (in_array($phase, ['tickets', 'deleted'], true)) {
            if ($cp['pending'] !== []) {
                $ticket = $cp['pending'][0];
                if ($phase === 'deleted') {
                    $this->importer->delete($installation, $run, 'ticket', (string) $ticket['id']);
                } else {
                    try {
                        $detail = $client->get('/tickets/'.(int) $ticket['id']);
                        if ($detail['deleted'] ?? false) {
                            $this->importer->delete($installation, $run, 'ticket', (string) $ticket['id']);
                        } else {
                            $this->importer->ticket($installation, $run, $client, $detail);
                        }
                    } catch (FreshdeskApiException $exception) {
                        // 404 can mean archived/inaccessible: it is not deletion evidence.
                        throw $exception;
                    }
                }
                array_shift($cp['pending']);
                $run->checkpoint = $cp;

                return false;
            }
            if ($cp['end_after_pending'] ?? false) {
                $next = $phase === 'tickets'
                    ? ['phase' => 'deleted', 'since' => $run->checkpoint['initial_since'] ?? $run->checkpoint['since'], 'page' => 1, 'pending' => [], 'window_days' => $cp['window_days']]
                    : ['phase' => 'categories', 'categories' => [], 'page' => 1, 'window_days' => $cp['window_days']];
                $cp = $next + ['projection_hash' => $cp['projection_hash'] ?? null];
                $run->checkpoint = $cp;

                return false;
            }
            $query = ['updated_since' => $cp['since'], 'order_by' => 'updated_at', 'order_type' => 'asc', 'per_page' => 100, 'page' => $cp['page']];
            if ($phase === 'deleted') {
                $query['filter'] = 'deleted';
            }
            $rows = $client->get('/tickets', $query);
            $pending = [];
            foreach ($rows as $row) {
                if (! isset($row['id'], $row['updated_at'])) {
                    throw new \RuntimeException('Freshdesk ticket list is missing identity or update timestamp.');
                }
                if (Carbon::parse($row['updated_at'])->greaterThan($run->scan_started_at)) {
                    break;
                }
                $pending[] = ['id' => (int) $row['id'], 'updated_at' => $row['updated_at']];
                $cp['last_seen'] = $row['updated_at'];
            }
            $cp['pending'] = $pending;
            $ended = count($rows) < 100 || count($pending) < count($rows);
            if ($ended) {
                // Consume this last page before changing phase.
                $cp['end_after_pending'] = true;
            } elseif ($cp['page'] >= 300) {
                $next = Carbon::parse($cp['last_seen'])->subSecond();
                if (! $next->greaterThan(Carbon::parse($cp['since']))) {
                    throw new \RuntimeException('Freshdesk pagination cannot advance at the timestamp boundary; import remains incomplete.');
                }
                $cp['since'] = $next->toIso8601ZuluString();
                $cp['page'] = 1;
            } else {
                $cp['page']++;
            }
            // Transition on the following step after the final page is imported.
            $run->checkpoint = $cp;

            return false;
        }
        if ($phase === 'categories') {
            $rows = $client->get('/solutions/categories', ['page' => $cp['page'], 'per_page' => 100]);
            $cp['categories'] = array_merge($cp['categories'], array_column($rows, 'id'));
            if (count($rows) < 100) {
                $cp['phase'] = 'folders';
                $cp['page'] = 1;
                $cp['folders'] = [];
            } else {
                if ($cp['page'] >= 300) {
                    throw new \RuntimeException('Freshdesk category pagination limit exceeded.');
                }
                $cp['page']++;
            }
            $run->checkpoint = $cp;

            return false;
        }
        if ($phase === 'folders') {
            if ($cp['categories'] === []) {
                $cp['phase'] = 'subfolders';
                $cp['folder_queue'] = $cp['folders'];
                $cp['visited'] = [];
                $cp['page'] = 1;
                $cp['pending'] = [];
            } else {
                $rows = $client->get('/solutions/categories/'.(int) $cp['categories'][0].'/folders', ['page' => $cp['page'], 'per_page' => 100]);
                $cp['folders'] = array_values(array_unique(array_merge($cp['folders'], array_column($rows, 'id'))));
                if (count($rows) < 100) {
                    array_shift($cp['categories']);
                    $cp['page'] = 1;
                } else {
                    if ($cp['page'] >= 300) {
                        throw new \RuntimeException('Freshdesk folder pagination limit exceeded.');
                    } $cp['page']++;
                }
            }
            $run->checkpoint = $cp;

            return false;
        }
        if ($phase === 'subfolders') {
            if ($cp['folder_queue'] === []) {
                $cp['phase'] = 'articles';
                $cp['pending'] = [];
                $cp['page'] = 1;
            } else {
                $folder = (int) $cp['folder_queue'][0];
                $rows = $client->get('/solutions/folders/'.$folder.'/subfolders', ['page' => $cp['page'], 'per_page' => 100]);
                foreach (array_column($rows, 'id') as $child) {
                    if (! in_array($child, $cp['folders'], true)) {
                        $cp['folders'][] = $child;
                        $cp['folder_queue'][] = $child;
                    }
                }
                if (count($rows) < 100) {
                    array_shift($cp['folder_queue']);
                    $cp['page'] = 1;
                } else {
                    if ($cp['page'] >= 300) {
                        throw new \RuntimeException('Freshdesk subfolder pagination limit exceeded.');
                    } $cp['page']++;
                }
            }
            $run->checkpoint = $cp;

            return false;
        }
        if ($phase === 'articles') {
            if ($cp['pending'] !== []) {
                $article = $client->get('/solutions/articles/'.(int) $cp['pending'][0]);
                $this->importer->article($installation, $run, $article);
                array_shift($cp['pending']);
            } elseif ($cp['folders'] === []) {
                $cp['phase'] = 'article_check';
                $cp['article_cursor'] = 0;
            } else {
                $rows = $client->get('/solutions/folders/'.(int) $cp['folders'][0].'/articles', ['page' => $cp['page'], 'per_page' => 100]);
                $cp['pending'] = array_column($rows, 'id');
                if (count($rows) < 100) {
                    array_shift($cp['folders']);
                    $cp['page'] = 1;
                } else {
                    if ($cp['page'] >= 300) {
                        throw new \RuntimeException('Freshdesk article pagination limit exceeded.');
                    } $cp['page']++;
                }
            }
            $run->checkpoint = $cp;

            return false;
        }
        if ($phase === 'article_check') {
            $state = SourceState::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id)
                ->where('kind', 'article')->where('id', '>', $cp['article_cursor'])->where('last_seen_run_id', '!=', $run->id)->orderBy('id')->first();
            if ($state === null) {
                return true;
            }
            try {
                $article = $client->get('/solutions/articles/'.(int) $state->remote_id);
                $this->importer->article($installation, $run, $article);
            } catch (FreshdeskApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
                // Only a detail 404 confirms deletion; list absence alone does not.
                $this->importer->delete($installation, $run, 'article', $state->remote_id);
            }
            $cp['article_cursor'] = $state->id;
            $run->checkpoint = $cp;

            return false;
        }
        throw new \RuntimeException('Unknown Freshdesk sync phase.');
    }

    /** @return Builder<SyncRun> */
    private function runs(ConnectorInstallation $installation): Builder
    {
        return SyncRun::query()->where('tenant_id', $installation->tenant_id)->where('installation_id', $installation->id);
    }
}
