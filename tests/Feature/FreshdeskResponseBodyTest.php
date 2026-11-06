<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;

final class FreshdeskResponseBodyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('connector-freshdesk.http.resolve_dns', false);
        Http::preventStrayRequests();
    }

    public function test_probe_preserves_a_non_seekable_body_read_by_an_http_observer(): void
    {
        Http::fake(fn () => Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/json'], new NoSeekStream(Utils::streamFor('{"id":42}')))));
        Event::listen(ResponseReceived::class, static function (ResponseReceived $event): void {
            $event->response->body();
        });

        $this->assertSame(['id' => 42], (new FreshdeskClient('example.freshdesk.com', 'test-key'))->get('/agents/me'));
    }

    public function test_limited_reader_rewinds_a_seekable_body_read_by_an_http_observer(): void
    {
        $response = new Response(new PsrResponse(200, [], '{"id":42}'));
        $response->body();

        $this->assertSame('{"id":42}', FreshdeskClient::readLimited($response, 100));
    }

    public function test_attachment_preserves_bytes_read_by_an_http_observer(): void
    {
        Http::fake(fn () => Create::promiseFor(new PsrResponse(200, [], new NoSeekStream(Utils::streamFor('attachment bytes')))));
        Event::listen(ResponseReceived::class, static function (ResponseReceived $event): void {
            $event->response->body();
        });

        $this->assertSame('attachment bytes', (new FreshdeskClient('example.freshdesk.com', 'test-key'))->download('https://cdn.freshdesk.com/file', 100));
    }

    public function test_oversized_non_seekable_body_is_rejected_before_an_observer_reads_it(): void
    {
        config()->set('connector-freshdesk.http.max_json_bytes', 8);
        Http::fake(fn () => Create::promiseFor(new PsrResponse(200, [], new NoSeekStream(Utils::streamFor('{"id":42}')))));
        $observed = false;
        Event::listen(ResponseReceived::class, static function () use (&$observed): void {
            $observed = true;
        });

        try {
            (new FreshdeskClient('example.freshdesk.com', 'test-key'))->get('/agents/me');
            $this->fail('An oversized response was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Freshdesk response exceeds the configured size limit.', $exception->getMessage());
        }
        $this->assertFalse($observed);
    }
}
