<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies;

/** Host-owned AI access; this contract never receives a Freshdesk client or credential. */
interface CaseStudyGeneratorContract
{
    /**
     * @param  list<array<string,mixed>>  $sources
     * @return list<array<string,mixed>>
     */
    public function extract(CaseStudyInput $input, array $sources): array;

    /** @param list<array<string,mixed>> $candidates */
    public function compile(CaseStudyInput $input, array $candidates): CaseStudyResult;
}
