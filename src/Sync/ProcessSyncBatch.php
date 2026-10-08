<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Sync;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

final class ProcessSyncBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $runId, public readonly string $tenantId)
    {
        $this->onQueue((string) config('connector-freshdesk.sync.queue', 'freshdesk'));
        $this->onConnection(config('connector-freshdesk.sync.connection'));
    }

    /** @return list<object|string> */
    public function middleware(): array
    {
        return config('connector-freshdesk.sync.middleware', []);
    }

    public function handle(SyncManager $manager, TenantContext $tenants): void
    {
        $prior = $tenants->current();
        $tenants->set($this->tenantId);
        try {
            $run = SyncRun::query()->where('tenant_id', $this->tenantId)->whereKey($this->runId)->first();
            if ($run === null) {
                return;
            }
            $delay = $manager->batch($this->runId);
            if ($delay !== null) {
                // Each continuation is a fresh job, rather than exhausting the
                // queue's retry count during a successful long import.
                self::dispatch($this->runId, $this->tenantId)->delay($delay);
            }
        } finally {
            $tenants->set($prior);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        // Queue timeouts can terminate execution before batch() records a failure.
        // Never persist the exception body: transport exceptions may contain URLs.
        $message = 'Freshdesk worker stopped before completing the batch; resume the import.';
        SyncRun::query()->where('tenant_id', $this->tenantId)->whereKey($this->runId)->whereIn('status', ['queued', 'running', 'failed'])
            ->update(['status' => 'failed', 'error' => $message]);
        $run = SyncRun::query()->where('tenant_id', $this->tenantId)->whereKey($this->runId)->where('status', 'failed')->first();
        if ($run !== null) {
            ConnectorInstallation::query()->where('tenant_id', $this->tenantId)->whereKey($run->installation_id)
                ->whereIn('status', ['active', 'errored'])->update(['status' => 'errored', 'error_json' => ['message' => $message]]);
        }
    }
}
