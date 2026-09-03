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

## Scope and evidence boundary

The foundation gate covers migrations, idempotent seeders, authentication,
membership lifecycle, rolling periods, usage limits, expiry projection, and
safe administrator provisioning. It does not by itself prove a deployment or
an external-client integration.

DNS, Nginx, HTTPS certificates, PHP-FPM, production MySQL/Redis, queue workers,
the scheduler, external provider credentials, real WeChat platforms, and
Android/iOS devices require separate operator-controlled evidence.
