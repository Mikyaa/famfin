<?php
declare(strict_types=1);
// Sends last week's summary with an analysis image to the family Telegram channel.
// Cron (Mondays): php weekly_report.php
// Options: --week=YYYY-MM-DD (any day of the week to report), --chat=ID, --out=file.png (render only), --force
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/report_image.php';

$opts = getopt('', ['week:', 'chat:', 'out:', 'force']);
$data = weekly_report_data($opts['week'] ?? null);
$caption = weekly_report_caption($data);

if (isset($opts['out'])) {
  render_weekly_report_png($data, $opts['out']);
  fwrite(STDOUT, "Rendered {$opts['out']}\n\n$caption\n");
  exit(0);
}

$chat = (string)($opts['chat'] ?? ($config['report_chat_id'] ?? ''));
if ($chat === '') { fwrite(STDERR, "Set report_chat_id in config.php or pass --chat\n"); exit(1); }

// One report per chat and week, so a cron retry or a manual run cannot double-post
$key = 'weekly_report_sent:' . $chat;
if (!isset($opts['force']) && get_setting($key) === $data['from']) {
  fwrite(STDOUT, date('c') . " Already sent for week {$data['from']} to $chat\n");
  exit(0);
}

$file = tempnam(sys_get_temp_dir(), 'famfin') . '.png';
try {
  render_weekly_report_png($data, $file);
  $r = telegram_send_photo($chat, $file, $caption);
} finally {
  @unlink($file);
}
if (!($r['ok'] ?? false)) {
  fwrite(STDERR, date('c') . ' Telegram error: ' . ($r['description'] ?? 'unknown') . "\n");
  exit(1);
}
set_setting($key, $data['from']);
fwrite(STDOUT, date('c') . " Sent week {$data['from']} to $chat\n");
