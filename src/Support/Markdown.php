<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\Support;

use League\HTMLToMarkdown\HtmlConverter;

final class Markdown
{
    /**
     * @param  array<string|int,mixed>  $item
     */
    public static function body(array $item): string
    {
        $text = $item['description_text'] ?? $item['body_text'] ?? null;
        if (is_string($text) && $text !== '') {
            return $text;
        }

        return (new HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'script style iframe']))->convert((string) ($item['description'] ?? $item['body'] ?? ''));
    }

    /**
     * @param  array<string|int,mixed>  $ticket
     * @param  array<string|int,mixed>  $conversations
     */
    public static function ticket(array $ticket, array $conversations, bool $private): string
    {
        $body = '# '.str_replace(["\r", "\n"], ' ', (string) ($ticket['subject'] ?? 'Ticket'))."\n\n".self::body($ticket);
        foreach ($conversations as $conversation) {
            if (! $private && ($conversation['private'] ?? false)) {
                continue;
            }
            $body .= "\n\n## ".(($conversation['private'] ?? false) ? 'Private note' : 'Conversation').' · '.($conversation['created_at'] ?? '')."\n\n".self::body($conversation);
        }

        return $body."\n";
    }
}
