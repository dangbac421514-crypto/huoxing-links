# Reuse and dependency decisions

Baseline captured 2026-09-01 from branch `codex/full-function-implementation` at
`5e20731` (`chore: ignore local worktrees`). The expected fork/source remotes
are present (`origin` = `dangbac421514-crypto/huoxing-links`, `upstream` =
`surprise-tech/huoxing-links`). An earlier `admin/pnpm-workspace.yaml` was
non-task setup residue; the controller removed it before Task 0 acceptance and
it was never committed.

The decisions below are the approved controller rulings. Versions are target
versions for the stabilization work, not claims that this baseline already
contains them. No private code, domains, credentials, or test data are
included.

| Capability | Candidate | URL | Version/commit | License | Maintenance signal | Adopt/reject reason |
| --- | --- | --- | --- | --- | --- | --- |
| External HTTP | Laravel HTTP Client | https://github.com/laravel/framework/tree/v13.0.0 | Laravel 13.0.0 supplied client | MIT | Official Laravel release tag | **Adopt**; use the framework-provided client and add no production dependency. |
| External HTTP | Existing Guzzle | https://github.com/guzzle/guzzle/tree/7.9.2 | 7.9.2 | MIT | Composer lock tag; maintained upstream | **Reject as primary abstraction**; retain only where existing code requires it. |
| External HTTP | Symfony HttpClient | https://github.com/symfony/http-client/tree/v6.4.11 | v6.4.11 | MIT | Composer lock tag; maintained upstream | **Reject**; duplicates the framework path and is not the approved application interface. |
| Backend runtime | Laravel 10 / PHP 8.1 | https://github.com/laravel/framework/tree/v10.48.20 | v10.48.20 / PHP 8.1 | MIT / PHP license | Existing lock tag | **Reject**; below the approved runtime target. |
| Backend runtime | Laravel 12 / PHP 8.2 | https://github.com/laravel/framework/tree/v12.0.0 | v12.0.0 / PHP 8.2 | MIT / PHP license | Official release tag | **Reject**; not the approved target. |
| Backend runtime | Laravel 13 / PHP 8.3 | https://github.com/laravel/framework/tree/v13.0.0 | v13.0.0 / PHP 8.3 | MIT / PHP license | Official release tag | **Adopt**; target for foundation task and runtime modernization. |
| Image captcha | mews/captcha | https://github.com/mewebstudio/captcha/tree/3.4.3 | 3.4.3 | MIT | Composer lock tag; maintained upstream | **Reject**; controller approved Gregwar Captcha instead. |
| Image captcha | Gregwar Captcha | https://github.com/Gregwar/Captcha/tree/2.1.1 | 2.1.1 | MIT | Approved pinned release tag | **Adopt**; approved captcha implementation. |
| Image captcha | Custom GD | N/A — in-repository procedure, no versioned artifact | N/A — custom code has no release tag | Project/root terms | No independent maintenance signal | **Reject**; increases security and maintenance surface. |
| SMS | Custom AliDySms | N/A — in-repository procedure, no versioned artifact | N/A — custom implementation has no release tag | Project/root terms | Local implementation only | **Reject**; replace custom gateway handling with approved adapter. |
| SMS | Existing EasySMS | https://github.com/overtrue/easy-sms/tree/3.0.1 | 3.0.1 existing; 3.2.1 upgrade target | MIT | Existing lock tag; upstream release history | **Adopt via upgrade target**; use `overtrue/easy-sms` 3.2.1 with Aliyun gateway. |
| SMS | Alibaba SDK | N/A — vendor SDK family, no single versioned artifact selected | N/A — component/version depends on gateway | Varies by component | Alibaba official SDK catalog | **Reject**; adds a larger vendor surface than the approved EasySMS gateway. |
| Admin build | Vite 4 | https://github.com/vitejs/vite/tree/v4.3.9 | v4.3.9 | MIT | Existing manifest tag | **Reject**; below approved target. |
| Admin build | Vite 7 | https://github.com/vitejs/vite/tree/v7.3.6 | v7.3.6 | MIT | npm metadata verified 2026-09-01; official release tag | **Adopt** with Vue 3.5.42. |
| Admin build | Vite 8 | https://github.com/vitejs/vite/tree/v8.0.0 | v8.0.0 | MIT | Official release tag | **Reject**; not the approved target. |
| Vue tests | Vitest | https://github.com/vitest-dev/vitest/tree/v4.1.10 | 4.1.10 | MIT | npm metadata verified 2026-09-01; official release tag | **Adopt**. Peer metadata accepts Vite 7 and requires Node 20+. |
| Vue tests | Jest | https://github.com/jestjs/jest/tree/v30.2.0 | v30.2.0 | MIT | Official release tag | **Reject**; not aligned with the approved Vite/Vitest toolchain. |
| Vue tests | Bare Vue Test Utils | https://github.com/vuejs/test-utils/tree/v2.5.0 | 2.5.0 | MIT | npm metadata verified 2026-09-01; official release tag | **Adopt alongside Vitest**, not as the test runner. |
| Browser E2E | Playwright | https://github.com/microsoft/playwright/tree/v1.62.1 | @playwright/test 1.62.1 | Apache-2.0 | npm metadata verified 2026-09-01; official release tag | **Adopt**; Node >=20. |
| Browser E2E | Cypress | https://github.com/cypress-io/cypress/tree/v15.8.0 | v15.8.0 | MIT | Official release tag | **Reject**; Playwright is the approved runner. |
| Browser E2E | Selenium | https://github.com/SeleniumHQ/selenium/tree/selenium-4.30.0 | selenium-4.30.0 | Apache-2.0 | Official release tag | **Reject**; unnecessary additional browser stack. |
| uni-app | Manual HBuilderX | https://uniapp.dcloud.net.cn/quickstart-cli | N/A — procedure, no versioned artifact | DCloud terms | DCloud official documentation | **Reject**; not reproducible enough for the approved build path. |
| uni-app | HBuilderX CLI | https://uniapp.dcloud.net.cn/quickstart-cli | N/A — procedure/tool, no versioned repository artifact | DCloud terms | DCloud official CLI documentation | **Reject**; approved target is the official Vue2 package line. |
| uni-app | Official Vue2 CLI packages | https://github.com/dcloudio/uni-app/tree/v2.0.2-5020420260813001 | 2.0.2-5020420260813001 | Apache-2.0 (metadata) | npm metadata verified 2026-09-01; official release tag | **Adopt**; approved uni-app target. |
| UV atomicity | Redis Set/Lua | N/A — procedure, no versioned artifact | Existing Redis/Predis v2.2.2 | MIT for Predis | Existing runtime capability | **Reject for account UV**; Redis remains only for QR rotation. |
| UV atomicity | Database lock + unique visitor rows | N/A — application/database procedure, no versioned artifact | N/A — MySQL server version is deployment-owned | Database/vendor terms | Native transactional constraint | **Adopt**; atomic account UV counting and idempotency. |
| UV atomicity | Log aggregation | N/A — procedure, no versioned artifact | N/A — aggregation has no release artifact | Varies | Operational tooling only | **Reject**; not an atomic source of truth. |
| TLS | Manual certificate | N/A — procedure, no versioned artifact | N/A — manual certificate process | N/A | No automated renewal signal | **Reject**; renewal and reproducibility risk. |
| TLS | acme.sh | https://github.com/acmesh-official/acme.sh/tree/3.1.0 | 3.1.0 | GPL-3.0 | Official release tag | **Reject**; not the approved certificate workflow. |
| TLS | Certbot webroot | https://github.com/certbot/certbot/tree/v4.0.0 | v4.0.0 | Apache-2.0 | Official release tag | **Adopt**; webroot issuance and renewal. |

## Compatibility evidence

The exact read-only metadata commands in the task brief were run on
2026-09-01 without installing or upgrading packages. npm returned the approved
versions/licenses: Vitest 4.1.10 (MIT), Vue Test Utils 2.5.0 (MIT), Playwright
1.62.1 (Apache-2.0), `@dcloudio/uni-mp-weixin`
2.0.2-5020420260813001 (Apache-2.0), Vite 7.3.6 (MIT), and Vue 3.5.42 (MIT).
Composer reported `composer.json is valid but your composer.lock has some
errors` and specifically that the lock file is not up to date with
`composer.json`. This is an intentional baseline finding for foundation Task
1; this task does not fix it.
