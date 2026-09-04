# 现有 Web 功能交付验收

## 范围与基线

2026-09-04 用户确认首版范围：后台、链接跳转、商家客诉工单可上线使用。
本轮基于 `codex/wecom-feedback-on-live@ef0bc90`，工作分支为 `codex/ready-to-use`。
不将尚未实现的小红书平台分类、获客目标池、广告回传或 Android 真机发卡算入交付。

## 已确认的交付阻断

- 新管理员的 `must_change_password` 会使 `/api/userinfo` 返回
  `PASSWORD_CHANGE_REQUIRED`，原前端将其当成登录失败，用户无法进入修改密码流程。
- 原退出只清空 localStorage，保留 sessionStorage token 和内存用户信息。
- 原 HTTP 错误处理假定 `error.response` 永远存在，断网时再次抛错。
- 原启动等待 `/api/config`，请求失败时没有可操作的重试界面。
- 二维码解析测试使用固定到期日，跨日期后测试素材正确过期而使测试失败。
- 原生产依赖包含已知安全公告，需要兼容升级并重新构建验证。
- 原 `deploy.sh` 使用 PHP 8.1 与 Yarn，且包含无备份初始化动作，与当前运行时不符。

## 复用决定

| 需求 | 候选与版本 | 来源、许可证 | 决定 |
| --- | --- | --- | --- |
| 首次改密与路由恢复 | 项目既有 `/api/change-password`、Vue Router 4.2.2、Pinia 2.x | [Vue Router](https://github.com/vuejs/router/tree/v4.2.2)，MIT；官方仓库 2026-09-04 查询仍在维护 | 复用既有 API 与导航守卫，不引入认证系统 |
| 表单与错误反馈 | 项目已安装的 Element Plus 2.x | [Element Plus](https://github.com/element-plus/element-plus)，MIT | 复用表单、按钮和反馈组件 |
| 重建整套鉴权或引入帮助台框架 | 另一个路由、认证或工单依赖 | 不选定额外依赖 | 不采用；现有接口已具备业务能力，问题在前端未衔接 |
| 发布命令 | 已安装 Laravel 13.29.0 / Composer 与锁定 pnpm | [Laravel 13 部署文档](https://github.com/laravel/docs/blob/13.x/deployment.md)，框架 MIT | 修正现有入口，使用已有构建和受控迁移命令 |

依赖修复版本、安全审计和许可证以本次最终锁文件与实测结果为准。

## 验证记录

以下为 2026-09-04 在本分支实测，后端与浏览器顺序使用隔离数据库，不并行清库。

| 检查 | 实际命令/环境 | 结果 |
| --- | --- | --- |
| 后端完整测试 | `serve/bin/test-env php vendor/bin/phpunit`，PHP 8.5.10 | 421 tests / 2521 assertions，退出 0 |
| 前端类型 | pnpm 9.15.9 `--dir admin run type-check` | 退出 0 |
| 锁定安装 | pnpm 9.15.9 `--dir admin install --frozen-lockfile` | 退出 0 |
| 正式构建 | `VITE_PUBLIC_PATH=/web/` + pnpm 9.15.9 `--dir admin run build-only` | 2372 modules，退出 0；同时消除原 CSS nesting 编译警告 |
| 开发服务浏览器闭环 | `pnpm exec playwright test --reporter=line`，admin 目录 | 9 passed / 21.8s，退出 0 |
| 正式构建浏览器闭环 | `E2E_BUILT=1 pnpm exec playwright test --reporter=line`，admin 目录 | 9 passed / 18.5s，退出 0；实际访问 `/web/` 构建文件及 Laravel API |
| 启动重试重复验证 | Playwright `-g startup --repeat-each=8` | 8 passed / 13.6s，退出 0 |
| 前端生产依赖审计 | pnpm 9.15.9 `--dir admin audit --prod --audit-level high` | 严重/高危 0；剩余 2 moderate / 1 low，退出 0 |
| 后端锁文件审计 | `composer audit --working-dir=serve --locked --no-interaction` | 无安全公告，退出 0 |
| 部署行为测试 | `php deploy/tests/check.php` | 20 checks，退出 0 |
| 脚本与 diff | `bash -n`、`php -l`、`git diff --check` | 通过 |

浏览器验证真实提交到本地隔离库：新管理员改密、重新登录、session token 退出，
企微链接上传/创建/编辑/复制/解析/删除，客诉提交/幂等重试/私有附件下载/状态/备注，
以及渠道创建和复制。网络故障场景只中断指定请求，恢复后继续请求真实 API。

部署行为测试覆盖缺失/不安全配置、密钥格式、私有存储公开目录与软链接、数据库不可用、
非覆盖打包、敏感文件/配置缓存排除、移动后 manifest 验证、未提交构建拒绝等。
配置预检不运行迁移；打包要求源码和跟踪的构建文件都已提交，包内 `RELEASE` 记录准确 SHA。

## 保留事项

- PHP 8.5 对既有 `wyzheng/ugly-base` 两个隐式 nullable 参数发出弃用提示；
  PHPUnit 全量无失败，没有修改 vendor 或隐藏该提示。
- 前端 `element-plus`、`echarts` 保留两项 moderate，`es5-ext` 保留一项 low；
  未把“高危清零”描述为无漏洞。本次没有进行 ECharts 大版本迁移或替换编辑器。
- 首次整组开发服务验收中，启动重试出现过一次未渲染登录表单；补充恢复响应状态与
  浏览器异常断言后，全组 9 项、独立 8 次以及正式构建 9 项均通过。原始一次瞬态未再次复现，
  不宣称已定位其唯一原因；现网上线验收仍须观察启动恢复。
- 旧源码中的未使用地图凭据配置与 loader 已移除；没有重写 Git 历史。
- 第三方 vendor 构建块含有语义相关的空白字符串，保持编译输出原样；
  `.gitattributes` 仅对生成的 `vendor-*.js` 关闭空白排版检查，源代码仍完整检查。
- 许可证文件与既有第三方声明保留，本轮不改变既有商业授权决策。

## 外部验收

本地测试采用专用测试数据库和测试数据。企微获客链接使用测试字符串，
仅验证系统保存、复制及返回目标，不发送好友申请。机器人通知不使用真实 Webhook。

2026-09-04 只读访问计划中的站点：登录页与 `/api/config` 返回 HTTP 200；
该结果不确定服务端代码 SHA，也不证明新版本已部署。

正式交付还需目标服务器版本核对、数据库与附件备份、经批准的发布、
队列和调度状态、真实群通知，以及运营方实际链接在目标客户端中的打开结果。

## 现网验收发现的 H5 兼容修复

2026-09-04 发布检查发现：已有两条 type=5 的 H5 二维码卡片没有 `min_id`，
其 QR 配置有效，但 `LandingMiniHealthChecker` 仍强制要求小程序引用，
使每次定时健康检查把它们置为不健康；这不是人工停用。

- 健康检查与现有 H5 resolver 对齐：未配置 `min_id` 时检查有效 QR；
  显式无效小程序引用、空 QR 和过期 QR 仍判失败，不修改人工开关或 URL 安全规则。
- 六类链接列表复用 API 现有 `effective_status`，修正旧 `status` 为 1 时仍显示
  “可用”的误报。新增浏览器回归：通过真实 API 停用后，列表显示不可用且解析返回 403。
- 本地完整 PHPUnit 更新为 **423 tests / 2528 assertions**，退出 0。
- 正式构建下完整浏览器验收 **9 passed / 18.0s**，包含停用后的状态与接口一致性回归；
  类型检查、正式构建及部署工具 20 项检查通过。
- 新增站点专用 `deploy/systemd/link-card-worker.service`，以 `www` 运行 Redis worker；
  现网已有每分钟 cron 被保留，不新增重复 scheduler。
- 另一条 type=4 链接实际指向 `work.weixin.qq.com/help`，不是有效的获客链接。
  保留原地址和检测结果，需运营方替换为真实目标，不通过强行启用掩盖配置问题。

完整发布结果以本次部署记录为准，数据库密码、Webhook 和私钥不会写入记录。
