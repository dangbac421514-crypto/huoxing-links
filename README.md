# Link SaaS

This repository contains the API in `serve/`, the administration client in
`admin/`, the share page in `jump/`, and the mini-program source in
`mini_programs/`.

Installation and acceptance are CLI-only. The authoritative operator commands,
including the separation between isolated tests and production operations, are
in [`serve/README.md`](serve/README.md).

The repository is released under the terms in [`LICENSE`](LICENSE). Third-party
license and attribution obligations are recorded in
[`docs/compliance/license-inventory.md`](docs/compliance/license-inventory.md).

## Web release

Build/package, deployment prerequisites and first use are documented in
[`docs/ready-to-use-deployment.md`](docs/ready-to-use-deployment.md).
`bash deploy.sh --help` explains the read-only preflight; it never silently
initializes a production database. Release verification is recorded in
[`docs/ready-to-use-acceptance.md`](docs/ready-to-use-acceptance.md).

## 商家客诉受理

The administration client includes 商家客诉受理: a tenant-owned after-sales
feedback channel, a public `/f/{code}` form, a ticket workbench, private
attachments, and optional WeCom group-robot notifications. The public page is
merchant-operated after-sales feedback, not an official WeCom complaint entry.

Operator runbook: [`docs/feedback-operations.md`](docs/feedback-operations.md).
Isolated browser acceptance, from `admin/`:

```bash
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm exec playwright test
```

## Scope and evidence boundary

The foundation gate covers migrations, idempotent seeders, authentication,
membership lifecycle, rolling periods, usage limits, expiry projection, and
safe administrator provisioning. It does not by itself prove a deployment or
an external-client integration.

DNS, Nginx, HTTPS certificates, PHP-FPM, production MySQL/Redis, queue workers,
the scheduler, external provider credentials, real WeChat platforms, and
Android/iOS devices require separate operator-controlled evidence.
