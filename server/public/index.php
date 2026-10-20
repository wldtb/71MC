<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');
if (is_https()) header('Strict-Transport-Security: max-age=31536000');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($path === '/api/v1/telemetry' && in_array($method, ['GET', 'POST'], true)) {
        // Compatibility endpoint: acknowledge without collecting hardware IDs or event payloads.
        http_response_code(204);
        exit;
    }
    if (str_starts_with($path, '/admin')) {
        require __DIR__ . '/../app/admin.php';
        exit;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        json_response(['error' => 'method_not_allowed'], 405);
    }
    if ($path === '/api/v1/health') json_response(['status' => 'ok', 'database' => query('SELECT 1')->fetchColumn() == 1]);
    if ($path === '/notice.cfg') {
        $revision = (int)settings()['revision'];
        $version = latest()['version_code'] ?? BASE_VERSION_CODE;
        header('Content-Type: text/plain; charset=utf-8');
        echo "$revision|$version|$version|1|$revision";
        exit;
    }
    if ($path === '/ServerConfig.json') json_response(server_config());
    if ($path === '/notice.json') json_response(legacy_notices());
    if ($path === '/minecraft/download.json') json_response([]);
    if ($path === '/api/v1/multiplayer/nodes') json_response([]);
    if ($path === '/api/v1/releases/latest') {
        $release = latest();
        $code = filter_var($_GET['current_code'] ?? '0', FILTER_VALIDATE_INT);
        if ($code === false || $code < 0) json_response(['error' => 'invalid_current_code'], 400);
        json_response(['app' => '71MC', 'update_available' => $release && (int)$release['version_code'] > $code,
            'latest' => $release ? release_data($release) : null]);
    }
    if ($path === '/api/v1/releases') {
        $rows = query('SELECT * FROM releases WHERE published=1 ORDER BY version_code DESC LIMIT 100')->fetchAll();
        json_response(['releases' => array_map('release_data', $rows)]);
    }
    if ($path === '/api/v1/notices') {
        json_response(['notices' => query('SELECT id,title,body,level,created_at FROM notices WHERE published=1 ORDER BY id DESC LIMIT 100')->fetchAll()]);
    }
    if ($path === '/homepage.xaml') {
        header('Content-Type: application/xml; charset=utf-8');
        $site = settings();
        // A fragment consumed by PageLaunchRight; no arbitrary user-provided XAML is accepted.
        echo '<local:MyCard xmlns:local="clr-namespace:PCL;assembly=71MC" xmlns="http://schemas.microsoft.com/winfx/2006/xaml/presentation" Title="' . xml($site['site_title']) . '" Margin="0,0,0,15"><TextBlock Margin="25,40,25,20" TextWrapping="Wrap" Text="' . xml($site['homepage_text']) . '" /></local:MyCard>';
        exit;
    }
    if ($path === '/download') {
        $release = latest();
        if (!$release) json_response(['error' => 'no_release'], 404);
        header('Location: ' . https_url($release['download_url']), true, 302);
        exit;
    }
    if ($path !== '/') json_response(['error' => 'not_found'], 404);
    $site = settings();
    $release = latest();
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($site['site_title'])?></title><link rel="stylesheet" href="/assets/app.css"><body>
    <main class="public"><span class="eyebrow">MINECRAFT LAUNCHER</span><h1><?=h($site['site_title'])?></h1><p class="lead"><?=nl2br(h($site['homepage_text']))?></p>
    <?php if ($release): ?><a class="button" href="/download">下载 71MC <?=h($release['version'])?></a><p class="muted">版本代码 <?=h($release['version_code'])?> · <?=h($release['published_at'])?></p><pre><?=h($release['notes'])?></pre><details><summary>SHA-256</summary><code><?=h($release['sha256'])?></code></details><?php else: ?><p>管理员尚未发布版本。</p><?php endif ?>
    <p><a href="<?=h(config()['source_url'])?>">完整源代码</a> · <a href="/admin">管理后台</a></p><small>71MC 是基于 OpenPCL 独立修改的第三方启动器。感谢 Plain Craft Launcher 原作者及开源贡献者。</small></main></body></html>
    <?php
} catch (Throwable $e) {
    error_log('71MC server: ' . $e->getMessage());
    json_response(['error' => 'service_unavailable', 'message' => '服务尚未安装或暂时不可用，请联系管理员。'], 503);
}
