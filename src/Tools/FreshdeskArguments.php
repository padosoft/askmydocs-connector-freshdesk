<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Tools;

/** Request identity follows the actual wire defaults, not the planner's spelling. */
final class FreshdeskArguments
{
    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public static function normalize(string $action, array $arguments): array
    {
        if (in_array($action, ['list_tickets', 'search_tickets', 'list_conversations'], true)) {
            $arguments['page'] = (int) ($arguments['page'] ?? 1);
        }
        if ($action === 'list_tickets') {
            $arguments['per_page'] = min(100, (int) ($arguments['per_page'] ?? 30));
            if (isset($arguments['requester_id']) || isset($arguments['email'])) {
                $arguments['updated_since'] ??= '1970-01-01T00:00:00Z';
            }
        }
        if ($action === 'search_tickets' && is_string($arguments['query'] ?? null)) {
            $arguments['query'] = trim(trim($arguments['query']), '"');
        }
        ksort($arguments);

        return $arguments;
    }

    /** Parse the filter grammar; never interpret a person's name as a filter. */
    public static function validQuery(string $query): bool
    {
        $offset = $depth = 0;
        $operand = true;
        $length = strlen($query);
        while ($offset < $length) {
            if (preg_match('/\G\s+/A', $query, $match, 0, $offset)) {
                $offset += strlen($match[0]);

                continue;
            }
            if ($operand && $query[$offset] === '(') {
                $depth++;
                $offset++;

                continue;
            }
            if (! $operand && $query[$offset] === ')') {
                if (--$depth < 0) {
                    return false;
                }
                $offset++;

                continue;
            }
            if (! $operand && preg_match('/\G(?:AND|OR)\b/A', $query, $match, 0, $offset)) {
                $operand = true;
                $offset += strlen($match[0]);

                continue;
            }
            if (! $operand || ! preg_match("/\\G([a-z][a-z0-9_]*):[><]?(?:-?[0-9]+(?:\\.[0-9]+)?|true|false|null|'(?:[^'\\\\]|\\\\.)*')/A", $query, $match, 0, $offset)) {
                return false;
            }
            if (in_array($match[1], ['requester', 'requester_id', 'name', 'email', 'subject', 'description'], true)) {
                return false;
            }
            $operand = false;
            $offset += strlen($match[0]);
        }

        return ! $operand && $depth === 0;
    }
}
