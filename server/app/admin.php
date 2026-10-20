<?php
declare(strict_types=1);
start_session();
header('Content-Type: text/html; charset=utf-8');
$error = '';
$success = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($method === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'login') {
            if (login(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) redirect('/admin');
            throw new InvalidArgumentException('用户名或密码错误。');
        }
        if (!authenticated()) redirect('/admin');
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                setcookie(session_name(), '', ['expires' => 1, 'path' => '/admin', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Strict']);
                redirect('/admin');
            case 'release':
                save_release($_POST);
                $_SESSION['flash'] = '版本已保存。已发布的最高版本代码将提供给启动器。';
                break;
            case 'notice':
                save_notice($_POST);
                $_SESSION['flash'] = '公告已保存。';
                break;
            case 'notice_toggle':
                db()->beginTransaction();
                query('UPDATE notices SET published = 1 - published WHERE id=?', [(int)($_POST['id'] ?? 0)]);
                revision();
                audit('notice.toggle', (string)($_POST['id'] ?? ''));
                db()->commit();
                $_SESSION['flash'] = '公告状态已切换。';
                break;
            case 'settings':
                $title = trim((string)($_POST['site_title'] ?? ''));
                $text = trim((string)($_POST['homepage_text'] ?? ''));
                if ($title === '' || strlen($title) > 120 || strlen($text) > 12000) throw new InvalidArgumentException('站点标题或主页内容不符合长度要求。');
                db()->beginTransaction();
                query("UPDATE settings SET value=? WHERE name='site_title'", [$title]);
                query("UPDATE settings SET value=? WHERE name='homepage_text'", [$text]);
                revision();
                audit('settings.save');
                db()->commit();
                $_SESSION['flash'] = '官网及启动器主页内容已更新。';
                break;
            case 'password':
                $old = (string)($_POST['old_password'] ?? '');
                $new = (string)($_POST['new_password'] ?? '');
                $hash = query('SELECT password_hash FROM admins WHERE id=?', [$_SESSION['admin_id']])->fetchColumn();
                if (!password_verify($old, $hash)) throw new InvalidArgumentException('当前密码不正确。');
                if (strlen($new) < 12 || strlen($new) > 72 || $new !== (string)($_POST['confirm_password'] ?? '')) throw new InvalidArgumentException('新密码须为 12–72 字节，且两次输入一致。');
                $hash = password_hash($new, PASSWORD_DEFAULT);
                query('UPDATE admins SET password_hash=? WHERE id=?', [$hash, $_SESSION['admin_id']]);
                $_SESSION['password_version'] = hash('sha256', $hash);
                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                audit('admin.password');
                $_SESSION['flash'] = '密码已修改，其他登录会话已失效。';
                break;
            default:
                throw new InvalidArgumentException('未知操作。');
        }
        redirect('/admin');
    } catch (InvalidArgumentException $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $e->getMessage();
    }
} elseif ($method !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}
$loggedIn = authenticated();
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>71MC 管理后台</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header><a class="brand" href="/admin">71MC <span>发布管理</span></a><?php if ($loggedIn): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="logout"><span><?=h($_SESSION['username'])?></span><button class="secondary">退出登录</button></form><?php endif ?></header>
<main>
<?php if ($error): ?><p role="alert" class="alert error"><?=h($error)?></p><?php endif ?>
<?php if ($success): ?><p role="status" class="alert success"><?=h($success)?></p><?php endif ?>
<?php if (!$loggedIn): ?>
<section class="login"><span class="eyebrow">WELCOME BACK</span><h1>管理你的 71MC</h1><p class="muted">登录后可发布新版本、维护下载链接和公告。</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="login"><label>管理员账号<input name="username" required autocomplete="username" maxlength="64"></label><label>密码<input name="password" type="password" required autocomplete="current-password" maxlength="72"></label><button>登录后台</button></form></section>
<?php else:
$site = settings();
$releases = query('SELECT * FROM releases ORDER BY version_code DESC LIMIT 100')->fetchAll();
$notices = query('SELECT * FROM notices ORDER BY id DESC LIMIT 50')->fetchAll();
$editing = null;
if (isset($_GET['edit'])) $editing = query('SELECT * FROM releases WHERE id=?', [(int)$_GET['edit']])->fetch() ?: null;
?>
<div class="intro"><span class="eyebrow">LAUNCHER CONTROL</span><h1>发布中心</h1><p class="muted">从这里管理版本、下载链接和启动器公告。</p></div>
<nav><a href="#release">发布版本</a><a href="#history">版本记录</a><a href="#notices">公告</a><a href="#settings">主页设置</a><a href="/" target="_blank" rel="noopener">查看官网 ↗</a></nav>
<section id="release"><h2><?=$editing ? '编辑版本 ' . h($editing['version']) : '发布新版本'?></h2><p class="muted">先在 GitHub Release 上传 71MC.exe 或根目录含 71MC.exe 的 ZIP，再填写文件链接与 SHA-256。版本代码高于客户端才会触发更新。</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?=h($editing['id'] ?? 0)?>">
<div class="grid"><label>版本名称<input name="version" required placeholder="1.0.1" value="<?=h($editing['version'] ?? '')?>" maxlength="48"></label><label>递增版本代码<input name="version_code" type="number" min="10000" max="2000000000" required placeholder="10001" value="<?=h($editing['version_code'] ?? '')?>"></label></div>
<label>HTTPS 下载链接<input name="download_url" type="url" required placeholder="https://github.com/wldtb/71MC/releases/download/v1.0.1/71MC.exe" value="<?=h($editing['download_url'] ?? '')?>" maxlength="2048"></label>
<label>文件 SHA-256<input name="sha256" required pattern="[a-fA-F0-9]{64}" minlength="64" maxlength="64" value="<?=h($editing['sha256'] ?? '')?>" placeholder="64 位十六进制；必须与下载文件一致"></label>
<label>更新说明<textarea name="notes" rows="5" maxlength="16000"><?=h($editing['notes'] ?? '')?></textarea></label>
<label class="checkbox"><input name="published" type="checkbox" value="1" <?=!$editing || $editing['published'] ? 'checked' : ''?>>立即发布（取消勾选保存为草稿）</label><button>保存版本</button><?php if ($editing): ?> <a href="/admin#release">取消编辑</a><?php endif ?></form></section>
<section id="history"><h2>版本记录</h2><div class="table-wrap"><table><thead><tr><th>版本</th><th>代码</th><th>状态</th><th>下载链接</th><th></th></tr></thead><tbody><?php foreach ($releases as $row): ?><tr><td><?=h($row['version'])?></td><td><?=h($row['version_code'])?></td><td><span class="badge"><?=$row['published'] ? '已发布' : '草稿'?></span></td><td class="url"><?=h($row['download_url'])?></td><td><a href="/admin?edit=<?=h($row['id'])?>#release">编辑</a></td></tr><?php endforeach ?></tbody></table></div><?php if (!$releases): ?><p class="muted">还没有版本。发布后，启动器会自动读取最新版本信息。</p><?php endif ?></section>
<section id="notices"><h2>启动器公告</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="notice"><label>公告标题<input name="title" required maxlength="200"></label><label>内容<textarea name="body" required rows="4" maxlength="16000"></textarea></label><label>重要等级<select name="level"><option value="2">普通公告</option><option value="1">重要公告</option><option value="0">紧急公告</option></select></label><label class="checkbox"><input name="published" type="checkbox" value="1" checked>立即发布</label><button>发布公告</button></form>
<?php foreach ($notices as $notice): ?><article><div class="row"><h3><?=h($notice['title'])?></h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="notice_toggle"><input type="hidden" name="id" value="<?=h($notice['id'])?>"><button class="secondary"><?=$notice['published'] ? '下线' : '发布'?></button></form></div><p><?=nl2br(h($notice['body']))?></p><small class="muted"><?=h($notice['created_at'])?> · <?=$notice['published'] ? '已发布' : '未发布'?></small></article><?php endforeach ?></section>
<section id="settings"><h2>官网及启动器主页</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="settings"><label>站点标题<input name="site_title" required maxlength="120" value="<?=h($site['site_title'])?>"></label><label>主页文字<textarea name="homepage_text" rows="4" maxlength="12000"><?=h($site['homepage_text'])?></textarea></label><button>保存主页</button></form></section>
<section><h2>修改管理员密码</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="password"><label>当前密码<input type="password" name="old_password" required autocomplete="current-password"></label><div class="grid"><label>新密码<input type="password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password"></label><label>再次输入新密码<input type="password" name="confirm_password" required minlength="12" maxlength="72" autocomplete="new-password"></label></div><button>修改密码</button></form></section>
<section><h2>最近操作</h2><ul class="audit"><?php foreach (query('SELECT action,target,created_at FROM audit_log ORDER BY id DESC LIMIT 12') as $entry): ?><li><span><?=h($entry['action'])?> <?=h($entry['target'])?></span><time><?=h($entry['created_at'])?></time></li><?php endforeach ?></ul></section>
<?php endif ?>
</main><footer>71MC · PHP <?=h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)?> · <a href="/api/v1/health">服务状态</a></footer></body></html>
