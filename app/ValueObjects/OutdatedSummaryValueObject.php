<?php

namespace App\ValueObjects;

readonly class OutdatedSummaryValueObject
{
    /**
     * @param  list<array{name: string, current: string, latest: string}>  $major
     * @param  list<array{name: string, current: string, latest: string}>  $minor
     * @param  list<array{name: string, current: string, latest: string}>  $patch
     * @param  list<array{name: string, current: string, latest: string}>  $other
     */
    public function __construct(
        public array $major,
        public array $minor,
        public array $patch,
        public array $other,
    ) {}

    /**
     * @param  array<string, array{version: string, latest_version: string}>  $packages
     */
    public static function fromPackages(array $packages): self
    {
        $major = [];
        $minor = [];
        $patch = [];
        $other = [];

        foreach ($packages as $name => $data) {
            $entry = ['name' => $name, 'current' => $data['version'], 'latest' => $data['latest_version']];

            match (self::classify($data['version'], $data['latest_version'])) {
                'major' => $major[] = $entry,
                'minor' => $minor[] = $entry,
                'patch' => $patch[] = $entry,
                default => $other[] = $entry,
            };
        }

        return new self($major, $minor, $patch, $other);
    }

    public static function classify(string $current, string $latest): string
    {
        $parse = static function (string $v): ?array {
            $v = preg_replace('/[-+].*$/', '', $v) ?? ''; // @pest-mutate-ignore preg_replace never returns null for a valid pattern

            if ($v === '') { // @pest-mutate-ignore redundant with the is_numeric check below, which also rejects an empty segment
                return null;
            }

            $parts = explode('.', $v);
            $parts = array_pad($parts, 3, '0'); // @pest-mutate-ignore missing segments cast to int are 0 either way, same as the '0' pad value

            foreach ($parts as $part) {
                if (! is_numeric($part)) {
                    return null;
                }
            }

            return [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
        };

        $c = $parse($current);
        $l = $parse($latest);

        if ($c === null || $l === null) {
            return 'other';
        }

        if ($c[0] !== $l[0]) {
            return 'major';
        }

        if ($c[1] !== $l[1]) {
            return 'minor';
        }

        if ($c[2] !== $l[2]) {
            return 'patch';
        }

        return 'other';
    }

    public function isEmpty(): bool
    {
        return $this->major === [] && $this->minor === [] && $this->patch === [] && $this->other === [];
    }

    public function matchesSettings(bool $major, bool $minor, bool $patch): bool
    {
        if ($major && $this->major !== []) {
            return true;
        }

        if ($minor && $this->minor !== []) {
            return true;
        }

        if ($patch && $this->patch !== []) {
            return true;
        }

        if (($major || $minor || $patch) && $this->other !== []) {
            return true;
        }

        return false;
    }
}
