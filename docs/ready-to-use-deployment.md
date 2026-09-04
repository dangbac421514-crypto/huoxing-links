# 现有 Web 功能部署与使用

本版交付后台、六类已有链接管理、H5 二维码页面与商家客诉工单。
外部目标能否在微信/抖音客户端打开、真实机器人通知是否到达，需要实际配置后验收。
Android 安装包、小红书分类、广告回传和新获客目标池不在本版范围。

## 构建与打包

构建机需要 Node.js 20 或更新版本、pnpm 9.15.9，以及后端验证使用的 PHP 8.3+。
在仓库执行以下命令，后台固定部署在 `/web/`，API 使用同源 `/api`：

```bash
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin install --frozen-lockfile
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin run type-check
VITE_PUBLIC_PATH=/web/ npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm --dir admin run build-only
```

提交源代码及本次构建后的已跟踪 `admin/dist`，再执行：

```bash
bash deploy/package-release.sh /absolute/new/release-directory
```

输出目录必须不存在，其父目录必须已存在，且输出不能位于源码目录内。
脚本不会覆盖或删除已有目录。包内含 `serve/`、`admin/dist/`、许可证、手册、
`RELEASE` 提交号与相对路径 `MANIFEST`；不含真实 env、密钥、数据库、上传附件、
缓存、node_modules 或 Composer vendor。

把输出目录复制到目标服务器的**新版本目录**。复制后在包目录核验：

```bash
shasum -a 256 -c MANIFEST
```

Linux 没有 `shasum` 时使用 `sha256sum -c MANIFEST`。必须全部通过后再安装依赖。

## 目标服务器准备

最低运行环境为 PHP 8.3、MySQL、Redis、Nginx/PHP-FPM；锁文件的实际平台要求须通过：

```bash
cd serve
composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
composer check-platform-reqs --no-dev
```

保持已有受控 env 和 `APP_KEY`。升级禁止重新生成密钥或用示例配置覆盖 env。
新安装应由操作人员创建受控配置和数据库，首次密钥只生成一次并保存在密钥托管中。
必要配置包括：

| 配置 | 要求 |
| --- | --- |
| `APP_ENV`、`APP_DEBUG` | `production`、`false` |
| `APP_KEY` | 原有 `base64:` 加密密钥，解码为 32 字节 |
| `APP_VISITOR_HASH_KEY`、`APP_FEEDBACK_HASH_KEY` | 各自独立随机值，至少 32 字节 |
| `APP_VISITOR_TOKEN_KEY` | 独立随机值的 Base64，解码为 32 字节 |
| `APP_URL`、`PUBLIC_ORIGIN` | 一致的 HTTPS origin，例如实际站点域名，无路径 |
| `ALLOWED_SHARE_HOSTS` | 包含实际站点和允许的分享域名，逗号分隔 |
| `DB_CONNECTION` 与 `DB_*` | `mysql`，运营方为此应用准备的连接信息 |
| `QUEUE_CONNECTION`、`REDIS_*` | `redis` 与实际连接信息 |
| `CACHE_STORE`、`SESSION_DRIVER` | 持久化驱动，推荐 Redis；不能为 `array` |
| `FEEDBACK_PRIVATE_ROOT` | 共享私有附件目录；不得位于 Web 公共目录下 |

env 文件权限为 0600 或 0640；PHP-FPM/队列用户必须有读取权限。可通过
`serve/.env` 链接到受控 env，或沿用已有发布工具加载方式；不要把 dotenv 当 shell 脚本 source。

升级时把新版本的 `serve/storage` 接到原有持久存储，保留所有已上传公开素材、
私有客诉附件、会话与日志。**不要拿包内空 storage 覆盖现网 storage。**
`serve/bootstrap/cache` 为版本内可写目录。

在新版本根目录先运行纯配置预检，再以应用运行用户运行完整只读预检：

```bash
bash deploy.sh --env /absolute/path/to/controlled.env --config-only
bash deploy.sh --env /absolute/path/to/controlled.env
```

`--config-only` 明确显示连接检查被跳过；完整预检执行 MySQL `SELECT 1`、Redis `PING`
和目录可写检查，不执行迁移、清理、通知或服务切换。PHP 可通过 `PHP_BIN` 指定。
预检无法证明 TLS 证书、数据库迁移已执行、备份可恢复或 worker 正常消费。

## 经批准后的发布顺序

1. 固定本次 `RELEASE` SHA，记录现网版本与站点路径。备份数据库、持久 storage、
   私有附件及受控密钥配置；备份存储需受限，验证清单并确认可恢复。
2. 在未切换的新版本目录安装锁定 Composer 依赖，链接受控 env 与原有 storage，
   完成上述预检。Nginx 文档根目录必须是该版本的 `serve/public`。
3. 在新版本 `serve/` 执行 `php artisan migrate --force`。仅新安装需要执行
   `php artisan db:seed --force` 和交互式 `php artisan app:admin-provision`；
   升级不自动覆盖站点配置，不在生产运行测试或 `migrate:fresh`。
4. 确认 `public/storage` 链接指向共享公开素材目录；缺失时执行 `php artisan storage:link`。
   包内 `public/web` 已链接到本版本 `admin/dist`。执行 `php artisan config:cache`、
   `php artisan view:cache`。不要复制测试配置缓存。
5. 通过现有服务器发布方式切换到候选版本，仅重载此应用相关 PHP-FPM/queue 配置。
   常驻 worker 使用 `php artisan queue:work redis`，由已有进程管理器自动拉起。
   设置每分钟 `php artisan schedule:run`，验证 `schedule:list` 中已有健康检查、
   会员到期和客诉清理任务。不重启其他站点服务。
6. 完成下方验收。若失败，停止扩大流量并切回上个已验收版本；数据库结构不自动倒退。
   必须恢复数据库/附件时先停写并使用该次经校验备份，不混用旧代码和不兼容新数据。

本仓库提供预检和发布包，不擅自安装服务器服务，也不提供一个返回成功但没有实际发布的 `--apply`。

## 使用与验收

- 管理后台：`https://实际域名/web/#/login`。新管理员使用命令行创建的账号登录，
  系统先进入修改密码页，保存后进入后台。退出后访问业务页必须回到登录页。
- 域名管理：新增或检查实际启用的 HTTPS 域名，并使其处于 `ALLOWED_SHARE_HOSTS` 中；
  数据库启用不代替 DNS/证书配置。
- 链接管理：上传封面、选择对应类型和域名，填写实际外部目标，保存并复制分享地址。
  分别验证新增、编辑、复制、目标解析和删除。小程序类型仍需自己的有效 AppID/Secret
  和允许的页面；未配置的平台不能通过虚假值验收。
- 客诉受理：创建商家自己的渠道，设置运营主体、问题分类和保存期限；复制 `/f/{code}`
  地址给测试人员。提交后后台能查看工单、私有附件、内部备注并更新处理状态。
- 企微通知可选：渠道里配置实际群机器人 Webhook，使用“测试通知”确认到达。
  不配置 Webhook 不影响客户提交和后台处理；已配置后需再验证队列失败重试。
- 配置服务短暂不可用时应显示“重新加载”；登录网络失败后应保留表单且能重试。

更多私有存储、队列与保存期限说明见 [feedback-operations.md](feedback-operations.md)。
测试与残余问题见 [ready-to-use-acceptance.md](ready-to-use-acceptance.md)。
部署方式参考 [Laravel 官方 13.x 部署文档](https://github.com/laravel/docs/blob/13.x/deployment.md)。
