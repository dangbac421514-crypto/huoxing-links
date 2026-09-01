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

### npm (`admin/package.json` `dependencies`)

| Package | Manifest version | License |
| --- | --- | --- |
| @amap/amap-jsapi-loader | ^1.0.1 | MIT |
| @element-plus/icons-vue | ^2.1.0 | MIT |
| @vueuse/core | ^10.9.0 | MIT |
| @wangeditor/editor | ^5.1.23 | MIT |
| @wangeditor/editor-for-vue | ^5.1.12 | MIT |
| animate.css | ^4.1.1 | MIT |
| axios | ^1.6.0 | MIT |
| echarts | ^5.4.3 | Apache-2.0 |
| element-plus | ^2.3.8 | MIT |
| jsqr | ^1.4.0 | Apache-2.0 |
| nprogress | ^0.2.0 | MIT |
| pinia | ^2.1.3 | MIT |
| pinia-plugin-persistedstate | ^3.2.0 | MIT |
| qrcode.vue | ^3.4.1 | MIT |
| qs | ^6.11.2 | BSD-3-Clause |
| sortablejs | ^1.15.0 | MIT |
| vue | ^3.3.4 | MIT |
| vue-cropper | ^1.1.4 | ISC |
| vue-router | ^4.2.2 | MIT |

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
