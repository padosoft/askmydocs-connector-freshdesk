<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies;

final readonly class CaseStudyResult
{
    public const FIELDS = ['problem', 'cause', 'intervention', 'outcome'];

    public const MIME = 'application/vnd.askmydocs.freshdesk-case+markdown';

    /**
     * @param  array<string,mixed>  $fields  Untrusted generator output; validate before rendering.
     * @param  array<string,mixed>  $usage
     */
    public function __construct(public array $fields, public array $usage = []) {}

    /** Validate every reference against the trusted redacted snapshot, not AI-supplied identifiers.
     * @param  array<string,mixed>  $candidate
     */
    public static function validateCandidate(CaseStudyInput $input, array $candidate): void
    {
        if (! in_array($candidate['field'] ?? null, self::FIELDS, true)
            || ! is_string($candidate['text'] ?? null) || trim($candidate['text']) === '' || mb_strlen($candidate['text']) > 1000
            || ! is_array($candidate['evidence'] ?? null) || ! array_is_list($candidate['evidence'])
            || count($candidate['evidence']) < 1 || count($candidate['evidence']) > 4) {
            throw new \UnexpectedValueException('Invalid Freshdesk case fact.');
        }
        foreach ($candidate['evidence'] as $evidence) {
            $source = is_array($evidence) && is_string($evidence['source_key'] ?? null) ? $input->source($evidence['source_key']) : null;
            $quote = $evidence['quote'] ?? null;
            if ($source === null || ! is_string($quote) || trim($quote) === '' || mb_strlen($quote) > 1500 || ! str_contains($source['text'], $quote)) {
                throw new \UnexpectedValueException('Freshdesk case evidence does not match its source.');
            }
        }
    }

    public function validate(CaseStudyInput $input): void
    {
        if (array_diff(array_keys($this->fields), self::FIELDS) !== [] || count($this->fields) !== 4) {
            throw new \UnexpectedValueException('Freshdesk case needs exactly four fields.');
        }
        foreach ($this->fields as $name => $field) {
            if (! is_array($field) || ! is_string($field['text'] ?? null) || ! is_array($field['evidence'] ?? null) || ! array_is_list($field['evidence'])
                || ! in_array($field['status'] ?? null, ['documented', 'not_documented', 'conflicting'], true)) {
                throw new \UnexpectedValueException('Invalid Freshdesk case field status.');
            }
            if ($field['status'] === 'not_documented') {
                if ($field['text'] !== '' || $field['evidence'] !== []) {
                    throw new \UnexpectedValueException('Unverified Freshdesk case field contains a fact.');
                }
            } else {
                self::validateCandidate($input, [...$field, 'field' => $name]);
            }
        }
    }

    public function markdown(CaseStudyInput $input): string
    {
        $labels = ['problem' => 'Problema', 'cause' => 'Causa', 'intervention' => 'Intervento', 'outcome' => 'Esito'];
        $body = '# Caso Freshdesk #'.$input->ticketId.' — '.str_replace(["\r", "\n"], ' ', $input->title)."\n\n";
        $body .= "Scheda derivata da evidenze verificate; descrive osservazioni storiche.\n\n";
        $references = [];
        foreach ($labels as $name => $label) {
            $field = $this->fields[$name];
            $text = match ($field['status']) {
                'not_documented' => 'Non documentato con evidenze verificate.',
                'conflicting' => 'Informazioni contrastanti nelle fonti; nessuna conclusione confermata.',
                default => str_replace(["\r", "\n"], ' ', $field['text']),
            };
            $refs = [];
            foreach ($field['evidence'] ?? [] as $evidence) {
                $number = count($references) + 1;
                $refs[] = '[E'.$number.']';
                $references[] = $evidence;
            }
            $body .= '**'.$label.':** '.$text.' '.implode(' ', $refs)."\n\n";
        }
        $body .= '[Ticket originale]('.$input->sourceUrl.")\n\n## Evidenze originali\n\n";
        foreach ($references as $index => $evidence) {
            $source = $input->source($evidence['source_key']);
            $label = $source['conversation_id'] === null ? 'descrizione iniziale' : 'conversazione #'.$source['conversation_id'];
            $body .= '### E'.($index + 1).' — '.$label.' · '.($source['created_at'] ?? '')."\n\n";
            $body .= '> '.str_replace("\n", "\n> ", $evidence['quote'])."\n\n";
        }

        return $body;
    }
}
