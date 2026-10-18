<?php

namespace App\Ecosystems\Contracts;

use App\Models\Project;
use App\ValueObjects\EcosystemProcessResultValueObject;

interface EcosystemProcessor
{
    /**
     * @param  array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool|null, is_child: bool|null, depth: int|null, source_url: string|null, available_version: string|null, author: string|null}>  $packages
     * @param  array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $findings
     * @param  array<int, array{ecosystem: string, name: string, payload: array<int, mixed>}>  $advisories
     * @param  list<string>  $privatePackages
     * @param  list<string>  $publicPackages
     */
    public function process(
        Project $project,
        string $payloadHash,
        array $packages,
        array $findings,
        array $advisories,
        array $privatePackages,
        array $publicPackages = [],
    ): EcosystemProcessResultValueObject;
}
