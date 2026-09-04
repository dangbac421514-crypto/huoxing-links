# 商家客诉受理运营手册

本文说明商家客诉受理在已通过本地/测试验收之后的运行要求。它不替代部署批准，也不证明 DNS、证书、队列或企业微信已可用。

生产变更必须使用受控 `APP_ENV_FILE` 与既有发布流程。禁止把 `.env.example` 直接复制到生产，禁止在本文、聊天、工单或 Git 中粘贴 Webhook、私钥或测试密码。

## 1. 环境键

配置层级：环境变量 > 配置文件 > 代码默认值。下列键必须可覆盖。

| 键 | 用途 | 约束 |
| --- | --- | --- |
| `APP_ENV` | 运行环境 | 生产不得为 `testing` |
| `APP_KEY` | 应用加密（联系方式、正文、备注、Webhook） | 非空；轮换时同步 `APP_PREVIOUS_KEYS` |
| `APP_URL` | 应用根 URL | 与对外访问一致 |
| `PUBLIC_ORIGIN` | 规范分享源 | `https://host`，无 userinfo/query/fragment，端口缺省或 443 |
| `ALLOWED_SHARE_HOSTS` | 允许的分享主机名 | 逗号分隔，精确匹配，小写比较 |
| `APP_VISITOR_HASH_KEY` | 访客身份 HMAC | 非空 |
| `APP_VISITOR_TOKEN_KEY` | 访客 Cookie 密钥 | Base64 解码后 32 字节 |
| `APP_FEEDBACK_HASH_KEY` | 客诉限流 HMAC | 长度 ≥ 32 |
| `FEEDBACK_PRIVATE_ROOT` | 私有附件根目录 | 空则回退 `storage/app/private/feedback` |
| `QUEUE_CONNECTION` | 队列驱动 | 生产使用 `redis`（或已验收的异步驱动），不要用 `sync` 充当生产 |
| `SESSION_DRIVER` | 会话 | 生产使用持久驱动，不要用 `array` |
| `CACHE_STORE` / `CACHE_DRIVER` | 缓存/限流 | 生产使用共享缓存，不要用 `array` |

`serve/bin/test-env` 只用于隔离测试库（库名以 `_test` 结尾、Redis DB 14/15、测试前缀）。不要对生产数据库执行 `migrate:fresh`、`php artisan test` 或 `tests/Support/seed-feedback-e2e.php`。

## 2. 私有存储权限

附件只保存在 `feedback_private` 磁盘。目录必须：

- 仅应用用户可写；禁止 Web 根直接静态映射该路径。
- 不通过 Nginx/Caddy alias 对外暴露。
- 下载只走已登录租户的 `/api/feedback-attachments/{id}/download`。
- 磁盘路径、原文件名密文和 SHA-256 不得拼进公共 URL。

部署后检查私有目录存在、仅应用用户可写、且不在 `public/` 下：

```bash
php -r 'echo (getenv("FEEDBACK_PRIVATE_ROOT") ?: "storage/app/private/feedback"), PHP_EOL;'
```

权限过宽、目录不可写或被静态站点映射都视为发布阻塞。`php artisan app:secret-storage-status` 只报告受保护配置的存储格式，不代替上述目录核验。

## 3. 队列与调度

客诉通知在工单事务提交后写入 `feedback_deliveries` 再入队。队列不可用时客户提交仍成功，后台显示通知失败，恢复后可重试。Webhook 失败/重试由隔离的 `Http::fake()` 功能测试覆盖，不要用浏览器打真实企微端点做回归。

检查项：

```bash
php artisan schedule:list
php artisan queue:failed
```

调度必须包含：

- `app:vip-expired` 每小时
- `app:links-health-check` 每 10 分钟
- `app:feedback-purge` 每天 02:30（`Asia/Shanghai`）

生产必须有常驻 queue worker 与 cron/`schedule:work`。`QUEUE_CONNECTION=sync` 只能用于隔离测试。

## 4. 域名与 HTTPS

渠道绑定一个已启用域名，分享地址固定为 `https://{host}/f/{code}`。域名停用后不自动轮换。

发布前逐项核验：

1. 域名池记录已启用，URL 为 `https://host`（无路径、userinfo、非 443 端口）。
2. 主机名精确位于 `ALLOWED_SHARE_HOSTS`。
3. DNS A/AAAA 指向本发布目标。
4. 证书覆盖该主机，浏览器访问为 HTTPS。
5. 反向代理把 `/f/{code}` 交给本应用，而不是其它站点。
6. 公开页 Host 必须等于渠道选定主机，其它允许列表内的主机也返回 404。

数据库里有启用域名 ≠ DNS/证书/Nginx 已通。

## 5. Webhook 轮换

企业微信群机器人地址只接受：

`https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...`

禁止 userinfo、非 443、重定向、其它主机或路径。保存后永不回显。列表/详情只暴露是否已配置。

轮换条件：Webhook 曾出现在截图、聊天、日志、工单或备份的明文副本中；机器人被重置；通知持续失败且密钥可能泄露。

轮换步骤：

1. 在企业微信群中重置机器人并得到新地址。
2. 在客诉渠道编辑页粘贴新地址并保存；不要把新地址发到聊天或 Git。
3. 使用“测试通知”发送一条带“测试”标识、不含客户信息的消息。
4. 确认测试群收到后，旧机器人作废。

浏览器 E2E 不配置真实 Webhook。

## 6. 保存期限与清理

渠道保存期限 30–365 天，默认 180 天。到期命令：

```bash
php artisan app:feedback-purge
```

行为：先删除私有附件，再清除正文和联系方式，保留最小匿名审计计数；`visitor_hash` 在提交 30 天后单独置空。文件删除失败时保留库内个人信息以便重试，命令以非零状态退出。

调度入口是 `app:feedback-purge`，不要另写未验收的清理脚本。

## 7. 备份、恢复与回滚证据

发布前备份：

- 数据库（含加密字段；备份本身按密钥材料保管）。
- `FEEDBACK_PRIVATE_ROOT` 或默认私有附件目录。
- 当前发布 SHA 与 `APP_KEY`/`APP_PREVIOUS_KEYS` 的密钥托管记录（不把密钥写入本文）。

恢复：先停写入（queue worker / php-fpm），再还原数据库和私有目录，最后用同一 SHA 启动应用。只还原库不还原附件会导致授权下载 404。

回滚：回到已验收的上一 SHA 与对应备份。生产禁止 `migrate:fresh`。迁移失败则停止发布并保留备份。回滚证据至少包括：备份路径、SHA、操作时间、执行人、恢复后公开 GET 与一次授权下载的结果（不要附带客户正文或 Webhook）。

## 8. 本地浏览器验收命令

隔离 MySQL/Redis 就绪后，在 `admin/` 运行：

```bash
npx --yes --no-audit --package=pnpm@9.15.9 -- pnpm exec playwright test
```

该命令会通过 `serve/bin/test-env` 播种隔离库、启动 `php artisan serve`（8090）和 Vite `e2e` 模式（4174）。它只证明代码/测试库闭环，不证明生产部署或真实企微通知。

## 9. 外部验收边界

通过本手册中的本地命令、单元/功能测试和构建，只能证明代码与隔离库。下列事项仍需单独的运营证据：

- 生产 DNS、HTTPS、反向代理、PHP-FPM、MySQL/Redis
- 队列 worker 与调度实际执行
- 私有目录权限与备份可恢复
- 企业微信成员资料字段与真实群通知
- 许可证元数据决策（见 `docs/compliance/license-inventory.md`）
