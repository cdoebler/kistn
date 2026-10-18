<?php

namespace App\Console\Commands;

use App\Enums\EcosystemEnum;
use App\Models\NotificationDigestItem;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AuditDiscrepancyNotification;
use App\Notifications\DigestNotification;
use App\Notifications\PackagePublishedNotification;
use App\Notifications\ProjectChangesNotification;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('mail:demo {email? : Address to send the demo mails to}')]
#[Description('Send one of each notification mail with randomized multi-project fake data, to preview mail templates')]
class SendDemoMailsCommand extends Command
{
    /** @var array<string, list<EcosystemEnum>> */
    private const array TYPE_ECOSYSTEMS = [
        'wordpress' => [EcosystemEnum::WpPlugin, EcosystemEnum::WpTheme],
        'laravel' => [EcosystemEnum::Composer, EcosystemEnum::Npm],
        'mixed' => [EcosystemEnum::Composer, EcosystemEnum::Npm, EcosystemEnum::WpPlugin, EcosystemEnum::WpTheme],
    ];

    public function handle(): int
    {
        $email = $this->argument('email') ?? config('mail.from.address');
        $email = is_string($email) ? $email : 'demo@example.com';

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Demo User', 'password' => Hash::make(Str::random(32))],
        );

        $projectCount = random_int(3, 5);

        // Guarantee one wordpress and one laravel project so the package-published
        // and audit-discrepancy demos always have a matching ecosystem to pick from.
        $types = ['wordpress', 'laravel'];

        for ($i = count($types); $i < $projectCount; $i++) {
            $types[] = $this->randomString(array_keys(self::TYPE_ECOSYSTEMS));
        }

        $projects = [];

        foreach ($types as $index => $type) {
            $projects[] = $this->buildProject($type, $index + 1);
        }

        /** @var list<array{package_name: string, package_version: string, advisory_id: string, severity: string}> $allFindings */
        $allFindings = [];

        foreach ($projects as $data) {
            foreach ($data['ecosystems'] as $ecosystemData) {
                array_push($allFindings, ...$ecosystemData['findings']);
            }
        }

        $this->sendPackagePublished($user, $projects);
        $this->sendAuditDiscrepancy($user, $projects, $allFindings);
        $this->sendProjectChanges($user, $projects);
        $this->sendDigest($user, $projects);

        $this->components->info(sprintf('Sent demo mails for %d project(s) to %s.', $projectCount, $email));

        return self::SUCCESS;
    }

    /** @param  list<array{type: string, project: Project, ecosystems: list<array{ecosystem: EcosystemEnum, packagesMap: array<string, array{version: string, latest_version: string}>, findings: list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>}>}>  $projects */
    private function sendPackagePublished(User $user, array $projects): void
    {
        $wpProject = Collection::make($projects)->firstWhere('type', 'wordpress');

        if ($wpProject === null) {
            return;
        }

        $ecosystemData = $wpProject['ecosystems'][0];
        $packageName = array_key_first($ecosystemData['packagesMap']);
        $package = new Package(['ecosystem' => $ecosystemData['ecosystem'], 'name' => $packageName]);

        $user->notify(new PackagePublishedNotification($wpProject['project'], $ecosystemData['ecosystem'], $package));
    }

    /**
     * @param  list<array{type: string, project: Project, ecosystems: list<array{ecosystem: EcosystemEnum, packagesMap: array<string, array{version: string, latest_version: string}>, findings: list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>}>}>  $projects
     * @param  list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>  $allFindings
     */
    private function sendAuditDiscrepancy(User $user, array $projects, array $allFindings): void
    {
        $codeProject = Collection::make($projects)->firstWhere('type', 'laravel');

        if ($codeProject === null) {
            return;
        }

        // Reuse advisory ids already surfaced in the other mails, so the discrepancy reads as part of the same story.
        $advisoryIds = Collection::make(array_column($allFindings, 'advisory_id'))->shuffle()->all();

        $ecosystemData = $codeProject['ecosystems'][0];

        $user->notify(new AuditDiscrepancyNotification(
            $codeProject['project'],
            $ecosystemData['ecosystem'],
            onlyInServer: array_slice($advisoryIds, 0, 2),
            onlyInClient: array_slice($advisoryIds, 2, 1),
        ));
    }

    /** @param  list<array{type: string, project: Project, ecosystems: list<array{ecosystem: EcosystemEnum, packagesMap: array<string, array{version: string, latest_version: string}>, findings: list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>}>}>  $projects */
    private function sendProjectChanges(User $user, array $projects): void
    {
        foreach ($projects as $data) {
            $outdatedByEcosystem = [];
            $findingsByEcosystem = [];

            foreach ($data['ecosystems'] as $ecosystemData) {
                $outdatedByEcosystem[$ecosystemData['ecosystem']->value] = OutdatedSummaryValueObject::fromPackages($ecosystemData['packagesMap']);

                if ($ecosystemData['findings'] !== []) {
                    $findingsByEcosystem[$ecosystemData['ecosystem']->value] = $ecosystemData['findings'];
                }
            }

            $payload = new ProjectNotificationPayloadValueObject($outdatedByEcosystem, $findingsByEcosystem, CarbonImmutable::now());

            $user->notify(new ProjectChangesNotification($data['project'], $payload));
        }
    }

    /** @param  list<array{type: string, project: Project, ecosystems: list<array{ecosystem: EcosystemEnum, packagesMap: array<string, array{version: string, latest_version: string}>, findings: list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>}>}>  $projects */
    private function sendDigest(User $user, array $projects): void
    {
        /** @var Collection<int, NotificationDigestItem> $items */
        $items = collect();

        foreach ($projects as $data) {
            foreach ($data['ecosystems'] as $ecosystemData) {
                $outdatedData = Collection::make($ecosystemData['packagesMap'])
                    ->map(fn (array $v, string $name): array => ['name' => $name, 'current' => $v['version'], 'latest' => $v['latest_version']])
                    ->values()
                    ->all();

                $items->push($this->makeDigestItem($data['project'], $ecosystemData['ecosystem'], 'outdated', $outdatedData));

                if ($ecosystemData['findings'] !== []) {
                    $items->push($this->makeDigestItem($data['project'], $ecosystemData['ecosystem'], 'vuln', $ecosystemData['findings']));
                }
            }
        }

        $user->notify(new DigestNotification($items));
    }

    /** @param  array<int, array<string, mixed>>  $data */
    private function makeDigestItem(Project $project, EcosystemEnum $ecosystem, string $type, array $data): NotificationDigestItem
    {
        $item = new NotificationDigestItem(['project_id' => $project->id, 'type' => $type, 'ecosystem' => $ecosystem->value, 'data' => $data, 'created_at' => now()]);
        $item->setRelation('project', $project);

        return $item;
    }

    /**
     * @param  positive-int  $id
     * @return array{type: string, project: Project, ecosystems: list<array{ecosystem: EcosystemEnum, packagesMap: array<string, array{version: string, latest_version: string}>, findings: list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>}>}
     */
    private function buildProject(string $type, int $id): array
    {
        $pool = self::TYPE_ECOSYSTEMS[$type];
        $ecosystems = Collection::make($pool)->shuffle()->take(min(count($pool), random_int(2, 3)))->all();

        $suffix = match ($type) {
            'wordpress' => $this->randomString(['Blog', 'Site', 'Shop']),
            'laravel' => $this->randomString(['API', 'Platform', 'App']),
            default => $this->randomString(['Stack', 'Suite']),
        };

        $project = new Project(['name' => fake()->company().' '.$suffix]);
        $project->id = $id;
        $project->uuid = (string) Str::uuid();

        $ecosystemsData = [];

        foreach ($ecosystems as $ecosystem) {
            $ecosystemsData[] = $this->buildEcosystemData($ecosystem);
        }

        return ['type' => $type, 'project' => $project, 'ecosystems' => $ecosystemsData];
    }

    /** @return array{ecosystem: EcosystemEnum, packagesMap: array<string, array{version: string, latest_version: string}>, findings: list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>} */
    private function buildEcosystemData(EcosystemEnum $ecosystem): array
    {
        /** @var array<string, array{version: string, latest_version: string}> $packagesMap */
        $packagesMap = [];

        for ($i = 0, $count = random_int(3, 10); $i < $count; $i++) {
            $name = Package::factory()->forEcosystem($ecosystem)->make()->name;
            [$current, $latest] = $this->randomVersionPair();
            $packagesMap[$name] = ['version' => $current, 'latest_version' => $latest];
        }

        $names = Collection::make(array_keys($packagesMap))->shuffle()->all();
        $vulnerableNames = array_slice($names, 0, random_int(0, 2));

        $findings = [];

        foreach ($vulnerableNames as $name) {
            $findings[] = [
                'package_name' => $name,
                'package_version' => $packagesMap[$name]['version'],
                'advisory_id' => $this->randomAdvisoryId(),
                'severity' => $this->randomString(['critical', 'high', 'medium', 'low']),
            ];
        }

        return ['ecosystem' => $ecosystem, 'packagesMap' => $packagesMap, 'findings' => $findings];
    }

    /** @return array{0: string, 1: string} */
    private function randomVersionPair(): array
    {
        $major = random_int(1, 8);
        $minor = random_int(0, 20);
        $patch = random_int(0, 20);
        $current = "{$major}.{$minor}.{$patch}";

        $latest = match (fake()->randomElement(['major', 'minor', 'patch'])) {
            'major' => ($major + random_int(1, 3)).'.0.0',
            'minor' => "{$major}.".($minor + random_int(1, 5)).'.0',
            default => "{$major}.{$minor}.".($patch + random_int(1, 5)),
        };

        return [$current, $latest];
    }

    private function randomAdvisoryId(): string
    {
        return match (fake()->randomElement(['ghsa', 'ghsa', 'cve', 'wpscan'])) {
            'ghsa' => 'GHSA-'.$this->randomHexGroup().'-'.$this->randomHexGroup().'-'.$this->randomHexGroup(),
            'cve' => 'CVE-'.random_int(2021, 2025).'-'.random_int(1000, 99999),
            default => 'WPSCAN-'.random_int(1000, 9999),
        };
    }

    private function randomHexGroup(): string
    {
        return substr(bin2hex(random_bytes(2)), 0, 4);
    }

    /** @param  list<string>  $options */
    private function randomString(array $options): string
    {
        $value = fake()->randomElement($options);

        return is_string($value) ? $value : $options[0];
    }
}
