<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('CLI only');
require __DIR__ . '/../app/bootstrap.php';
$action = $argv[1] ?? 'help';

function password_input(): string
{
    $value = getenv('ADMIN_PASSWORD');
    if ($value !== false) return $value;
    fwrite(STDOUT, '管理员密码（12–72 字节）: ');
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && stream_isatty(STDIN);
    if ($hide) shell_exec('stty -echo');
    try { $value = trim((string)fgets(STDIN)); }
    finally { if ($hide) shell_exec('stty echo'); fwrite(STDOUT, PHP_EOL); }
    return $value;
}

try {
    if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('需要启用 PDO SQLite 扩展。');
    if ($action === 'install') {
        $username = $argv[2] ?? 'admin';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $username)) throw new InvalidArgumentException('用户名须为 3–64 位英文字母、数字或 _.-');
        $path = config()['database'];
        if (is_file($path)) throw new RuntimeException('数据库已存在，禁止重复安装。修改密码请使用 reset-password。');
        $password = password_input();
        if (strlen($password) < 12 || strlen($password) > 72) throw new InvalidArgumentException('密码须为 12–72 字节。');
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('无法创建数据目录。');
        $connection = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        chmod($path, 0600);
        $connection->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        $connection->exec(<<<'SQL'
CREATE TABLE admins(id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL);
CREATE TABLE settings(name TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE releases(id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT NOT NULL, version_code INTEGER NOT NULL UNIQUE,
download_url TEXT NOT NULL, sha256 TEXT NOT NULL, notes TEXT NOT NULL, published INTEGER NOT NULL DEFAULT 0 CHECK(published IN(0,1)), published_at TEXT NOT NULL);
CREATE TABLE notices(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL, level INTEGER NOT NULL CHECK(level BETWEEN 0 AND 2), published INTEGER NOT NULL CHECK(published IN(0,1)), created_at TEXT NOT NULL);
CREATE TABLE login_attempts(id INTEGER PRIMARY KEY, ip_hash TEXT NOT NULL, created_at INTEGER NOT NULL);
CREATE INDEX login_attempts_ip_time ON login_attempts(ip_hash,created_at);
CREATE TABLE audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, action TEXT NOT NULL, target TEXT NOT NULL, created_at TEXT NOT NULL);
INSERT INTO settings VALUES('revision','1'),('site_title','71MC'),('homepage_text','欢迎使用 71MC！点击顶部联机按钮即可打开本地联机工具。');
SQL);
        $connection->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        fwrite(STDOUT, "安装完成。请由 PHP-FPM 运行用户读写 storage 目录，然后访问 /admin。\n");
    } elseif ($action === 'reset-password') {
        $password = password_input();
        if (strlen($password) < 12 || strlen($password) > 72) throw new InvalidArgumentException('密码须为 12–72 字节。');
        $stmt = query('UPDATE admins SET password_hash=? WHERE username=?', [password_hash($password, PASSWORD_DEFAULT), $argv[2] ?? 'admin']);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('管理员不存在。');
        fwrite(STDOUT, "密码已重置，旧会话已失效。\n");
    } elseif ($action === 'publish') {
        $file = $argv[2] ?? '';
        if ($file === '' || !is_file($file)) throw new InvalidArgumentException('用法：php bin/console.php publish release.json');
        $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new InvalidArgumentException('发布清单必须为 JSON 对象。');
        $id = save_release($data);
        fwrite(STDOUT, "版本已保存，ID: $id\n");
    } else {
        fwrite(STDOUT, "71MC 服务端\n  php bin/console.php install [用户名]\n  php bin/console.php reset-password [用户名]\n  php bin/console.php publish release.json\n密码可以通过临时 ADMIN_PASSWORD 环境变量传入，请勿写入仓库。\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
