<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

final class GenerateCaseStudy implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $caseId, public readonly string $tenantId, public readonly string $fingerprint)
    {
        $this->onQueue((string) config('connector-freshdesk.sync.queue', 'freshdesk'));
        $this->onConnection(config('connector-freshdesk.sync.connection'));
    }

    /** @return list<object|string> */
    public function middleware(): array
    {
        return config('connector-freshdesk.sync.middleware', []);
    }

    public function handle(CaseStudyManager $manager, TenantContext $tenants): void
    {
        $prior = $tenants->current();
        $tenants->set($this->tenantId);
        try {
            $delay = $manager->batch($this->caseId, $this->fingerprint);
            if ($delay !== null) {
                self::dispatch($this->caseId, $this->tenantId, $this->fingerprint)->delay($delay);
            }
        } finally {
            $tenants->set($prior);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CaseStudy::query()->where('tenant_id', $this->tenantId)->whereKey($this->caseId)
            ->where('source_fingerprint', $this->fingerprint)->whereIn('status', ['queued', 'running', 'failed'])
            ->update(['status' => 'failed', 'error' => 'Case generation stopped; resume on the next ticket sync.']);
    }
}
