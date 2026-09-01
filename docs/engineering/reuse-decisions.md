# Reuse and dependency decisions

Baseline captured 2026-09-01 from branch `codex/full-function-implementation` at
`5e20731` (`chore: ignore local worktrees`). The expected fork/source remotes
are present (`origin` = `dangbac421514-crypto/huoxing-links`, `upstream` =
`surprise-tech/huoxing-links`). The worktree also contains an unrelated,
pre-existing untracked `admin/pnpm-workspace.yaml`; it is not part of this
record or the baseline commit.

The decisions below are the approved controller rulings. Versions are target
versions for the stabilization work, not claims that this baseline already
contains them. No private code, domains, credentials, or test data are
included.

| Capability | Candidate | URL | Version/commit | License | Maintenance signal | Adopt/reject reason |
| --- | --- | --- | --- | --- | --- | --- |
| External HTTP | Laravel HTTP Client | https://github.com/laravel/framework | Laravel 13 supplied client | MIT | Official Laravel framework, maintained upstream | **Adopt**; use the framework-provided client and add no production dependency. |
| External HTTP | Existing Guzzle | https://github.com/guzzle/guzzle | Existing lock: 7.9.2 | MIT | Existing transitive/runtime package in lock | **Reject as primary abstraction**; retain only where existing code requires it. |
| External HTTP | Symfony HttpClient | https://github.com/symfony/http-client | Existing lock: v6.4.11 | MIT | Official Symfony component, maintained upstream | **Reject**; duplicates the framework path and is not the approved application interface. |
| Backend runtime | Laravel 10 / PHP 8.1 | https://github.com/laravel/framework | Existing manifest target `^10.10` / `^8.1` | MIT / PHP license | Existing baseline only | **Reject**; below the approved runtime target. |
| Backend runtime | Laravel 12 / PHP 8.2 | https://github.com/laravel/framework | Alternative | MIT / PHP license | Official upstream | **Reject**; not the approved target. |
| Backend runtime | Laravel 13 / PHP 8.3 | https://github.com/laravel/framework | Approved target | MIT / PHP license | Official upstream | **Adopt**; target for foundation task and runtime modernization. |
| Image captcha | mews/captcha | https://github.com/mewebstudio/captcha | Existing lock: 3.4.3 | MIT | Existing package in lock | **Reject**; controller approved Gregwar Captcha instead. |
| Image captcha | Gregwar Captcha | https://github.com/Gregwar/Captcha | 2.1.1 | MIT | Established package; version pinned by approval | **Adopt**; approved captcha implementation. |
| Image captcha | Custom GD | N/A (in-repository implementation) | N/A | Project/root terms | No independent maintenance signal | **Reject**; increases security and maintenance surface. |
| SMS | Custom AliDySms | N/A (existing application code) | Existing implementation | Project/root terms | Local implementation | **Reject**; replace custom gateway handling with approved adapter. |
| SMS | Existing EasySMS | https://github.com/overtrue/easy-sms | Existing lock: 3.0.1 | MIT | Existing package in lock | **Adopt via upgrade target**; use `overtrue/easy-sms` 3.2.1 with Aliyun gateway. |
| SMS | Alibaba SDK | https://github.com/aliyun | Alternative SDK | Varies by component | Vendor-maintained components | **Reject**; adds a larger vendor surface than the approved EasySMS gateway. |
| Admin build | Vite 4 | https://github.com/vitejs/vite | Existing manifest target `^4.3.9` | MIT | Existing baseline | **Reject**; below approved target. |
| Admin build | Vite 7 | https://github.com/vitejs/vite | 7.3.6 | MIT | npm metadata verified 2026-09-01; official upstream | **Adopt** with Vue 3.5.42. |
| Admin build | Vite 8 | https://github.com/vitejs/vite | Alternative | MIT | Official upstream | **Reject**; not the approved target. |
| Vue tests | Vitest | https://github.com/vitest-dev/vitest | 4.1.10 | MIT | npm metadata verified 2026-09-01; official upstream | **Adopt**. Peer metadata accepts Vite 7 and requires Node 20+. |
| Vue tests | Jest | https://github.com/jestjs/jest | Alternative | MIT | Official upstream | **Reject**; not aligned with the approved Vite/Vitest toolchain. |
| Vue tests | Bare Vue Test Utils | https://github.com/vuejs/test-utils | 2.5.0 | MIT | npm metadata verified 2026-09-01; official upstream | **Adopt alongside Vitest**, not as the test runner. |
| Browser E2E | Playwright | https://github.com/microsoft/playwright | @playwright/test 1.62.1 | Apache-2.0 | npm metadata verified 2026-09-01; official upstream | **Adopt**; Node >=20. |
| Browser E2E | Cypress | https://github.com/cypress-io/cypress | Alternative | MIT | Official upstream | **Reject**; Playwright is the approved runner. |
| Browser E2E | Selenium | https://github.com/SeleniumHQ/selenium | Alternative | Apache-2.0 | Official upstream | **Reject**; unnecessary additional browser stack. |
| uni-app | Manual HBuilderX | https://uniapp.dcloud.net.cn/quickstart-cli | Manual process | DCloud terms | Product documentation | **Reject**; not reproducible enough for the approved build path. |
| uni-app | HBuilderX CLI | https://uniapp.dcloud.net.cn/quickstart-cli | Alternative CLI | DCloud terms | Official DCloud documentation | **Reject**; approved target is the official Vue2 package line. |
| uni-app | Official Vue2 CLI packages | https://github.com/dcloudio/uni-app | @dcloudio Vue2 packages 2.0.2-5020420260813001 | Apache-2.0 (metadata) | npm metadata verified 2026-09-01; official upstream | **Adopt**; approved uni-app target. |
| UV atomicity | Redis Set/Lua | N/A (existing Redis capability) | Existing Redis/Predis | MIT for Predis | Existing runtime capability | **Reject for account UV**; Redis remains only for QR rotation. |
| UV atomicity | Database lock + unique visitor rows | N/A (application/database design) | MySQL | Database/vendor terms | Native transactional constraint | **Adopt**; atomic account UV counting and idempotency. |
| UV atomicity | Log aggregation | N/A | N/A | Varies | Operational tooling only | **Reject**; not an atomic source of truth. |
| TLS | Manual certificate | N/A | Manual operations | N/A | No automated renewal signal | **Reject**; renewal and reproducibility risk. |
| TLS | acme.sh | https://github.com/acmesh-official/acme.sh | Alternative | GPL-3.0 | Active upstream | **Reject**; not the approved certificate workflow. |
| TLS | Certbot webroot | https://github.com/certbot/certbot | Approved target | Apache-2.0 | Official EFF project/upstream | **Adopt**; webroot issuance and renewal. |

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
