<?php
declare(strict_types=1);

// Only public/ is a web root. Database, configuration and application code stay outside it.
const APP_ROOT = __DIR__ . '/..';
const BASE_VERSION_CODE = 10000;
const BASE_VERSION = '1.0.0';

function config(): array
{
    static $value;
    if ($value !== null) return $value;
    $local = is_file(APP_ROOT . '/config/local.php') ? require APP_ROOT . '/config/local.php' : [];
    $value = array_replace([
        'base_url' => getenv('APP_URL') ?: 'https://mcgame1.710080.xyz',
        'database' => getenv('APP_DATABASE') ?: APP_ROOT . '/storage/71mc.sqlite',
        'trust_proxy' => getenv('APP_TRUST_PROXY') === '1',
        'allow_http_admin' => getenv('APP_ALLOW_HTTP_ADMIN') === '1',
        'source_url' => 'https://github.com/wldtb/71MC',
    ], $local);
    return $value;
}

function db(): PDO
{
    static $db;
    if ($db instanceof PDO) return $db;
    $path = config()['database'];
    if (!is_file($path)) throw new RuntimeException('尚未安装，请通过 CLI 创建管理员。');
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON;');
    return $db;
}

function query(string $sql, array $args = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt;
}

function settings(): array
{
    $result = ['revision' => '1', 'site_title' => '71MC', 'homepage_text' => '欢迎使用 71MC！点击顶部联机按钮即可打开本地联机工具。'];
    foreach (query('SELECT name, value FROM settings') as $row) $result[$row['name']] = $row['value'];
    return $result;
}

function revision(): void
{
    query("UPDATE settings SET value = CAST(value AS INTEGER) + 1 WHERE name = 'revision'");
}

function latest(): ?array
{
    return query('SELECT * FROM releases WHERE published = 1 ORDER BY version_code DESC LIMIT 1')->fetch() ?: null;
}

function release_data(array $row): array
{
    return ['version' => $row['version'], 'version_code' => (int)$row['version_code'], 'download_url' => $row['download_url'],
        'sha256' => $row['sha256'], 'notes' => $row['notes'], 'published_at' => $row['published_at']];
}

function https_url(string $url): string
{
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) throw new InvalidArgumentException('下载链接不是有效的网址。');
    $parts = parse_url($url);
    if (strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('下载链接必须为 HTTPS，且不能包含账号、密码或锚点。');
    }
    $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, ['zip', 'exe'], true)) throw new InvalidArgumentException('链接路径必须以 .zip 或 .exe 结尾，可包含查询参数。');
    return $url;
}

function save_release(array $data): int
{
    $version = trim((string)($data['version'] ?? ''));
    $code = filter_var($data['version_code'] ?? '', FILTER_VALIDATE_INT);
    if (!preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}(?:\.[0-9]{1,4})?(?:-[A-Za-z0-9.]+)?$/D', $version)) throw new InvalidArgumentException('版本名请使用 1.0.1 这样的格式。');
    if ($code === false || $code < BASE_VERSION_CODE || $code > 2000000000) throw new InvalidArgumentException('版本代码必须是 10000 到 2000000000 之间的整数，升级时递增。');
    $url = https_url(trim((string)($data['download_url'] ?? '')));
    $sha = strtolower(trim((string)($data['sha256'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/D', $sha)) throw new InvalidArgumentException('请输入下载文件的 64 位 SHA-256。');
    $notes = trim((string)($data['notes'] ?? ''));
    if (strlen($notes) > 16000) throw new InvalidArgumentException('更新说明过长。');
    $published = isset($data['published']) && (string)$data['published'] === '1' ? 1 : 0;
    $id = max(0, (int)($data['id'] ?? 0));
    db()->beginTransaction();
    try {
        if ($id) {
            if (!query('SELECT id FROM releases WHERE id = ?', [$id])->fetch()) throw new InvalidArgumentException('版本不存在。');
            query('UPDATE releases SET version=?, version_code=?, download_url=?, sha256=?, notes=?, published=?, published_at=? WHERE id=?', [$version, $code, $url, $sha, $notes, $published, gmdate('c'), $id]);
        } else {
            query('INSERT INTO releases(version,version_code,download_url,sha256,notes,published,published_at) VALUES(?,?,?,?,?,?,?)', [$version, $code, $url, $sha, $notes, $published, gmdate('c')]);
            $id = (int)db()->lastInsertId();
        }
        revision();
        audit('release.save', (string)$id);
        db()->commit();
        return $id;
    } catch (Throwable $e) {
        db()->rollBack();
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE')) throw new InvalidArgumentException('版本代码已存在，请编辑原版本或使用更大的代码。');
        throw $e;
    }
}

function save_notice(array $data): void
{
    $title = trim((string)($data['title'] ?? ''));
    $body = trim((string)($data['body'] ?? ''));
    $level = filter_var($data['level'] ?? '2', FILTER_VALIDATE_INT);
    if ($title === '' || strlen($title) > 200 || $body === '' || strlen($body) > 16000 || $level === false || $level < 0 || $level > 2) throw new InvalidArgumentException('请填写有效公告标题、内容和等级。');
    db()->beginTransaction();
    try {
        query('INSERT INTO notices(title,body,level,published,created_at) VALUES(?,?,?,?,?)', [$title, $body, $level, isset($data['published']) ? 1 : 0, gmdate('c')]);
        revision();
        audit('notice.create', (string)db()->lastInsertId());
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
}

function audit(string $action, string $target = ''): void
{
    query('INSERT INTO audit_log(admin_id,action,target,created_at) VALUES(?,?,?,?)', [$_SESSION['admin_id'] ?? null, $action, $target, gmdate('c')]);
}

function legacy_notices(): array
{
    $result = [];
    // Revision is monotonically increasing, so edits and new releases invalidate client caches.
    $current = (int)settings()['revision'];
    foreach (query('SELECT * FROM notices WHERE published=1 ORDER BY id') as $row) {
        $result[] = ['id' => $current, 'title' => $row['title'], 'description' => $row['body'], 'importantLevel' => (int)$row['level'],
            'isUpdate' => false, 'buttons' => [['text' => '知道了', 'actions' => new stdClass()]]];
    }
    if ($release = latest()) {
        $result[] = ['id' => $current, 'title' => '71MC ' . $release['version'] . ' 已发布', 'description' => $release['notes'] ?: '发现新版本，是否更新？',
            'importantLevel' => 2, 'isUpdate' => true, 'requirements' => ['versioncode <=' => (int)$release['version_code'] - 1],
            'buttons' => [['text' => '立即更新', 'actions' => ['update' => true]], ['text' => '稍后', 'actions' => new stdClass()]]];
    }
    return $result;
}

function server_config(): array
{
    $release = latest();
    $info = ['DownloadUrls' => $release ? [$release['download_url']] : [], 'Sha256' => $release['sha256'] ?? '',
        'VersionCode' => $release ? (int)$release['version_code'] : BASE_VERSION_CODE];
    return ['Version' => (int)settings()['revision'], 'Update' => ['Release' => $info, 'Snapshot' => $info],
        'Link' => ['DisableReason2' => '请通过顶部联机按钮打开本机联机工具。']];
}

function json_response(mixed $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function xml(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'); }

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (config()['trust_proxy'] && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_https() && !config()['allow_http_admin'] && !in_array($remote, ['127.0.0.1', '::1'], true)) {
        http_response_code(400);
        exit('后台必须通过 HTTPS 访问。');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $sessionPath = dirname(config()['database']) . '/sessions';
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true)) throw new RuntimeException('无法创建会话目录。');
    session_save_path($sessionPath);
    session_name('mc71_admin');
    session_set_cookie_params(['httponly' => true, 'secure' => is_https(), 'samesite' => 'Strict', 'path' => '/admin']);
    if (!session_start()) throw new RuntimeException('无法创建管理员会话。');
    if (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] > 3600) $_SESSION = [];
    $_SESSION['last_active'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }

function check_csrf(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('请求验证失败，请刷新页面后重试。');
    }
}

function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }

function authenticated(): bool
{
    if (empty($_SESSION['admin_id'])) return false;
    $admin = query('SELECT password_hash FROM admins WHERE id=?', [$_SESSION['admin_id']])->fetch();
    return $admin && hash_equals(hash('sha256', $admin['password_hash']), $_SESSION['password_version'] ?? '');
}

function login(string $username, string $password): bool
{
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli');
    $cutoff = time() - 900;
    query('DELETE FROM login_attempts WHERE created_at < ?', [$cutoff]);
    $count = (int)query('SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND created_at>=?', [$ip, $cutoff])->fetchColumn();
    if ($count >= 8) throw new InvalidArgumentException('尝试过于频繁，请在 15 分钟后重试。');
    $user = query('SELECT * FROM admins WHERE username=?', [$username])->fetch();
    // Perform a password hash check even for an unknown username.
    $hash = $user['password_hash'] ?? '$2y$10$aoSMDFJoRqfzRPvJ6S8aVeGzLiJ3KcGofgMvzR3V5TYdEfiBwwnW.';
    if (!password_verify($password, $hash) || !$user) {
        query('INSERT INTO login_attempts(ip_hash,created_at) VALUES(?,?)', [$ip, time()]);
        return false;
    }
    query('DELETE FROM login_attempts WHERE ip_hash=?', [$ip]);
    session_regenerate_id(true);
    $_SESSION = ['admin_id' => (int)$user['id'], 'username' => $user['username'], 'csrf' => bin2hex(random_bytes(32)),
        'password_version' => hash('sha256', $hash), 'last_active' => time()];
    audit('admin.login');
    return true;
}
