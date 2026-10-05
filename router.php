<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$blocked = [
  '/config.php', '/config.example.php', '/lib.php', '/polling.php',
  '/router.php', '/schema.mysql.sql', '/README.md', '/DESIGN.md', '/.gitignore',
  '/weekly_report.php', '/report_image.php', '/widget_update.php', '/features.php', '/bot.php', '/backup.php', '/daily.php', '/statement_worker.php', '/extras.php', '/planner.php', '/family.php', '/tools/qr.mjs', '/tools/stt.py', '/import_statement.php',
  '/.env', '/.env.example', '/package.json', '/tsconfig.json',
];
if (str_starts_with($path, '/storage/') || str_contains($path, '..') || in_array($path, $blocked, true)) {
  http_response_code(404);
  exit;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) return false;
require __DIR__ . '/index.php';
