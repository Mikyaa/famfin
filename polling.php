<?php
declare(strict_types=1);
require __DIR__.'/lib.php';

if (empty($config['local_test_mode'])) { fwrite(STDERR, "local_test_mode is not enabled in config.php. Refusing to run.\n"); exit(1); }
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
if (empty($config['bot_token'])) { fwrite(STDERR, "Set bot_token in config.php\n"); exit(1); }

$webhook = telegram('getWebhookInfo', []);
if (($webhook['ok'] ?? false) && !empty($webhook['result']['url'])) {
  $deleted = telegram('deleteWebhook', ['drop_pending_updates' => false]);
  if (!($deleted['ok'] ?? false)) { fwrite(STDERR, "Could not switch this bot to local polling.\n"); exit(1); }
}

telegram('setMyCommands', ['commands' => [
  ['command'=>'start','description'=>'Открыть семейный бюджет'],
  ['command'=>'login','description'=>'Код для входа на сайт'],
  ['command'=>'balance','description'=>'Текущие остатки'],
  ['command'=>'week','description'=>'Сводка за неделю'],
  ['command'=>'month','description'=>'Сводка за месяц'],
  ['command'=>'limits','description'=>'Лимиты по категориям'],
  ['command'=>'help','description'=>'Помощь'],
]]);

$identity = telegram('getMe', []);
if (!($identity['ok'] ?? false)) { fwrite(STDERR, "Telegram bot authentication failed. Check config.php token.\n"); exit(1); }
$username = $identity['result']['username'] ?? 'bot';
fwrite(STDOUT, "Connected as @$username. Polling Telegram; press Ctrl+C to stop.\n");

$appUrl = rtrim($config['app_url'] ?? '', '/');

$offset = 0;
while (true) {
  try {
    $updates = telegram('getUpdates', ['offset'=>$offset,'timeout'=>25,'allowed_updates'=>['message']], 35);
    if (!($updates['ok'] ?? false)) { fwrite(STDERR, "Telegram polling error; retrying in 3 seconds.\n"); sleep(3); continue; }
    foreach ($updates['result'] ?? [] as $update) {
      $offset = max($offset, (int)$update['update_id'] + 1);
      $message = $update['message'] ?? null;
      if (!$message) continue;
      $from = $message['from'] ?? [];
      $id = (int)($from['id'] ?? 0);
      $chat = $message['chat']['id'] ?? null;
      if (!in_array($id, $config['allowed_users'], true) || !$chat) continue;
      $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
      save_member(['id'=>$id,'name'=>user_label($id, $name)]);
      $text = trim((string)($message['text'] ?? ''));

      handle_bot_command($chat, $id, $text, $appUrl);
    }
  } catch (Throwable $e) {
    fwrite(STDERR, "Polling request failed; retrying in 3 seconds.\n");
    sleep(3);
  }
}
