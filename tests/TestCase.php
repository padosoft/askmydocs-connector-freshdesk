<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\FreshdeskServiceProvider;

abstract class TestCase extends Orchestra
{
    public FakeIngestion $ingestion;

    protected function getPackageProviders($app): array
    {
        return [ConnectorServiceProvider::class, FreshdeskServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('connector-freshdesk.http.resolve_dns', false);
        $app['config']->set('connector-freshdesk.sync.batch_items', 100);
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        app(TenantContext::class)->set('tenant-a');
        $this->ingestion = new FakeIngestion;
        $this->app->instance(ConnectorIngestionContract::class, $this->ingestion);
        Storage::fake('local');
        Queue::fake();
    }

    public function installation(array $config = [], string $tenant = 'tenant-a', string $project = 'support'): ConnectorInstallation
    {
        return ConnectorInstallation::query()->create(['tenant_id' => $tenant, 'connector_name' => 'freshdesk', 'label' => 'account-'.uniqid(), 'project_key' => $project, 'status' => 'active', 'config_json' => array_replace_recursive(['connection' => ['domain' => 'example.freshdesk.com']], $config)]);
    }
}
