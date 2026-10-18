<?php

namespace App\Enums;

enum VulnSourceEnum: string
{
    case ClientAudit = 'client-audit';
    case ServerOsv = 'server-osv';
    case ServerWpscan = 'server-wpscan';
    case ServerGithub = 'server-github';
    case ServerComposerAudit = 'server-composer-audit';
    case ServerNpmAudit = 'server-npm-audit';

    public function label(): string
    {
        return match ($this) {
            self::ClientAudit => (string) __('Client'),
            self::ServerOsv => (string) __('OSV'),
            self::ServerWpscan => (string) __('WPScan'),
            self::ServerGithub => (string) __('GitHub'),
            self::ServerComposerAudit => (string) __('Composer audit'),
            self::ServerNpmAudit => (string) __('npm audit'),
        };
    }
}
