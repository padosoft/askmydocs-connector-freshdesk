<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\InvalidHistoryStartDate;
use Padosoft\AskMyDocsConnectorFreshdesk\FreshdeskConnector;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\ProcessSyncBatch;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncRun;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HistoricalImportDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12T12:00:00Z');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_history_date_reaches_both_ticket_filters_and_preserves_conversations_and_the_ordinary_window(): void
    {
        $installation = $this->installation(['date_window_days' => 45, 'history' => ['start_date' => '2024-01-01']]);
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'test-key');
        Http::fake(function ($request) {
            return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/v2/tickets' => ($request['filter'] ?? null) === 'deleted' ? [] : [['id' => 1, 'updated_at' => '2024-05-01T12:00:00Z']],
                '/api/v2/tickets/1' => ['id' => 1, 'subject' => 'Documented problem', 'description_text' => 'Original issue'],
                '/api/v2/tickets/1/conversations' => [['id' => 11, 'body_text' => 'Documented intervention']],
                '/api/v2/solutions/categories' => [],
                default => throw new \RuntimeException('Unexpected Freshdesk read.'),
            });
        });
        $run = app(SyncManager::class)->start($installation, history: true);
        $this->finish($run);

        $this->assertSame('2024-01-01T00:00:00Z', $run->fresh()->summary()['updated_since']);
        $this->assertSame(45, $installation->fresh()->config_json['date_window_days']);
        $this->assertCount(1, $this->ingestion->documents);
        $this->assertStringContainsString('Documented intervention', Storage::disk('local')->get($this->ingestion->documents[0]['relativePath']));
        Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/api/v2/tickets'
            && ($request['filter'] ?? null) !== 'deleted' && $request['updated_since'] === '2024-01-01T00:00:00Z');
        Http::assertSent(fn ($request) => ($request['filter'] ?? null) === 'deleted' && $request['updated_since'] === '2024-01-01T00:00:00Z');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/conversations?page=1&per_page=100'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_resume_keeps_the_original_period_and_a_new_run_uses_the_new_date(): void
    {
        $installation = $this->installation(['history' => ['start_date' => '2024-01-01']]);
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'test-key');
        $manager = app(SyncManager::class);
        $run = $manager->start($installation, history: true);
        $run->update(['status' => 'paused']);
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['history' => ['start_date' => '2025-01-01']])]);
        $resumed = $manager->start($installation, history: true);
        $this->assertSame($run->id, $resumed->id);
        $this->assertSame('2024-01-01T00:00:00Z', $resumed->summary()['updated_since']);
        Http::fake(fn () => Http::response([]));
        $this->finish($resumed);
        $this->assertSame('2024-01-01T00:00:00Z', $resumed->fresh()->summary()['updated_since']);
        $next = $manager->start($installation, history: true);
        $this->assertNotSame($run->id, $next->id);
        $this->assertSame('2025-01-01T00:00:00Z', $next->summary()['updated_since']);
    }

    public function test_recent_history_does_not_advance_the_ordinary_watermark_past_unscanned_tickets(): void
    {
        $installation = $this->installation(['date_window_days' => 45, 'history' => ['start_date' => '2026-10-11']]);
        app(OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'test-key');
        Http::fake(fn () => Http::response([]));
        $manager = app(SyncManager::class);
        $history = $manager->start($installation, history: true);
        $this->finish($history);
        $ordinary = $manager->start($installation);
        $this->assertSame('2026-08-28T12:00:00Z', $ordinary->checkpoint['initial_since']);
        $this->assertSame(45, $ordinary->checkpoint['window_days']);
    }

    public function test_empty_date_retains_full_history_and_date_policy_does_not_affect_ordinary_sync(): void
    {
        $installation = $this->installation(['history' => ['start_date' => '']]);
        $manager = app(SyncManager::class);
        $this->assertSame('1970-01-01T00:00:00Z', $manager->start($installation, history: true)->checkpoint['initial_since']);
        $other = $this->installation(['history' => ['start_date' => 'invalid']]);
        $this->assertSame('2026-07-14T12:00:00Z', $manager->start($other)->checkpoint['initial_since']);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_do_not_start_jobs_or_send_http(mixed $date): void
    {
        try {
            app(SyncManager::class)->start($this->installation(['history' => ['start_date' => $date]]), history: true);
            $this->fail('Invalid historical date accepted.');
        } catch (InvalidHistoryStartDate) {
            $this->assertSame(0, SyncRun::count());
            Queue::assertNotPushed(ProcessSyncBatch::class);
            Http::assertNothingSent();
        }
    }

    /** @return array<string, array{mixed}> */
    public static function invalidDates(): array
    {
        return ['invalid day' => ['2024-02-30'], 'wrong format' => ['01/01/2024'], 'non-padded' => ['2024-1-1'],
            'timestamp' => ['2024-01-01T00:00:00Z'], 'before epoch' => ['1969-12-31'], 'future' => ['2026-10-13'],
            'boolean' => [false], 'array' => [['2024-01-01']]];
    }

    public function test_schema_exposes_an_optional_bounded_date(): void
    {
        $field = collect(app(FreshdeskConnector::class)->connectionSettingsSchema())->firstWhere('name', 'history.start_date');
        $this->assertSame('date', $field['type']);
        $this->assertFalse($field['required']);
        $this->assertNull($field['default']);
        $this->assertSame('1970-01-01', $field['min']);
        $this->assertSame('2026-10-12', $field['max']);
    }

    private function finish(SyncRun $run): void
    {
        for ($batch = 0; $batch < 10; $batch++) {
            if (app(SyncManager::class)->batch($run->id) === null) {
                $this->assertSame('completed', $run->fresh()->status);

                return;
            }
        }
        $this->fail('Historical import did not complete.');
    }
}
