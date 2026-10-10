<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\IngestionDisabled;
use Padosoft\AskMyDocsConnectorFreshdesk\FreshdeskConnector;
use Padosoft\AskMyDocsConnectorFreshdesk\Support\Features;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\ProcessSyncBatch;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\StartSync;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncRun;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;
use Padosoft\AskMyDocsConnectorFreshdesk\Tools\FreshdeskTools;

final class FeatureSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_schema_exposes_independent_boolean_preferences_with_compatible_defaults(): void
    {
        $schema = collect(app(FreshdeskConnector::class)->connectionSettingsSchema())->keyBy('name');
        foreach (['ingestion.enabled', 'chat_tools.enabled', 'case_studies.enabled'] as $name) {
            $this->assertSame('checkbox', $schema[$name]['type']);
            $this->assertSame('config', $schema[$name]['target']);
            $this->assertTrue($schema[$name]['default']);
        }
        $installation = $this->installation();
        $this->assertTrue(Features::ingestion($installation));
        $this->assertTrue(Features::live($installation));
        $this->assertFalse(Features::caseStudies($installation));
        config(['connector-freshdesk.case_studies.enabled' => true]);
        $this->assertTrue(Features::caseStudies($installation));
        config(['connector-freshdesk.chat_tools.enabled' => false, 'connector-freshdesk.case_studies.enabled' => false]);
        $installation->update(['config_json' => ['chat_tools' => ['enabled' => true], 'case_studies' => ['enabled' => true]]]);
        $this->assertFalse(Features::live($installation));
        $this->assertFalse(Features::caseStudies($installation));
    }

    public function test_live_switch_is_per_installation_and_independent_of_ingestion(): void
    {
        $off = $this->installation(['chat_tools' => ['enabled' => false]]);
        $liveOnly = $this->installation(['ingestion' => ['enabled' => false]]);
        app(OAuthCredentialVault::class)->setCredentials($liveOnly->id, accessToken: 'test-key');
        $tools = app(FreshdeskTools::class);
        $catalog = $tools->catalog('support');
        $this->assertCount(9, $catalog);
        $this->assertSame([$liveOnly->id], array_values(array_unique(array_column($catalog, 'installation_id'))));
        Http::fake(fn () => Http::response(['id' => 1, 'subject' => 'Live']));
        $this->assertArrayHasKey('data', $tools->execute('freshdesk_'.$liveOnly->id.'_get_ticket', ['id' => 1], 'support'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->assertSame(0, SyncRun::count());
        $this->assertTrue(Features::ingestion($off));
    }

    public function test_cached_live_tool_cannot_execute_after_deactivation(): void
    {
        $installation = $this->installation();
        $name = collect(app(FreshdeskTools::class)->catalog('support'))->firstWhere('action', 'get_ticket')['name'];
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['chat_tools' => ['enabled' => false]])]);
        try {
            app(FreshdeskTools::class)->execute($name, ['id' => 1], 'support');
            $this->fail('A disabled live tool executed.');
        } catch (\RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_disabling_live_during_a_retry_blocks_the_next_physical_request(): void
    {
        $installation = $this->installation();
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'test-key');
        Http::fake(function () use ($installation) {
            $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['chat_tools' => ['enabled' => false]])]);

            return Http::response([], 503);
        });
        $result = app(FreshdeskTools::class)->execute('freshdesk_'.$installation->id.'_get_ticket', ['id' => 1], 'support');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(1, $result['physical_request_count']);
        Http::assertSentCount(1);
    }

    public function test_disabled_ingestion_rejects_manual_imports_and_skips_scheduler_entries(): void
    {
        $installation = $this->installation(['ingestion' => ['enabled' => false]]);
        $manager = app(SyncManager::class);
        foreach ([false, true] as $history) {
            try {
                $manager->start($installation, $history);
                $this->fail('Disabled ingestion started.');
            } catch (IngestionDisabled) {
                $this->assertSame(0, SyncRun::count());
            }
        }
        (new StartSync($installation->id, 'tenant-a'))->handle($manager, app(TenantContext::class));
        app(FreshdeskConnector::class)->syncFull($installation->id);
        $this->assertNull($installation->fresh()->last_sync_at);
        Queue::assertNotPushed(ProcessSyncBatch::class);
        Http::assertNothingSent();
    }

    public function test_queued_import_pauses_without_http_and_resumes_the_same_checkpoint(): void
    {
        $installation = $this->installation();
        $manager = app(SyncManager::class);
        $run = $manager->start($installation, history: true);
        $checkpoint = $run->checkpoint;
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => false]])]);
        $this->assertNull($manager->batch($run->id));
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $this->assertNull($installation->fresh()->last_sync_at);
        Http::assertNothingSent();
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => true]])]);
        $resumed = $manager->start($installation);
        $this->assertSame($run->id, $resumed->id);
        $this->assertSame('history', $resumed->mode);
        $this->assertSame($checkpoint, $resumed->checkpoint);
        Queue::assertPushed(ProcessSyncBatch::class, 2);
        $manager->cancel($installation);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_disabling_ingestion_mid_batch_stops_requests_and_keeps_pending_items(): void
    {
        $installation = $this->installation();
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'test-key');
        $manager = app(SyncManager::class);
        $run = $manager->start($installation);
        $first = true;
        Http::fake(function ($request) use ($installation, &$first) {
            if ($first) {
                $first = false;
                $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => false]])]);

                return Http::response([['id' => 1, 'updated_at' => now()->subDay()->toIso8601ZuluString()]]);
            }

            return Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/tickets/1') ? ['id' => 1, 'subject' => 'Original', 'description_text' => 'Original problem'] : []);
        });
        $this->assertNull($manager->batch($run->id));
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->checkpoint['pending'][0]['id']);
        $this->assertNull($installation->fresh()->last_sync_at);
        $this->assertCount(0, $this->ingestion->documents);
        Http::assertSentCount(1);
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => true]])]);
        $this->assertSame($run->id, $manager->start($installation)->id);
        $this->assertNull($manager->batch($run->id));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertCount(1, $this->ingestion->documents);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
