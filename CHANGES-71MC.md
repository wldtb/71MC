# 71MC 1.0.0

- 启动器名称、程序文件名、窗口标题、默认顶部文字、程序集资源名更改为 71MC；内部 CLR 命名空间继续使用 PCL 以保持源码兼容。
- 项目配置、更新、公告、默认主页和遥测兼容接口统一为 `https://mcgame1.710080.xyz/`。
- 移除回声洞、反馈入口、新功能投票、今日人品、千万别点，以及对应事件、预设主页和设置项。保留内存优化、垃圾清理、自定义下载。
- 顶部“联机”点击后启动 `C:\Program Files\mclan\联机工具.exe`，缺失时显示“未找到联机工具”；阻止原内部联机页面、剪贴板邀请码和联机设置入口。
- 更新包支持 HTTPS ZIP/EXE 和带查询参数的链接；强制 SHA-256 校验、验证产品名及更新包根目录可执行文件。
- 提供 PHP 8.2+/SQLite 后台、API、部署说明及真实 HTTP 集成测试。
- GitHub Actions 执行 PHP 测试与 Windows Release 构建，并在推送版本标签时发布 EXE、Windows ZIP、完整源码、PHP 服务端源码和校验清单。

原有 Minecraft/Microsoft 登录、Mojang、BMCLAPI、Modrinth、CurseForge、Java 等游戏必需接口继续使用对应提供方地址。上游作者署名、许可证和鸣谢保留。
