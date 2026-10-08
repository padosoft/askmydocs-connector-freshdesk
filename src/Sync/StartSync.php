<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Sync;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/** Entry point for schedulers: scheduling is separate from import completion. */
final class StartSync implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $installationId, public readonly string $tenantId) {}

    /** @return list<object|string> */
    public function middleware(): array
    {
        return config('connector-freshdesk.sync.middleware', []);
    }

    public function handle(SyncManager $manager, TenantContext $tenants): void
    {
        $previous = $tenants->current();
        $tenants->set($this->tenantId);
        try {
            $installation = $manager->installation($this->installationId);
            if ($installation->status === 'active') {
                $manager->start($installation);
            }
        } finally {
            $tenants->set($previous);
        }
    }
}
