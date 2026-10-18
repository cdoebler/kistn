<?php

use App\Enums\VulnSourceEnum;
use Tests\TestCase;

// label() calls __(), which needs the app's translator bound.
uses(TestCase::class);

it('labels each source, translated', function (): void {
    expect(VulnSourceEnum::ClientAudit->label())->toBe('Client')
        ->and(VulnSourceEnum::ServerOsv->label())->toBe('OSV')
        ->and(VulnSourceEnum::ServerWpscan->label())->toBe('WPScan')
        ->and(VulnSourceEnum::ServerGithub->label())->toBe('GitHub')
        ->and(VulnSourceEnum::ServerComposerAudit->label())->toBe('Composer audit')
        ->and(VulnSourceEnum::ServerNpmAudit->label())->toBe('npm audit');
});
