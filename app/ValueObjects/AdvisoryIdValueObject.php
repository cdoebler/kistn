<?php

namespace App\ValueObjects;

readonly class AdvisoryIdValueObject
{
    public function __construct(public string $id) {}

    public function url(): ?string
    {
        if (str_starts_with($this->id, 'GHSA-')) {
            return 'https://github.com/advisories/'.$this->id;
        }

        if (str_starts_with($this->id, 'CVE-')) {
            return 'https://www.cve.org/CVERecord?id='.$this->id;
        }

        if (str_starts_with($this->id, 'PKSA-')) {
            return 'https://packagist.org/security-advisories/'.$this->id;
        }

        return null;
    }
}
