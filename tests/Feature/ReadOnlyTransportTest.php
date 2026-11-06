<?php

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorFreshdesk\Http\FreshdeskClient;
use Padosoft\AskMyDocsConnectorFreshdesk\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadOnlyTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['https://example.freshdesk.com/*' => Http::response(['id' => 1])]);
    }

    #[DataProvider('nonGetMethods')]
    public function test_transport_blocks_non_get_methods_before_they_reach_the_http_handler(string $method): void
    {
        try {
            $this->guardedTransport()->send($method, 'https://example.freshdesk.com/api/v2/tickets/1');
            $this->fail('A non-GET Freshdesk request reached the transport.');
        } catch (\LogicException $exception) {
            $this->assertSame('Freshdesk transport only permits GET requests.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_transport_still_allows_reading(): void
    {
        $response = $this->guardedTransport()->get('https://example.freshdesk.com/api/v2/tickets/1');

        $this->assertSame(1, $response->json('id'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    /** @return array<string, array{string}> */
    public static function nonGetMethods(): array
    {
        return array_combine(
            ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'],
            array_map(fn ($method) => [$method], ['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT']),
        );
    }

    private function guardedTransport(): PendingRequest
    {
        // Exercise accidental future verb changes through the production guard.
        return (new \ReflectionMethod(FreshdeskClient::class, 'readOnly'))->invoke(null, Http::acceptJson());
    }
}
