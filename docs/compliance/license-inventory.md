# License inventory and release gate

Inventory refreshed 2026-09-01 from `serve/composer.lock` at foundation
commit `7878e3235535108c3916c14bd473720488cfce77` and the direct production
manifests. This is evidence documentation; it does not install or alter
dependencies.

## Repository terms

The root `LICENSE` is Apache License 2.0. `serve/composer.json` declares `MIT`.
That metadata mismatch is an external legal/commercial gate, independent of
functional correctness. Do not delete, rewrite, or relabel upstream copyright,
license, attribution, or NOTICE text.

## Direct production dependencies

The direct Composer requirements and the versions resolved by the final lock
are:

| Package | Constraint | Locked version | License |
| --- | --- | --- | --- |
| guzzlehttp/guzzle | ^7.15.2 | 7.15.5 | MIT |
| gregwar/captcha | ^2.1.1 | v2.1.1 | MIT |
| laravel/framework | ^13.0 | v13.29.0 | MIT |
| laravel/sanctum | ^4.3 | v4.3.3 | MIT |
| laravel/tinker | ^3.0 | v3.0.2 | MIT |
| overtrue/easy-sms | ^3.2.1 | 3.2.1 | MIT |
| predis/predis | ^2.2 | v2.4.1 | MIT |
| w7corp/easywechat | ^6.15 | v6.20.0 | MIT |
| wyzheng/ugly-base | ^0.0.3 | v0.0.3 | MIT |

PHP and `ext-*` requirements are platform requirements, not Composer package
licenses. The development-only direct tools are `fakerphp/faker` v1.24.1
(MIT), `laravel/pint` v1.30.5 (MIT), `mockery/mockery` v1.6.15 (BSD-3-Clause),
`nunomaduro/collision` v8.9.5 (MIT), and `phpunit/phpunit` v12.5.34
(BSD-3-Clause). They are not production runtime dependencies.

## Composer lock inventory

The final lock contains 95 production packages. License values below are the
package metadata recorded in the lock; the complete command output remains
reproducible with `composer licenses --working-dir=serve --format=json`.

| Package | Resolved | License |
| --- | --- | --- |
| brick/math | 0.18.0 | MIT |
| carbonphp/carbon-doctrine-types | 3.2.0 | MIT |
| dflydev/dot-access-data | v3.0.3 | MIT |
| doctrine/inflector | 2.1.0 | MIT |
| doctrine/lexer | 3.0.1 | MIT |
| dragonmantank/cron-expression | v3.6.0 | MIT |
| egulias/email-validator | 4.0.4 | MIT |
| fruitcake/php-cors | v1.4.0 | MIT |
| graham-campbell/result-type | v1.2.0 | MIT |
| gregwar/captcha | v2.1.1 | MIT |
| guzzlehttp/guzzle | 7.15.5 | MIT |
| guzzlehttp/promises | 2.5.3 | MIT |
| guzzlehttp/psr7 | 2.13.1 | MIT |
| guzzlehttp/uri-template | v2.0.1 | MIT |
| laravel/framework | v13.29.0 | MIT |
| laravel/prompts | v0.3.24 | MIT |
| laravel/sanctum | v4.3.3 | MIT |
| laravel/serializable-closure | v2.0.16 | MIT |
| laravel/tinker | v3.0.2 | MIT |
| league/commonmark | 2.10.0 | BSD-3-Clause |
| league/config | v1.2.0 | BSD-3-Clause |
| league/flysystem | 3.35.3 | MIT |
| league/flysystem-local | 3.35.3 | MIT |
| league/mime-type-detection | 1.17.0 | MIT |
| league/uri | 7.8.1 | MIT |
| league/uri-interfaces | 7.8.1 | MIT |
| monolog/monolog | 3.10.0 | MIT |
| nesbot/carbon | 3.13.2 | MIT |
| nette/schema | v1.3.6 | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| nette/utils | v4.1.5 | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| nikic/php-parser | v5.8.0 | BSD-3-Clause |
| nunomaduro/termwind | v2.4.0 | MIT |
| nyholm/psr7 | 1.8.2 | MIT |
| nyholm/psr7-server | 1.1.0 | MIT |
| overtrue/easy-sms | 3.2.1 | MIT |
| overtrue/socialite | 4.15.0 | MIT |
| phpoption/phpoption | 1.10.0 | Apache-2.0 |
| predis/predis | v2.4.1 | MIT |
| psr/cache | 3.0.0 | MIT |
| psr/clock | 1.0.0 | MIT |
| psr/container | 2.0.2 | MIT |
| psr/event-dispatcher | 1.0.0 | MIT |
| psr/http-client | 1.0.3 | MIT |
| psr/http-factory | 1.1.0 | MIT |
| psr/http-message | 2.0 | MIT |
| psr/log | 3.0.2 | MIT |
| psr/simple-cache | 3.0.0 | MIT |
| psy/psysh | v0.12.24 | MIT |
| ralouphie/getallheaders | 3.0.3 | MIT |
| ramsey/collection | 2.1.1 | MIT |
| ramsey/uuid | 4.9.3 | MIT |
| symfony/cache | v7.4.18 | MIT |
| symfony/cache-contracts | v3.7.1 | MIT |
| symfony/clock | v7.4.8 | MIT |
| symfony/console | v7.4.18 | MIT |
| symfony/css-selector | v7.4.18 | MIT |
| symfony/deprecation-contracts | v3.7.1 | MIT |
| symfony/error-handler | v7.4.17 | MIT |
| symfony/event-dispatcher | v7.4.17 | MIT |
| symfony/event-dispatcher-contracts | v3.7.1 | MIT |
| symfony/finder | v7.4.17 | MIT |
| symfony/http-client | v7.4.18 | MIT |
| symfony/http-client-contracts | v3.7.3 | MIT |
| symfony/http-foundation | v7.4.18 | MIT |
| symfony/http-kernel | v7.4.18 | MIT |
| symfony/mailer | v7.4.17 | MIT |
| symfony/mime | v7.4.18 | MIT |
| symfony/polyfill-ctype | v1.37.0 | MIT |
| symfony/polyfill-intl-grapheme | v1.41.0 | MIT |
| symfony/polyfill-intl-idn | v1.42.0 | MIT |
| symfony/polyfill-intl-normalizer | v1.42.0 | MIT |
| symfony/polyfill-mbstring | v1.38.2 | MIT |
| symfony/polyfill-php80 | v1.37.0 | MIT |
| symfony/polyfill-php81 | v1.38.1 | MIT |
| symfony/polyfill-php83 | v1.41.0 | MIT |
| symfony/polyfill-php84 | v1.38.1 | MIT |
| symfony/polyfill-php85 | v1.41.0 | MIT |
| symfony/polyfill-php86 | v1.41.0 | MIT |
| symfony/polyfill-uuid | v1.37.0 | MIT |
| symfony/process | v7.4.18 | MIT |
| symfony/psr-http-message-bridge | v7.4.8 | MIT |
| symfony/routing | v7.4.18 | MIT |
| symfony/service-contracts | v3.7.3 | MIT |
| symfony/string | v7.4.15 | MIT |
| symfony/translation | v7.4.17 | MIT |
| symfony/translation-contracts | v3.7.1 | MIT |
| symfony/uid | v7.4.17 | MIT |
| symfony/var-dumper | v7.4.18 | MIT |
| symfony/var-exporter | v7.4.18 | MIT |
| thenorthmemory/xml | 1.1.1 | Apache-2.0 |
| tijsverkoyen/css-to-inline-styles | v2.4.0 | BSD-3-Clause |
| vlucas/phpdotenv | v5.7.0 | BSD-3-Clause |
| voku/portable-ascii | 2.1.1 | MIT |
| w7corp/easywechat | 6.20.0 | MIT |
| wyzheng/ugly-base | v0.0.3 | MIT |

The lock's non-MIT obligations are BSD-3-Clause, Apache-2.0, and the dual
BSD/GPL metadata recorded above. Review each package's distributed notice
before redistribution; this table is not a replacement for required notices.

## npm direct production dependencies

`admin/package.json` and `admin/pnpm-lock.yaml` are the source for this table.
The mini-program manifest declares no npm runtime dependencies.

| Package | Manifest | Resolved | License |
| --- | --- | --- | --- |
| @amap/amap-jsapi-loader | ^1.0.1 | 1.0.1 | MIT |
| @element-plus/icons-vue | ^2.1.0 | 2.1.0 | MIT |
| @vueuse/core | ^10.9.0 | 10.9.0 | MIT |
| @wangeditor/editor | ^5.1.23 | 5.1.23 | MIT |
| @wangeditor/editor-for-vue | ^5.1.12 | 5.1.12 | MIT |
| animate.css | ^4.1.1 | 4.1.1 | MIT |
| axios | ^1.6.0 | 1.6.0 | MIT |
| echarts | ^5.4.3 | 5.4.3 | Apache-2.0 |
| element-plus | ^2.3.8 | 2.3.8 | MIT |
| jsqr | ^1.4.0 | 1.4.0 | Apache-2.0 |
| nprogress | ^0.2.0 | 0.2.0 | MIT |
| pinia | ^2.1.3 | 2.1.3 | MIT |
| pinia-plugin-persistedstate | ^3.2.0 | 3.2.0 | MIT |
| qrcode.vue | ^3.4.1 | 3.4.1 | MIT |
| qs | ^6.11.2 | 6.11.2 | BSD-3-Clause |
| sortablejs | ^1.15.0 | 1.15.0 | MIT |
| vue | ^3.3.4 | 3.3.4 | MIT |
| vue-cropper | ^1.1.4 | 1.1.4 | ISC |
| vue-router | ^4.2.2 | 4.2.2 | MIT |

## NOTICE and release decision

Apache-2.0 redistribution requires retaining applicable copyright, patent,
trademark, attribution, license, and NOTICE text. The repository currently
has no root `NOTICE` file; that absence is not permission to remove upstream
notices. The release artifact must ship a readable third-party NOTICE or an
equivalent documented location.

**Release decision: 外部阻塞.** The root Apache-2.0 versus
`serve/composer.json` MIT metadata mismatch requires an owner-approved legal
decision before commercial launch. Re-run this inventory after that decision
or any dependency/lockfile change. This gate is independent of code and
database test results.

## Reproduction commands

```bash
composer validate --working-dir=serve --strict --check-lock
composer licenses --working-dir=serve --format=json
```

The inventory records package metadata from the final Laravel 13 lock and the
direct production manifests. It does not claim DNS, server, external-provider,
or real-device acceptance.
