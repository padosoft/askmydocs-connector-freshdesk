<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Http;

final class UrlPolicy
{
    public static function domain(string $input): string
    {
        $input = strtolower(trim($input));
        if (str_starts_with($input, 'https://')) {
            $input = substr($input, 8);
        }
        $input = rtrim($input, '/');
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.freshdesk\.com$/D', $input) !== 1) {
            throw new \InvalidArgumentException('Enter the account domain, for example company.freshdesk.com.');
        }

        return $input;
    }

    /** Validate and pin DNS for both the API and every download redirect. */
    /**
     * @return array<string|int,mixed>
     */
    public static function options(string $url, bool $download = false): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Freshdesk URLs must use HTTPS without credentials or custom ports.');
        }
        if ($download) {
            $allowed = false;
            foreach ((array) config('connector-freshdesk.attachments.allowed_hosts', []) as $pattern) {
                if (is_string($pattern) && fnmatch($pattern, $host, FNM_CASEFOLD)) {
                    $allowed = true;
                }
            }
            if (! $allowed) {
                throw new \InvalidArgumentException('Freshdesk attachment host is not allowed.');
            }
        } else {
            self::domain($host);
        }
        if (! config('connector-freshdesk.http.resolve_dns', true)) {
            return [];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip)) {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Freshdesk URL resolved to a non-public address.');
            }
            $addresses[] = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        }
        if ($addresses === []) {
            throw new \RuntimeException('Freshdesk host could not be resolved.');
        }

        return ['curl' => [CURLOPT_RESOLVE => [$host.':443:'.implode(',', $addresses)]]];
    }
}
