<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorFreshdesk\Exceptions\FreshdeskApiException;

final class FreshdeskClient
{
    private string $domain;

    public int $physicalRequests = 0;

    public function __construct(string $domain, private readonly string $apiKey, private readonly ?Closure $beforeRequest = null)
    {
        $this->domain = UrlPolicy::domain($domain);
        if (trim($this->apiKey) === '') {
            throw new \InvalidArgumentException('Enter a Freshdesk API key.');
        }
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /** @return array<mixed> */
    /**
     * @param  array<string|int,mixed>  $query
     * @return array<string|int,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        if (preg_match('~^/[a-z0-9_/]+$~D', $path) !== 1) {
            throw new \InvalidArgumentException('Invalid Freshdesk API path.');
        }
        $url = 'https://'.$this->domain.'/api/v2'.$path;
        $options = UrlPolicy::options($url) + ['allow_redirects' => false, 'stream' => true];
        $attempts = max(1, min(3, (int) config('connector-freshdesk.http.attempts', 3)));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->countRequest();
            try {
                $response = Http::acceptJson()->withBasicAuth($this->apiKey, 'X')
                    ->connectTimeout((int) config('connector-freshdesk.http.connect_timeout', 5))
                    ->timeout((int) config('connector-freshdesk.http.timeout', 15))
                    ->withOptions($options)->get($url, $query);
            } catch (ConnectionException) {
                if ($attempt === $attempts) {
                    throw new \RuntimeException('Freshdesk connection timed out or could not be established.');
                }

                continue;
            }
            if ($response->serverError() && $attempt < $attempts) {
                continue;
            }
            if (! $response->successful()) {
                $fields = [];
                if ($response->status() === 400) {
                    try {
                        $error = json_decode(self::readLimited($response, 16384), true, 32, JSON_THROW_ON_ERROR);
                        foreach (array_slice((array) ($error['errors'] ?? []), 0, 10) as $field) {
                            if (is_array($field) && preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', (string) ($field['field'] ?? ''))
                                && in_array($field['code'] ?? '', ['invalid_value', 'invalid_field', 'missing_field', 'invalid_json'], true)) {
                                $fields[] = ['field' => $field['field'], 'code' => $field['code']];
                            }
                        }
                    } catch (\Throwable) {
                        // Diagnostic bodies cannot replace the original HTTP failure.
                    }
                }
                throw new FreshdeskApiException($response->status(), max(1, (int) $response->header('Retry-After')), $fields);
            }
            $body = self::readLimited($response, (int) config('connector-freshdesk.http.max_json_bytes', 2_000_000));
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \RuntimeException('Freshdesk returned invalid JSON.');
            }
            if (! is_array($data)) {
                throw new \RuntimeException('Freshdesk returned an unexpected response.');
            }

            return $data;
        }
        throw new \LogicException('Unreachable Freshdesk response.');
    }

    public function download(string $url, int $maxBytes): string
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $options = UrlPolicy::options($url, true) + ['allow_redirects' => false, 'stream' => true];
            $this->countRequest();
            try {
                // The API key is deliberately absent, including on same-domain downloads.
                $response = Http::connectTimeout(5)->timeout(60)->withOptions($options)->get($url);
            } catch (ConnectionException) {
                throw new \RuntimeException('Freshdesk attachment download failed.');
            }
            if ($response->redirect()) {
                $next = $response->header('Location');
                if (! str_starts_with($next, 'https://')) {
                    throw new \RuntimeException('Freshdesk attachment redirect is invalid.');
                }
                $url = $next;

                continue;
            }
            if (! $response->successful()) {
                throw new FreshdeskApiException($response->status(), max(1, (int) $response->header('Retry-After')));
            }

            return self::readLimited($response, $maxBytes);
        }
        throw new \RuntimeException('Freshdesk attachment exceeded the redirect limit.');
    }

    private function countRequest(): void
    {
        if ($this->beforeRequest !== null) {
            ($this->beforeRequest)();
        }
        $this->physicalRequests++;
    }

    public static function readLimited(Response $response, int $maxBytes): string
    {
        if ((int) $response->header('Content-Length') > $maxBytes) {
            throw new \RuntimeException('Freshdesk response exceeds the configured size limit.');
        }
        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $stream->eof()) {
            $bytes .= $stream->read(min(65536, $maxBytes - strlen($bytes) + 1));
            if (strlen($bytes) > $maxBytes) {
                throw new \RuntimeException('Freshdesk response exceeds the configured size limit.');
            }
        }

        return $bytes;
    }
}
