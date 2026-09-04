# 企业微信客诉受理 SaaS 接入设计

日期：2026-09-04
状态：实现完成，待外部部署验收
代码基线：`codex/full-function-implementation@c8c064926af7d5d32c958306ca3f2be68a4021c1`
任务分支：`codex/wecom-feedback-saas`

## 1. 背景与裁决

参考产品把企业微信成员对外资料中的自定义网页字段命名为“反馈”，再将客户带到第三方表单。可借鉴的只有“自有反馈入口、工单记录、企微机器人通知”三段工程能力；不得仿冒企业微信官方投诉页，不得隐藏或替代官方投诉入口，也不得承诺“防投诉”“降低封号率”或通过域名轮换规避平台治理。

本项目在现有链接 SaaS 内新增独立的“客诉受理”子系统。它复用现有用户、会员、域名、认证和基础设施，但不新增 `LinkType`，也不把工单提交塞进 `LinkResolutionCoordinator`。原因是现有 `Link` 表达“把访客解析到目标地址”，而客诉渠道表达“接收并持续处理一份业务记录”，生命周期和权限模型不同。

## 2. 目标与成功标准

首版同时满足以下条件才算完成：

1. 登录用户可创建客诉渠道，从现有启用域名中选择一个域名，并得到固定分享地址 `https://{host}/f/{code}`。
2. 客户可在移动端提交问题分类、问题说明、可选联系方式和最多 3 张图片；页面明确显示运营主体和“商家售后反馈”属性。
3. 提交成功后生成不可枚举的公开工单号；刷新或网络重试不会重复创建工单。
4. 工单按 `user_id` 严格隔离，普通租户不能读取、修改或下载其他租户的工单和附件。
5. 工单创建后异步通知配置的企业微信群机器人；通知失败不影响客户提交，并按有限次数重试。
6. 管理后台支持渠道管理、工单列表/详情以及“待处理、处理中、已解决、已关闭”状态流转，并记录操作者和时间。
7. Webhook、联系方式和问题正文不以明文长期存储；Webhook 不通过列表、详情、日志或错误响应回显。
8. 附件不提供公共静态 URL，只能由有权限的登录用户经受控下载接口读取。
9. 完成后通过后端单元/功能测试、前端类型检查/构建、浏览器移动端 E2E、密钥扫描和 `git diff --check`。
10. 生产发布、企业微信后台设置和真实群通知分别验收；代码测试或 HTTP 200 不能替代这些外部验收。

## 3. 范围

### 3.1 首版纳入

- 多租户客诉渠道创建、读取、编辑、停用和固定分享地址；首版不物理删除渠道。
- 结构化品牌配置：运营主体、页面标题、简介、客服电话、处理时效说明。
- 默认问题分类：售前承诺、订单履约、退款售后、服务态度、产品问题、其他；租户可改名称和顺序，但不能注入 HTML/JavaScript。
- 客户提交、幂等、防刷、附件上传、隐私确认和提交结果页。
- 工单后台、状态流转、内部备注和审计事件。
- 企业微信群机器人通知、测试通知、失败重试和通知状态。
- 保存期限 30–365 天可选，默认 180 天；到期清理任务先删除附件，再删除业务正文和联系方式，只保留最小匿名审计计数。
- 现有域名池选择、域名可用性校验和不可用提示。
- 在我方企业微信中完成一条真实“售后反馈”字段接入验收。

### 3.2 明确排除

- 仿制企业微信官方投诉界面、变体字“举报/投诉”、隐藏官方投诉入口或其他误导设计。
- 自动修改客户企业微信后台、批量写成员资料、CorpSecret/OAuth 授权和服务商代开发模式。
- 自动域名轮换、封禁探测后换域名、审核后换内容。
- 在线支付、独享域名售卖、按工单计费和代理佣金；现有会员权益只作为访问资格来源。
- 邮件、短信、钉钉、飞书通知；首版只接企业微信群机器人。
- 客户登录查询进度、公开回复对话、AI 自动裁决、自动退款或自动删除负面反馈。
- 批量导出包含联系方式或正文的工单数据。

## 4. 方案比较与选择

### 方案 A：Laravel 13 内独立客诉模块（采用）

新增 `FeedbackChannel`、`FeedbackTicket`、`FeedbackAttachment`、`FeedbackEvent` 和 `FeedbackDelivery` 边界，复用现有 `User`、`Domain`、认证、队列和分享域名策略。优点是租户、域名、会员和部署只有一套事实来源；代价是需要完整迁移、权限、附件和通知设计。

### 方案 B：新增普通链接类型（拒绝）

在 `links.type` 增加客诉类型，开发入口较快，但会让“目标解析”和“业务工单”共用同一生命周期，破坏 `LinkResolutionCoordinator` 的单一职责，后续状态、额度和缓存语义会互相污染。

### 方案 C：接入找回咩 Python 客服后台（拒绝）

适合单企业快速自用，但现有后台是单体、单租户业务系统，会重复实现平台账号、会员和域名管理，也无法自然支持平台客户隔离。

## 5. GitHub 复用检索

检索只使用“Laravel helpdesk / customer feedback ticket”等抽象需求，没有提交私有源码、域名、凭据或客户数据。

| 候选 | 版本或 commit | 许可证 | 维护与安全信号 | 结论 |
| --- | --- | --- | --- | --- |
| [FreeScout](https://github.com/freescout-help-desk/freescout) | `dist@3b471b17cfc9aa3f7047241cb34ab91eb1a790c9`（2026-09-04） | AGPL-3.0 | 活跃、4.5k+ stars；仍基于 Laravel 5.5，仓库公开安全公告包含近期高危 SSRF、权限与附件问题 | 不复制、不引入；强 copyleft、旧框架和攻击面均不适合本项目。只把“工单状态、事件历史”当概念参考。 |
| [Faveo Helpdesk](https://github.com/faveosuite/faveo-helpdesk) | `development@6568aa45f89b78028b05cddfb2d37c171d2fbab1` | OSL-3.0 | 仓库仍活跃，但默认分支头较旧；Laravel 9 且包含多项 `dev-*` 依赖 | 不复制、不引入；许可证、版本和供应链复杂度不匹配。 |
| [ruswan/helpdesk-laravel](https://github.com/ruswan/helpdesk-laravel) | `main@17179825f3663651452c3fc664a5de3310899d6d` | MIT | Laravel 12 + Filament 3，104 stars；默认分支头为 2025-08-04，未发现仓库级公开安全公告，但这不等于无风险 | 不直接采用；技术版本接近，但 Filament/Livewire 与现有 Vue 管理端重复。只参考简单状态和角色组织。 |

裁决：不新增工单系统生产依赖。使用 Laravel 13 自带的验证、加密 cast、Filesystem、HTTP Client、队列、限流和数据库事务；这与现有技术栈兼容，供应链和长期维护成本最低。

## 6. 架构与职责

### 6.1 业务边界

- `FeedbackChannelService`：创建/更新渠道、校验租户权益、域名和结构化页面配置。
- `FeedbackShareUrl`：生成 `https://{host}/f/{code}`。它与现有 `LinkShareUrl` 共用抽出的 `ShareOriginPolicy`，不复制 URL 解析逻辑。
- `FeedbackSubmissionService`：在事务中完成渠道门禁、幂等、工单和附件元数据落库，并在提交后派发通知。
- `FeedbackTicketPolicy`：统一租户读取、状态流转、内部备注和附件下载授权。
- `FeedbackAttachmentService`：校验真实 MIME、尺寸和哈希，保存到私有磁盘，并处理失败回滚和到期清理。
- `WeComWebhookPolicy`：只接受 `https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...`，禁止 userinfo、非 443 端口、重定向和其他主机/路径。
- `SendFeedbackNotification`：从 outbox/交付记录异步发送最小化通知并记录结果。
- `PurgeExpiredFeedback`：按渠道保存期限清理附件和个人信息。

公开页面和 API 不调用 `LinkResolutionCoordinator`，但复用服务器签发的访客身份和 HMAC 哈希能力；客户提交不消耗普通跳转 UV，避免一份工单同时污染获客统计。

### 6.2 数据模型

#### `feedback_channels`

- `id`, `user_id`, `domain_id`, `code`, `status`
- `name`, `operator_name`, `intro`, `service_phone`, `sla_text`
- `categories`（结构化 JSON，仅字符串/排序）
- `contact_required`, `retention_days`
- `webhook_url`（Laravel encrypted cast，`TEXT NULL`）
- `webhook_configured_at`, timestamps

`code` 使用高熵随机值并全局唯一；`user_id + name` 不强制唯一。渠道固定绑定一个启用域名。域名被停用时，后台显示“域名不可用”，不自动把既有链接切到另一个域名。

#### `feedback_tickets`

- `id`, `user_id`, `feedback_channel_id`, `public_no`
- `category`, `status`
- `contact`、`content`（encrypted casts）
- `visitor_hash`, `submitted_at`, `resolved_at`
- `idempotency_key`，唯一约束 `(feedback_channel_id, idempotency_key)`
- timestamps

列表默认只显示工单号、分类、状态、渠道和时间；联系方式只在授权详情页解密展示。正文只按纯文本渲染，不允许富文本。

#### `feedback_attachments`

- `id`, `user_id`, `feedback_ticket_id`
- `disk`, `path`, `original_name`（encrypted cast）, `mime`, `size`, `sha256`
- timestamps

附件保存到私有磁盘。下载接口先做租户授权，再以流式响应返回；数据库路径不得直接拼接到公共 URL。

#### `feedback_events`

- `feedback_ticket_id`, `user_id`, `actor_user_id`
- `event`, `from_status`, `to_status`, `note`（encrypted）
- `created_at`

#### `feedback_deliveries`

- `feedback_channel_id`, `feedback_ticket_id`（测试通知时可空）, `user_id`
- `kind`（`ticket` / `test`）, `channel`, `status`
- `attempts`, `next_attempt_at`, `last_error_code`, `sent_at`
- 唯一 `idempotency_key`

不得保存完整 Webhook、完整请求体或客户正文副本。

## 7. HTTP 与页面契约

### 7.1 登录接口

- `GET /api/feedback-channels`
- `POST /api/feedback-channels`
- `GET /api/feedback-channels/{id}`
- `PUT /api/feedback-channels/{id}`
- `PATCH /api/feedback-channels/{id}/status`
- `POST /api/feedback-channels/{id}/test-notification`
- `GET /api/feedback-tickets`
- `GET /api/feedback-tickets/{id}`
- `PATCH /api/feedback-tickets/{id}/status`
- `POST /api/feedback-tickets/{id}/notes`
- `GET /api/feedback-attachments/{id}/download`

所有普通资源接口（包括管理员调用）都只返回当前登录账号自己的 `user_id` 数据。首版不提供跨租户接口；未来如增加管理员跨租户访问，必须独立经过管理员中间件并写审计事件。Webhook 更新为 write-only：详情仅返回 `webhook_configured: true|false`。

### 7.2 公开接口

- `GET /f/{code}`：服务端 Blade 渲染固定模板和 CSRF token。
- `POST /f/{code}/tickets`：multipart 提交；返回公开工单号，不返回内部 ID。

表单要求：问题分类、10–2000 字纯文本说明、隐私确认；联系方式默认可选。附件最多 3 张，每张最多 5 MiB，只接受经内容嗅探确认的 JPEG/PNG/WebP，重编码或拒绝带主动内容的格式，不接受 SVG、HTML、PDF、压缩包和视频。

浏览器生成随机幂等键并随提交发送；服务端唯一约束是最终事实来源。提交成功页只显示工单号、运营主体和处理时效，不透露后台地址。

## 8. 安全、隐私与滥用控制

- 页面显著告知运营主体、处理目的、信息种类、保存期限和权利入口；勾选确认后才能提交。
- 联系方式、正文和内部备注应用层加密；密钥仅由部署环境提供。
- 原始 IP、User-Agent 不入业务表。以独立 HMAC 密钥生成短期限流键和 `visitor_hash`，30 天后清理访客哈希。
- 每个渠道默认每个访客 10 分钟最多 3 次、24 小时最多 10 次；另设渠道全局突发阈值，超限返回稳定的 429 页面。
- 所有文本按纯文本输出；后台不得使用 `v-html` 渲染客户输入。
- 上传先写临时位置，完成 MIME、尺寸、哈希和租户事务后再移动；任一步失败都删除临时文件。
- Webhook 精确 allowlist、禁重定向、短连接/响应超时；错误日志只记稳定错误码和请求 ID。
- 测试通知必须由用户显式点击，内容带“测试”标识；保存 Webhook 本身不发送消息。
- 渠道删除首版采用停用加延迟清理，不立即物理删除仍在保存期内的工单。

## 9. 通知与错误处理

创建工单的数据库事务成功后写 `feedback_deliveries` 并派发队列任务。机器人通知只包含运营主体、工单号、分类、提交时间和后台工单链接，不包含联系方式、完整正文或附件。

通知使用 Laravel HTTP Client，连接/响应超时，禁止跟随重定向；最多 5 次指数退避。最终失败时工单仍是“已受理”，后台显示“通知失败”，租户可手动重试。队列不可用时 outbox 记录保留，恢复后可重放，不在客户页面显示内部异常。

域名被停用、会员失效或渠道停用时，公开页返回无内部细节的稳定页面；既有渠道不自动切换域名。附件保存失败则整笔提交回滚，不产生半工单。重复幂等键返回首次创建的公开工单号。

允许的状态流转：`pending -> processing -> resolved -> closed`；`resolved -> processing` 可重新打开。其他流转返回 422。每次变化都在同一事务写事件记录。

## 10. 管理端设计

新增一级菜单“客诉受理”，包含：

- 渠道：创建、编辑、启停、选择域名、复制链接、Webhook 配置/测试。
- 工单：按状态、渠道、分类和时间筛选；列表不展示完整联系方式和正文。
- 工单详情：正文、附件、内部备注、通知状态和状态流转。

管理端继续使用现有 Vue 3、Element Plus、API 封装和认证状态，不引入 Filament/Livewire 或第二套后台框架。创建渠道后必须通过详情接口回读服务端生成的分享地址，前端不能自行拼域名和短码。

## 11. 企业微信接入

首版采用人工配置，避免申请不必要的通讯录权限：

1. 在平台创建渠道并得到分享链接。
2. 企业管理员在“成员对外资料显示”新增网页类型字段“售后反馈”。
3. 对需要展示的成员填写生成链接。
4. 在指定测试群创建企业微信群机器人，把 Webhook 直接录入平台；不得把 Webhook 发到聊天、任务单或 Git。

我方真实验收时，用户已授权 Codex 操作企业微信。若管理后台要求重新登录，只由用户扫码；Codex 不索取或保存密码。配置动作必须在代码部署和链接验证之后执行，每次保存后重新读取页面状态，并用另一微信账号验证字段可见和链接可打开。

## 12. 测试与验收

### 12.1 自动化测试

- 单元：分享域名策略、Webhook allowlist、状态机、保存期限、附件策略、通知最小化负载。
- 功能：渠道 CRUD、公开提交、幂等、租户隔离、越权附件、密钥不回显、XSS 文本、无效 MIME、超限、通知重试。
- 数据库：空库迁移/Seeder、旧数据向前迁移、唯一约束和回滚。
- 前端：类型检查、生产构建、渠道创建和工单处理组件测试。
- E2E：390 px/桌面浏览器创建渠道、复制链接、提交工单、后台处理；检查零横向溢出和可触达控件。
- 安全：Composer/npm audit、凭据/大文件扫描、`git diff --check`。

### 12.2 外部验收梯度

1. 本地自动化通过。
2. 测试构建产物与提交 hash 对应。
3. 测试服务器迁移、队列、私有存储和域名 HTTPS 正常。
4. 公网客诉页完成一次真实提交，后台可见且附件授权正确。
5. 指定企微测试群收到最小化通知。
6. 企业微信成员资料展示“售后反馈”，另一微信账号可打开并提交。
7. 用户确认后才部署生产并重复 3–6。

## 13. 发布、回滚与运营配合

生产发布前必须再次取得用户确认。发布步骤必须包含数据库和上传目录备份、迁移 dry-run/测试环境演练、队列 worker 与定时清理任务配置、域名 DNS/证书/`ALLOWED_SHARE_HOSTS` 核验，以及旧版本应用回滚方案。

用户需要配合：管理后台失效时扫码一次；指定一个测试群；最终用另一个微信账号做真实验收。其余代码、测试、Git、服务器部署准备和企业微信页面操作由 Codex 完成。

## 14. 已知外部门

- 仓库根 Apache-2.0 与 `serve/composer.json` MIT 元数据不一致，商业发布前仍需所有者作许可证决策；本功能不能绕过该发布门。
- 域名池只证明数据库有启用记录，不证明 DNS、HTTPS、Nginx、队列和私有存储当前可用；发布前逐域名实测。
- 企业微信群机器人 Webhook 属凭据，首次真实通知后如曾出现在截图、聊天或日志中必须轮换。
