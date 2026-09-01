# Link SaaS API

`serve/` is the Laravel API and the single source of truth for migrations,
seeders, authentication, membership, entitlements, and link resolution.

## Isolated test commands

Run these commands from the repository root. `serve/bin/test-env` applies the
testing environment allowlist: a database whose name ends in `_test`, Redis DB
14/15 with the test prefix, array cache/mail, and a synchronous test queue.
They never target a production database and must not send real mail or SMS.

```bash
docker compose -f compose.test.yaml up -d --wait
serve/bin/test-env php artisan migrate:fresh --seed
serve/bin/test-env php artisan test
```

To demonstrate repeatable seeders after the fresh install:

```bash
serve/bin/test-env php artisan db:seed
serve/bin/test-env php artisan db:seed
```

The full foundation result must be zero failing tests. PHP 8.5 can print the
known nullable-parameter deprecations from the tracked `wyzheng/ugly-base`
package; these are runtime-version compatibility notes, not application test
failures, and must not be hidden by changing vendor code.

## Production operator commands

Production operations are allowed only after the deployment process has
created a restricted `APP_ENV_FILE`, completed a database backup, and placed
the operator in the intended release directory. Load that file through the
deployment process; do not copy `.env.example` into production and do not put
secrets, passwords, or tokens in this document.

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan app:admin-provision
php artisan schedule:list
```

`app:admin-provision` asks for the username and reads the password through
hidden interactive input. It does not accept a password command-line
argument, prints no password or token, and marks the provisioned administrator
for a mandatory first password change.

Never run `php artisan migrate:fresh` in production. Never use
`php artisan test` as a production health check; use the isolated
`serve/bin/test-env` wrapper for tests. Do not use the removed browser
installer, import `base.sql`/`packages.sql` at runtime, or create a default
administrator from a seeder.

## Acceptance evidence

| Evidence lane | What the repository commands establish | What remains separate |
| --- | --- | --- |
| Code and database | Fresh migrations, repeatable seeders, isolated tests, API route listing, scheduler registration, dependency and source scans | No production deployment claim |
| Deployment and infrastructure | Nothing from the local foundation suite | DNS, Nginx, HTTPS, PHP-FPM, production MySQL/Redis, queue workers, and scheduler execution |
| External platforms and devices | Nothing from fakes or local tests | Provider credentials, real WeChat/API callbacks, and Android/iOS device behavior |

Passing the code/database lane is not a DNS, Nginx, HTTPS, external-provider,
or device acceptance result. Missing operator credentials or infrastructure
access must remain an explicit external blocker.

Dependency acceptance uses manifest constraints together with the resolved
lock: Guzzle is `constraint ^7.15.2; resolved 7.15.5`, not an exact 7.15.2
lock requirement.

## Runtime initialization

Fresh installations use Laravel migrations and the idempotent database
seeders. The legacy `/install` browser flow and its SQL-dump import path are
removed. Provision the first administrator only with `app:admin-provision`.
