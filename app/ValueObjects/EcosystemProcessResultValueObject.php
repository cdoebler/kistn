<?php

namespace App\ValueObjects;

use App\Enums\EcosystemEnum;

readonly class EcosystemProcessResultValueObject
{
    /**
     * @param  list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $newFindings
     */
    public function __construct(
        public EcosystemEnum $ecosystem,
        public OutdatedSummaryValueObject $outdatedSummary,
        public array $newFindings,
    ) {}
}
