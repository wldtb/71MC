# 71MC PHP 服务端

包含官网、管理员后台、版本发布与草稿、下载链接维护、SHA-256 校验值、启动器公告、主页文字、密码管理和操作记录。无需 Composer 或 MySQL。

## 部署到 mcgame1.710080.xyz

1. 安装 PHP 8.2 或更高版本（推荐 8.3），启用 `PDO`、`pdo_sqlite`、`session`、`openssl`。数据库使用本机 SQLite，勿放在网络共享盘。
2. 将整个服务端目录上传到服务器，例如 `/var/www/71mc`。**网站根目录必须是 `/var/www/71mc/public`**，不能设为服务端根目录。
3. 配置域名 `mcgame1.710080.xyz` 和有效的 HTTPS 证书。
4. 在服务端根目录执行：

   ```sh
   php bin/console.php install admin
   ```

   按提示设置自己的管理员密码（12–72 字节）。没有默认密码，也没有网页安装入口。

5. 确保 PHP-FPM 运行用户可以读写 `storage` 目录和数据库。例如 Ubuntu 默认运行用户为 `www-data`：

   ```sh
   sudo chown -R www-data:www-data /var/www/71mc/storage
   sudo chmod 700 /var/www/71mc/storage
   sudo chmod 600 /var/www/71mc/storage/71mc.sqlite
   ```

6. Nginx 的 HTTPS 站点配置中加入以下规则，按本机 PHP-FPM 版本修改 socket：

   ```nginx
   root /var/www/71mc/public;
   index index.php;
   location / {
       try_files $uri /index.php$is_args$args;
   }
   location = /index.php {
       include fastcgi_params;
       fastcgi_param SCRIPT_FILENAME $document_root/index.php;
       fastcgi_param HTTPS on;
       fastcgi_pass unix:/run/php/php8.3-fpm.sock;
   }
   location ~ \.php$ { return 404; }
   location ~ /\. { deny all; }
   ```

   Apache 可使用 `public/.htaccess`，需启用 `mod_rewrite` 与 `AllowOverride All`。宝塔面板选择 PHP 8.3，将运行目录设为 `/public`，Nginx 伪静态使用上面的 `location /` 规则。

7. 打开 `https://mcgame1.710080.xyz/api/v1/health` 检查状态；打开 `https://mcgame1.710080.xyz/admin` 登录。

若使用可信的 HTTPS 反向代理并由代理设置 `X-Forwarded-Proto: https`，可将 `config/local.example.php` 复制为 `config/local.php` 并启用 `trust_proxy`。`local.php` 不入库。正常直连 HTTPS 不需要此设置。

## 发布新版本

1. 修改启动器 `Modules/Base/ModBase.vb` 中的 `VersionBaseName` 和两个 `VersionCode`，同步 `My Project/AssemblyInfo.vb` 的程序集版本。
2. 构建并把 `71MC.exe` 或根目录包含 `71MC.exe` 的 ZIP 上传到 GitHub Release 或自己的 HTTPS 文件服务器。
3. 在后台“发布新版本”填写版本名、递增的版本代码、下载文件的 HTTPS 直链、**该下载文件**的 SHA-256 和更新说明，勾选“立即发布”。链接路径须以 `.zip` 或 `.exe` 结尾，支持查询参数。
4. 客户端在联网检查时读取新版本，并根据自己的公告/自动更新设置提示。手动检查入口在“设置 → 其他 → 检查更新”。校验不匹配时拒绝安装。

首版启动器的版本名为 `1.0.0`，版本代码为 `10000`。例如下一版使用 `1.0.1 / 10001`。客户端以**版本代码**判断升级，下载地址或说明修改后配置修订号会自动递增。

Windows 可用 `Get-FileHash .\71MC-windows.zip -Algorithm SHA256`；Linux 可用 `sha256sum 71MC-windows.zip`。每次 GitHub 构建附带 `SHA256SUMS.txt`。

首版 Release 还提供 `release.json`，可以在服务端根目录导入：

```sh
php bin/console.php publish /path/to/release.json
```

JSON 格式：

```json
{
  "version": "1.0.1",
  "version_code": 10001,
  "download_url": "https://example.com/71MC-windows.zip",
  "sha256": "填写下载文件真实的64位SHA256",
  "notes": "本次更新说明",
  "published": 1
}
```

同一版本的链接可在“版本记录 → 编辑”修改。取消“立即发布”可下架版本，更新接口随后返回剩余已发布版本中代码最大的版本；高版本客户端不会降级。公告可发布、下线。已读普通公告在服务配置变更后可能再次提示。

## 接口

所有路径的域名均为 `https://mcgame1.710080.xyz`。

| 路径 | 用途 |
| --- | --- |
| `/api/v1/health` | 数据库与服务状态 |
| `/api/v1/releases/latest?current_code=10000` | 最新正式版、更新判断、下载链接、SHA-256 |
| `/api/v1/releases` | 最近 100 个已发布版本 |
| `/api/v1/notices` | 已发布公告 |
| `/notice.cfg` | 启动器使用的版本代码与配置修订号 |
| `/ServerConfig.json` | 启动器更新链接、SHA-256 |
| `/notice.json` | 启动器公告与更新提示 |
| `/minecraft/download.json` | 空的额外游戏版本列表，不影响 Minecraft 官方列表 |
| `/homepage.xaml` | 经过 XML 转义的启动器主页 |
| `/download` | 跳转至最新发布版下载链接 |
| `/api/v1/telemetry` | 兼容接口，返回 204，不保存事件数据 |

公开 API 只读，后台写入需要管理员会话与 CSRF 校验。会话空闲一小时过期；同一来源 15 分钟内连续失败 8 次会暂时限制登录。

## 运维与本地验证

重置密码：`php bin/console.php reset-password admin`。修改密码会使其他会话失效。备份使用 SQLite 在线备份（例如 `sqlite3 storage/71mc.sqlite '.backup backup.sqlite'`），或停止 PHP 后复制完整 `storage` 目录；运行期间不要只复制 `.sqlite` 而漏掉 WAL 文件。

本地开发：

```sh
php -S 127.0.0.1:8080 -t public public/router.php
```

完整 HTTP 集成测试（自动创建和清理测试数据库，不接触生产数据库）：

```sh
python3 tests/smoke.py
```

GitHub 令牌、数据库、后台密码和 `config/local.php` 均不得上传仓库。发布接口不需要 GitHub 令牌：下载链接是公开地址。
