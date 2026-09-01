# License inventory and release gate

Inventory baseline: 2026-09-01, commit `5e20731`. This is a documentation
record; it does not alter dependency files or install packages.

## Repository terms

The repository root `LICENSE` is Apache License 2.0. `serve/composer.json`
currently declares `MIT`, which is inconsistent with the root license. The
inconsistency is a legal/commercial release gate, separate from code
correctness. Do not delete, rewrite, or relabel upstream copyright or
attribution notices.

## Direct production dependencies

The following are the direct runtime requirements in the current manifests.
Versions are the current lock/manifest evidence; later foundation work must
refresh this inventory after lockfile changes.

### Composer (`serve/composer.json` `require`)

| Package | Locked version | License |
| --- | --- | --- |
| guzzlehttp/guzzle | 7.9.2 | MIT |
| laravel/framework | v10.48.20 | MIT |
| laravel/sanctum | v3.3.3 | MIT |
| laravel/tinker | v2.9.0 | MIT |
| mews/captcha | 3.4.3 | MIT |
| overtrue/easy-sms | 3.0.1 | MIT |
| predis/predis | v2.2.2 | MIT |
| w7corp/easywechat | 6.15.2 | MIT |
| wyzheng/ugly-base | v0.0.3 | MIT |

PHP and `ext-*` requirements are platform licenses, not Composer packages.
The read-only `composer licenses --working-dir=serve --format=json` command
also enumerated all locked transitive and development packages. Its notable
non-MIT licenses are BSD-3-Clause (including Hamcrest, League CommonMark,
PHPUnit/Sebastian, and php-parser), Apache-2.0 (`phpoption/phpoption` and
`thenorthmemory/xml`), and dual BSD-3-Clause/GPL-2.0-only/GPL-3.0-only
metadata (`nette/schema`, `nette/utils`). The full command output is the
authoritative package-by-package inventory for this baseline.

Complete `packages` inventory (development-only `packages-dev` excluded):

| Package | Resolved | License |
|---|---|---|
| brick/math | 0.12.1 | MIT |
| carbonphp/carbon-doctrine-types | 2.1.0 | MIT |
| dflydev/dot-access-data | v3.0.3 | MIT |
| doctrine/inflector | 2.0.10 | MIT |
| doctrine/lexer | 3.0.1 | MIT |
| dragonmantank/cron-expression | v3.3.3 | MIT |
| egulias/email-validator | 4.0.2 | MIT |
| fruitcake/php-cors | v1.3.0 | MIT |
| graham-campbell/result-type | v1.1.3 | MIT |
| guzzlehttp/guzzle | 7.9.2 | MIT |
| guzzlehttp/promises | 2.0.3 | MIT |
| guzzlehttp/psr7 | 2.7.0 | MIT |
| guzzlehttp/uri-template | v1.0.3 | MIT |
| intervention/gif | 4.1.0 | MIT |
| intervention/image | 3.8.0 | MIT |
| laravel/framework | v10.48.20 | MIT |
| laravel/prompts | v0.1.25 | MIT |
| laravel/sanctum | v3.3.3 | MIT |
| laravel/serializable-closure | v1.3.4 | MIT |
| laravel/tinker | v2.9.0 | MIT |
| league/commonmark | 2.5.3 | BSD-3-Clause |
| league/config | v1.2.0 | BSD-3-Clause |
| league/flysystem | 3.28.0 | MIT |
| league/flysystem-local | 3.28.0 | MIT |
| league/mime-type-detection | 1.15.0 | MIT |
| mews/captcha | 3.4.3 | MIT |
| monolog/monolog | 3.7.0 | MIT |
| nesbot/carbon | 2.72.5 | MIT |
| nette/schema | v1.3.0 | BSD-3-Clause; GPL-2.0-only; GPL-3.0-only |
| nette/utils | v4.0.5 | BSD-3-Clause; GPL-2.0-only; GPL-3.0-only |
| nikic/php-parser | v5.1.0 | BSD-3-Clause |
| nunomaduro/termwind | v1.15.1 | MIT |
| nyholm/psr7 | 1.8.2 | MIT |
| nyholm/psr7-server | 1.1.0 | MIT |
| overtrue/easy-sms | 3.0.1 | MIT |
| overtrue/socialite | 4.11.1 | MIT |
| phpoption/phpoption | 1.9.3 | Apache-2.0 |
| predis/predis | v2.2.2 | MIT |
| psr/cache | 3.0.0 | MIT |
| psr/clock | 1.0.0 | MIT |
| psr/container | 2.0.2 | MIT |
| psr/event-dispatcher | 1.0.0 | MIT |
| psr/http-client | 1.0.3 | MIT |
| psr/http-factory | 1.1.0 | MIT |
| psr/http-message | 2.0 | MIT |
| psr/log | 3.0.1 | MIT |
| psr/simple-cache | 3.0.0 | MIT |
| psy/psysh | v0.12.4 | MIT |
| ralouphie/getallheaders | 3.0.3 | MIT |
| ramsey/collection | 2.0.0 | MIT |
| ramsey/uuid | 4.7.6 | MIT |
| symfony/cache | v6.4.11 | MIT |
| symfony/cache-contracts | v3.5.0 | MIT |
| symfony/console | v6.4.11 | MIT |
| symfony/css-selector | v6.4.8 | MIT |
| symfony/deprecation-contracts | v3.5.0 | MIT |
| symfony/error-handler | v6.4.10 | MIT |
| symfony/event-dispatcher | v6.4.8 | MIT |
| symfony/event-dispatcher-contracts | v3.5.0 | MIT |
| symfony/finder | v6.4.11 | MIT |
| symfony/http-client | v6.4.11 | MIT |
| symfony/http-client-contracts | v3.5.0 | MIT |
| symfony/http-foundation | v6.4.10 | MIT |
| symfony/http-kernel | v6.4.11 | MIT |
| symfony/mailer | v6.4.9 | MIT |
| symfony/mime | v6.4.11 | MIT |
| symfony/polyfill-ctype | v1.31.0 | MIT |
| symfony/polyfill-intl-grapheme | v1.31.0 | MIT |
| symfony/polyfill-intl-idn | v1.31.0 | MIT |
| symfony/polyfill-intl-normalizer | v1.31.0 | MIT |
| symfony/polyfill-mbstring | v1.31.0 | MIT |
| symfony/polyfill-php80 | v1.31.0 | MIT |
| symfony/polyfill-php81 | v1.31.0 | MIT |
| symfony/polyfill-php83 | v1.31.0 | MIT |
| symfony/polyfill-uuid | v1.31.0 | MIT |
| symfony/process | v6.4.8 | MIT |
| symfony/psr-http-message-bridge | v6.4.11 | MIT |
| symfony/routing | v6.4.11 | MIT |
| symfony/service-contracts | v3.5.0 | MIT |
| symfony/string | v6.4.11 | MIT |
| symfony/translation | v6.4.10 | MIT |
| symfony/translation-contracts | v3.5.0 | MIT |
| symfony/uid | v6.4.11 | MIT |
| symfony/var-dumper | v6.4.11 | MIT |
| symfony/var-exporter | v6.4.9 | MIT |
| symfony/yaml | v6.4.11 | MIT |
| thenorthmemory/xml | 1.1.1 | Apache-2.0 |
| tijsverkoyen/css-to-inline-styles | v2.2.7 | BSD-3-Clause |
| vlucas/phpdotenv | v5.6.1 | BSD-3-Clause |
| voku/portable-ascii | 2.0.1 | MIT |
| w7corp/easywechat | 6.15.2 | MIT |
| webmozart/assert | 1.11.0 | MIT |
| wyzheng/ugly-base | v0.0.3 | MIT |

### npm (`admin/package.json` `dependencies`)

`admin/pnpm-lock.yaml` exists (lockfileVersion 9.0). Resolved versions below
come from its root importer, including peer suffixes where present.

| Package | Manifest range | Resolved lock version | License |
| --- | --- | --- | --- |
| @amap/amap-jsapi-loader | ^1.0.1 | 1.0.1 | MIT |
| @element-plus/icons-vue | ^2.1.0 | 2.1.0 (vue 3.3.4) | MIT |
| @vueuse/core | ^10.9.0 | 10.9.0 (vue 3.3.4) | MIT |
| @wangeditor/editor | ^5.1.23 | 5.1.23 | MIT |
| @wangeditor/editor-for-vue | ^5.1.12 | 5.1.12 (editor 5.1.23, vue 3.3.4) | MIT |
| animate.css | ^4.1.1 | 4.1.1 | MIT |
| axios | ^1.6.0 | 1.6.0 | MIT |
| echarts | ^5.4.3 | 5.4.3 | Apache-2.0 |
| element-plus | ^2.3.8 | 2.3.8 (vue 3.3.4) | MIT |
| jsqr | ^1.4.0 | 1.4.0 | Apache-2.0 |
| nprogress | ^0.2.0 | 0.2.0 | MIT |
| pinia | ^2.1.3 | 2.1.3 (typescript 5.0.4, vue 3.3.4) | MIT |
| pinia-plugin-persistedstate | ^3.2.0 | 3.2.0 (pinia 2.1.3) | MIT |
| qrcode.vue | ^3.4.1 | 3.4.1 (vue 3.3.4) | MIT |
| qs | ^6.11.2 | 6.11.2 | BSD-3-Clause |
| sortablejs | ^1.15.0 | 1.15.0 | MIT |
| vue | ^3.3.4 | 3.3.4 | MIT |
| vue-cropper | ^1.1.4 | 1.1.4 | ISC |
| vue-router | ^4.2.2 | 4.2.2 (vue 3.3.4) | MIT |

`mini_programs/package.json` declares no npm runtime dependencies. The vendored
`mini_programs/uview-ui` metadata is MIT; its node-sass/sass-loader entries are
development-only. npm license values above were read from the registry on
2026-09-01 without installing packages.

## NOTICE and release decision

Apache-2.0 redistribution requires retaining the license and applicable
copyright, patent, trademark, attribution, and NOTICE text. The release
artifact must ship a readable third-party NOTICE (or equivalent documentation
location) for Apache-licensed components and must preserve upstream notices;
the absence of a root `NOTICE` file in this baseline is not permission to
remove those notices.

**Release decision: 外部阻塞.** Resolve the root Apache-2.0 versus
`serve/composer.json` MIT metadata mismatch with the repository owner before
commercial launch. Re-run the complete license inventory after the approved
dependency/lockfile changes. This gate is independent of functional test
results.

## Commands and results

Required npm metadata commands all returned the requested approved version and
license values. `composer validate --working-dir=serve --no-check-publish`
returned a valid JSON manifest plus the known lock mismatch; it did not modify
files. `composer licenses --working-dir=serve --format=json` returned the
package/license map described above.
