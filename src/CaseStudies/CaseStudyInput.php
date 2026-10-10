<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies;

final readonly class CaseStudyInput
{
    /** @param list<array<string,mixed>> $sources */
    public function __construct(
        public string $tenantId,
        public int $installationId,
        public string $ticketId,
        public string $projectKey,
        public string $title,
        public string $sourceUrl,
        public array $sources,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data['tenantId'], $data['installationId'], $data['ticketId'], $data['projectKey'], $data['title'], $data['sourceUrl'], $data['sources']);
    }

    /** Complete UTF-8 segments, including every message; never a head/tail truncation.
     * @return list<list<array<string,mixed>>>
     */
    public function segments(int $bytes = 10000): array
    {
        if ($bytes < 4) {
            throw new \InvalidArgumentException('Freshdesk source segments must hold a complete UTF-8 character.');
        }
        $segments = [];
        foreach ($this->sources as $source) {
            $text = (string) $source['text'];
            while ($text !== '') {
                $part = mb_strcut($text, 0, $bytes, 'UTF-8');
                $segments[] = [array_replace($source, ['text' => $part])];
                $text = substr($text, strlen($part));
            }
        }

        return $segments;
    }

    /** @return array<string,mixed>|null */
    public function source(string $key): ?array
    {
        foreach ($this->sources as $source) {
            if ($source['key'] === $key) {
                return $source;
            }
        }

        return null;
    }
}
