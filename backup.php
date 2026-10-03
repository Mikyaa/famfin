<?php
declare(strict_types=1);
// Daily backup: snapshot of the SQLite database, kept for 30 days next to it and sent
// silently to backup_chat_id (Mirzhan's chat with the bot).
// Cron: php backup.php · local copy only: php backup.php --no-send
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$opts = getopt('', ['no-send']);
$b = make_backup();
fwrite(STDOUT, date('c') . ' Backup ' . basename($b['file']) . " ({$b['size']} bytes, {$b['transactions']} operations)\n");
$chat = (string)($config['backup_chat_id'] ?? '');
if (isset($opts['no-send']) || $chat === '') exit(0);
$r = send_backup($chat, $b);
if (!($r['ok'] ?? false)) { fwrite(STDERR, date('c') . ' Telegram error: ' . ($r['description'] ?? 'unknown') . "\n"); exit(1); }
fwrite(STDOUT, date('c') . " Sent to $chat\n");
