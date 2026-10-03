<?php
declare(strict_types=1);
// Refreshes the pinned balance card in the family channel when the balance changed.
// Cron (every minute): php widget_update.php
// Manual: php widget_update.php --force [--chat=ID] · render only: --out=file.png
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/report_image.php';

$opts = getopt('', ['chat:', 'out:', 'force']);
if (isset($opts['out'])) {
  $d = balance_widget_data();
  render_balance_card_png($d, $opts['out']);
  fwrite(STDOUT, "Rendered {$opts['out']}\n\n" . balance_widget_caption($d) . "\n");
  exit(0);
}
$chat = (string)($opts['chat'] ?? ($config['widget_chat_id'] ?? ($config['report_chat_id'] ?? '')));
if ($chat === '') exit(0);
// Adding or deleting an operation marks the widget stale
if (!isset($opts['force']) && get_setting('balance_widget_dirty', '1') !== '1') exit(0);
set_setting('balance_widget_dirty', '0');
try {
  fwrite(STDOUT, date('c') . ' ' . update_balance_widget($chat) . "\n");
} catch (Throwable $e) {
  set_setting('balance_widget_dirty', '1');
  fwrite(STDERR, date('c') . ' Widget error: ' . $e->getMessage() . "\n");
  exit(1);
}
