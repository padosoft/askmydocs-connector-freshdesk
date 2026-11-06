<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudy;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyGeneratorContract;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyInput;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyManager;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyResult;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\GenerateCaseStudy;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\DocumentImporter;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SourceState;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncManager;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncRun;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;

final class CaseStudyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['connector-freshdesk.case_studies.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            ['id' => 11, 'body_text' => 'Abbiamo riavviato il servizio. Il cliente conferma che funziona.', 'created_at' => '2026-10-09T10:00:00Z'],
            ['id' => 12, 'body_text' => 'Nota privata sensitive@example.com', 'private' => true],
        ]));
    }

    private function import(array $config = [], ?array $ticket = null): array
    {
        $installation = $this->installation($config);
        $run = SyncRun::create(['tenant_id' => 'tenant-a', 'installation_id' => $installation->id, 'mode' => 'incremental', 'status' => 'running', 'scan_started_at' => now(), 'checkpoint' => [], 'counts' => []]);
        $ticket ??= ['id' => 1, 'subject' => 'Servizio bloccato', 'description_text' => 'Il servizio non risponde. sensitive@example.com'];
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);

        return [$installation, $run, $ticket, CaseStudy::first()];
    }

    private function candidate(): array
    {
        return ['field' => 'intervention', 'text' => 'Riavvio del servizio.', 'evidence' => [['source_key' => 'conversation:11', 'quote' => 'Abbiamo riavviato il servizio.']]];
    }

    private function sheetResult(): CaseStudyResult
    {
        $fields = array_fill_keys(CaseStudyResult::FIELDS, ['status' => 'not_documented', 'text' => '', 'evidence' => []]);
        $fields['intervention'] = ['status' => 'documented', 'text' => $this->candidate()['text'], 'evidence' => $this->candidate()['evidence']];

        return new CaseStudyResult($fields);
    }

    private function generator(): object
    {
        $generator = Mockery::mock(CaseStudyGeneratorContract::class);
        $generator->shouldReceive('extract')->andReturnUsing(fn ($input, $sources) => $sources[0]['key'] === 'conversation:11' ? [$this->candidate()] : []);
        $generator->shouldReceive('compile')->andReturn($this->sheetResult());
        $this->app->instance(CaseStudyGeneratorContract::class, $generator);

        return $generator;
    }

    private function drain(CaseStudy $case): void
    {
        for ($i = 0; $i < 10; $i++) {
            if (app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint) === null) {
                return;
            }
        }
        $this->fail('Case generation did not complete.');
    }

    public function test_case_is_local_separate_redacted_and_linked_to_original_conversations(): void
    {
        [, , , $case] = $this->import();
        Queue::assertPushed(GenerateCaseStudy::class);
        $this->assertCount(1, $this->ingestion->documents);
        $this->assertStringNotContainsString('sensitive@example.com', json_encode($case->snapshot));
        $this->generator();
        $this->drain($case);
        $this->assertSame('ready', $case->fresh()->status);
        $this->assertCount(2, $this->ingestion->documents);
        $document = $this->ingestion->documents[1];
        $this->assertSame(CaseStudyResult::MIME, $document['mimeType']);
        $this->assertNotNull(app(CaseStudyManager::class)->validMetadata($document['metadata']));
        $markdown = Storage::disk('local')->get($document['relativePath']);
        $this->assertStringContainsString('**Causa:** Non documentato', $markdown);
        $this->assertStringContainsString('conversazione #11', $markdown);
        $this->assertStringContainsString('> Abbiamo riavviato il servizio.', $markdown);
        $this->assertStringContainsString('https://example.freshdesk.com/a/tickets/1', $markdown);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_unchanged_existing_tickets_do_not_trigger_backlog_generation(): void
    {
        config(['connector-freshdesk.case_studies.enabled' => false]);
        [$installation, $run, $ticket] = $this->import();
        config(['connector-freshdesk.case_studies.enabled' => true]);
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);
        $this->assertSame(0, CaseStudy::count());
        Queue::assertNotPushed(GenerateCaseStudy::class);
    }

    public function test_repeated_ticket_reads_do_not_reset_or_duplicate_pending_case_jobs(): void
    {
        [$installation, $run, $ticket, $case] = $this->import();
        $this->generator();
        app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint);
        $checkpoint = $case->fresh()->checkpoint;
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);
        Queue::assertPushed(GenerateCaseStudy::class, 1);
        $this->assertSame($checkpoint, $case->fresh()->checkpoint);
    }

    public function test_private_notes_are_absent_from_the_generation_snapshot_when_excluded(): void
    {
        [, , , $case] = $this->import(['include_private_notes' => false]);
        $this->assertCount(2, $case->snapshot['sources']);
        $this->assertStringNotContainsString('Nota privata', json_encode($case->snapshot));
    }

    public function test_changed_ticket_invalidates_the_old_case_and_stale_jobs_cannot_publish(): void
    {
        [$installation, $run, $ticket, $case] = $this->import();
        $this->generator();
        $this->drain($case);
        $oldMetadata = $this->ingestion->documents[1]['metadata'];
        $oldFingerprint = $case->source_fingerprint;
        $ticket['description_text'] .= ' Nuovo sintomo.';
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);
        $this->assertNull(app(CaseStudyManager::class)->validMetadata($oldMetadata));
        $this->assertSame('queued', $case->fresh()->status);
        $this->assertNull(app(CaseStudyManager::class)->batch($case->id, $oldFingerprint));
        $this->assertCount(3, $this->ingestion->documents);
        $this->assertCount(1, $this->ingestion->deleted);
    }

    public function test_removal_cancels_pending_work_and_purges_its_snapshot(): void
    {
        [$installation, $run, , $case] = $this->import();
        app(DocumentImporter::class)->delete($installation, $run, 'ticket', '1');
        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertSame([], $case->fresh()->snapshot);
        $this->assertNull(app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint));
        $this->assertSame(0, SourceState::count());
        $this->assertCount(1, $this->ingestion->documents);
    }

    public function test_tenant_and_private_policy_are_revalidated_before_generation(): void
    {
        [$installation, , , $case] = $this->import();
        app(TenantContext::class)->set('other-tenant');
        $this->assertNull(app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint));
        $this->assertSame('queued', $case->fresh()->status);
        app(TenantContext::class)->set('tenant-a');
        $installation->update(['config_json' => array_replace($installation->config_json, ['include_private_notes' => false])]);
        $this->assertNull(app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint));
        $this->assertSame('cancelled', $case->fresh()->status);
    }

    public function test_invented_quotes_fail_without_interrupting_raw_ingestion(): void
    {
        [, , , $case] = $this->import();
        $generator = Mockery::mock(CaseStudyGeneratorContract::class);
        $candidate = $this->candidate();
        $candidate['evidence'][0]['quote'] = 'È stata confermata una causa mai descritta.';
        $generator->shouldReceive('extract')->once()->andReturn([$candidate]);
        $this->app->instance(CaseStudyGeneratorContract::class, $generator);
        try {
            app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint);
            $this->fail('Invented evidence accepted.');
        } catch (\RuntimeException) {
            $this->assertSame('failed', $case->fresh()->status);
            $this->assertCount(1, $this->ingestion->documents);
        }
    }

    public function test_retry_resumes_after_completed_extraction_segments(): void
    {
        [$installation, $run, $ticket, $case] = $this->import();
        $generator = Mockery::mock(CaseStudyGeneratorContract::class);
        $generator->shouldReceive('extract')->withArgs(fn ($input, $sources) => $sources[0]['key'] === 'description')->once()->andReturn([]);
        $generator->shouldReceive('extract')->withArgs(fn ($input, $sources) => $sources[0]['key'] === 'conversation:11')->once()->andThrow(new \RuntimeException('unavailable'));
        $this->app->instance(CaseStudyGeneratorContract::class, $generator);
        app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint);
        try {
            app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint);
        } catch (\RuntimeException) {
            $this->assertSame(1, $case->fresh()->checkpoint['next_segment']);
        }
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);
        $resumed = $this->generator();
        $resumed->shouldNotReceive('extract')->withArgs(fn ($input, $sources) => $sources[0]['key'] === 'description');
        $this->drain($case);
        $this->assertSame('ready', $case->fresh()->status);
    }

    public function test_long_utf8_sources_are_completely_segmented(): void
    {
        $text = str_repeat('È documentato. ', 2500);
        $input = new CaseStudyInput('tenant-a', 1, '1', 'support', 'Title', 'https://example.freshdesk.com/a/tickets/1', [['key' => 'description', 'text' => $text]]);
        $parts = array_map(fn ($segment) => $segment[0]['text'], $input->segments());
        $this->assertSame($text, implode('', $parts));
        foreach ($parts as $part) {
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            $this->assertLessThanOrEqual(10000, strlen($part));
        }
    }

    public function test_account_can_disable_case_sheets_while_raw_ingestion_continues(): void
    {
        $this->import(['case_studies' => ['enabled' => false]]);
        $this->assertSame(0, CaseStudy::count());
        $this->assertCount(1, $this->ingestion->documents);
        Queue::assertNotPushed(GenerateCaseStudy::class);
    }

    public function test_case_deactivation_pauses_paid_work_and_resumes_without_repeating_segments(): void
    {
        [$installation, , , $case] = $this->import();
        $generator = Mockery::mock(CaseStudyGeneratorContract::class);
        $generator->shouldReceive('extract')->once()->andReturn([]);
        $this->app->instance(CaseStudyGeneratorContract::class, $generator);
        app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint);
        $checkpoint = $case->fresh()->checkpoint;
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => false]])]);
        $this->assertNull(app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint));
        $this->assertSame('paused', $case->fresh()->status);
        $this->assertSame($checkpoint, $case->fresh()->checkpoint);
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => true]])]);
        app(CaseStudyManager::class)->resume($installation);
        app(CaseStudyManager::class)->resume($installation);
        Queue::assertPushed(GenerateCaseStudy::class, 2);
        $resumed = $this->generator();
        $resumed->shouldNotReceive('extract')->withArgs(fn ($input, $sources) => $sources[0]['key'] === 'description');
        $this->drain($case);
        $this->assertSame('ready', $case->fresh()->status);
    }

    public function test_case_switch_blocks_and_restores_existing_sheets_without_deleting_them(): void
    {
        [$installation, , , $case] = $this->import();
        $this->generator();
        $this->drain($case);
        $metadata = $this->ingestion->documents[1]['metadata'];
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => false]])]);
        $this->assertNotNull(app(CaseStudyManager::class)->validMetadata($metadata));
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => false]])]);
        $this->assertNull(app(CaseStudyManager::class)->validMetadata($metadata));
        $this->assertSame('ready', $case->fresh()->status);
        $this->assertSame(2, SourceState::count());
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => true]])]);
        $this->assertNotNull(app(CaseStudyManager::class)->validMetadata($metadata));
    }

    public function test_disabling_ingestion_during_extraction_keeps_the_completed_segment_and_pauses(): void
    {
        [$installation, , , $case] = $this->import();
        $generator = Mockery::mock(CaseStudyGeneratorContract::class);
        $generator->shouldReceive('extract')->once()->andReturnUsing(function () use ($installation) {
            $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => false]])]);

            return [];
        });
        $this->app->instance(CaseStudyGeneratorContract::class, $generator);
        $this->assertNull(app(CaseStudyManager::class)->batch($case->id, $case->source_fingerprint));
        $this->assertSame('paused', $case->fresh()->status);
        $this->assertSame(1, $case->fresh()->checkpoint['next_segment']);
        $this->assertCount(1, $this->ingestion->documents);
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['ingestion' => ['enabled' => true]])]);
        app(SyncManager::class)->start($installation);
        $this->assertSame('queued', $case->fresh()->status);
        Queue::assertPushed(GenerateCaseStudy::class, 2);
        Http::assertSentCount(1);
    }

    public function test_case_changed_while_disabled_is_regenerated_from_the_current_original(): void
    {
        [$installation, $run, $ticket, $case] = $this->import();
        $this->generator();
        $this->drain($case);
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => false]])]);
        $ticket['description_text'] = 'Descrizione aggiornata e documentata.';
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => true]])]);
        app(DocumentImporter::class)->ticket($installation, $run, new FreshdeskClient('example.freshdesk.com', 'test-key'), $ticket);
        $this->assertSame('queued', $case->fresh()->status);
        $this->assertSame($ticket['description_text'], $case->fresh()->snapshot['sources'][0]['text']);
        Queue::assertPushed(GenerateCaseStudy::class, 2);
    }
}
