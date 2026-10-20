# 71MC

基于 [CortexaX/OpenPCL](https://github.com/CortexaX/OpenPCL) 独立修改的 Minecraft 启动器，配套 PHP 管理后台。感谢 Plain Craft Launcher 原作者龙腾猫跃及所有上游贡献者，保留仓库 MIT 许可证与各第三方组件许可证。

- [下载启动器与完整源码](https://github.com/wldtb/71MC/releases/latest)
- [GitHub 构建记录](https://github.com/wldtb/71MC/actions)
- [PHP 服务端部署和接口文档](server/README.md)
- [本次修改](CHANGES-71MC.md)

更新、公告、默认主页使用 `https://mcgame1.710080.xyz/`。首次使用前需要在该域名部署 `server/`；GitHub Release 提供可直接导入后台的 `release.json`。

顶部联机按钮启动 `C:\Program Files\mclan\联机工具.exe`，缺失时提示“未找到联机工具”。联机工具需自行安装；此仓库不包含该外部程序。

## 完整源码与构建

```powershell
git clone --recurse-submodules https://github.com/wldtb/71MC.git
cd 71MC
msbuild "Plain Craft Launcher 2.sln" /t:Restore /p:Configuration=Release /p:Platform="Any CPU"
msbuild "Plain Craft Launcher 2.sln" /m /p:Configuration=Release /p:Platform="Any CPU"
```

使用 Windows、Visual Studio 2022 Build Tools（.NET 桌面开发、.NET Framework 4.8 targeting pack）和 .NET 9 SDK。输出为 `Plain Craft Launcher 2/bin/71MC.exe`。工程目录保留上游名称，程序集和实际程序名称均为 71MC。

GitHub 自动生成的 Source code ZIP 不包含子模块。请下载 Release 中的 **71MC-source.zip**，其中包含固定版本的 MeloongCore 源码和 PHP 服务端，可直接构建；**71MC-php-server.zip** 是独立服务端包。

## 后续版本

修改启动器版本名、递增 `VersionCode` 和程序集版本，提交到 main 后检查 Actions。推送相应 `v*` 标签会自动生成 Release。构建清单 `release.json` 根据源码中的版本生成，后台也可以手工发布新版本。

## 接口说明

项目自身服务统一指向 71MC 域名。Minecraft/Microsoft 登录以及游戏、Java、Mod、Forge 等资源下载仍使用各服务提供方接口。作者署名、鸣谢及其赞助链接作为上游来源说明保留。

当前改造未包含微软 OAuth 应用重新注册；继承上游 OAuthClientId 的可用性由对应 Microsoft 应用配置决定。如需独立运营，可注册桌面应用后替换 `Modules/ModSecret.vb` 中的 OAuthClientId。
