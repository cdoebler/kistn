<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;

readonly class ProjectNotificationPayloadValueObject
{
    /**
     * @param  array<string, OutdatedSummaryValueObject>  $outdatedByEcosystem  Keyed by ecosystem value
     * @param  array<string, list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>>  $findingsByEcosystem  Keyed by ecosystem value
     */
    public function __construct(
        public array $outdatedByEcosystem,
        public array $findingsByEcosystem,
        public CarbonImmutable $triggeredAt,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->outdatedByEcosystem as $summary) {
            if (! $summary->isEmpty()) {
                return false;
            }
        }

        foreach ($this->findingsByEcosystem as $findings) {
            if ($findings !== []) {
                return false;
            }
        }

        return true;
    }
}
