<?php

use App\Enums\EcosystemEnum;
use App\ValueObjects\EcosystemProcessResultValueObject;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;

test('EcosystemProcessResultValueObject holds ecosystem, summary, and findings', function (): void {
    $summary = new OutdatedSummaryValueObject([], [], [], []);
    $result = new EcosystemProcessResultValueObject(EcosystemEnum::Composer, $summary, []);

    expect($result->ecosystem)->toBe(EcosystemEnum::Composer)
        ->and($result->outdatedSummary)->toBe($summary)
        ->and($result->newFindings)->toBe([]);
});

test('ProjectNotificationPayloadValueObject isEmpty when all empty', function (): void {
    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );
    expect($payload->isEmpty())->toBeTrue();
});

test('ProjectNotificationPayloadValueObject is not empty when findings present', function (): void {
    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: ['composer' => [['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-x', 'severity' => 'high']]],
        triggeredAt: CarbonImmutable::now(),
    );
    expect($payload->isEmpty())->toBeFalse();
});

test('ProjectNotificationPayloadValueObject is not empty when outdated summary has packages', function (): void {
    $summary = new OutdatedSummaryValueObject(
        major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
        minor: [], patch: [], other: [],
    );
    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: ['composer' => $summary],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );
    expect($payload->isEmpty())->toBeFalse();
});
