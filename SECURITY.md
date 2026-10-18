# Security Policy

## Supported versions

Only the latest release on `main` receives security fixes.

## Reporting a vulnerability

Do **not** open a public issue for security problems.

Report privately via GitHub: **Security → Advisories → Report a vulnerability** on this repository. Include:

- affected component (API endpoint, job, view, file upload handling, …)
- steps to reproduce or a proof of concept
- impact as you understand it

You can expect an acknowledgement within 7 days. Once a fix is available, the advisory is published and credited to you unless you prefer otherwise.

## Scope

Particularly relevant areas:

- API authentication and project isolation (Sanctum tokens owned by `Project`)
- Uploaded lock/manifest file handling and sanitization before server-side `composer`/`npm` runs
- Leakage of private packages or findings across projects
