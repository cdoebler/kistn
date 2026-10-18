<?php

namespace App\Events;

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PackagePublishedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Project $project,
        public readonly EcosystemEnum $ecosystem,
        public readonly Package $package,
    ) {}
}
