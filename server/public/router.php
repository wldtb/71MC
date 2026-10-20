<?php
declare(strict_types=1);
// Development server only. Never expose files outside public/.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/assets/app.css') return false;
require __DIR__ . '/index.php';
