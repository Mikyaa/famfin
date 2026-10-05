<?php
declare(strict_types=1);
require __DIR__.'/lib.php';

if (empty($config['webhook_secret']) || !hash_equals($config['webhook_secret'], $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
  http_response_code(403);
  exit;
}

$u = json_decode(file_get_contents('php://input'), true) ?: [];
if (isset($u['callback_query'])) {
  try { bot_handle_callback($u['callback_query']); } catch (Throwable $e) { error_log((string)$e); }
  echo 'ok';
  exit;
}
$m = $u['message'] ?? null;
if (!$m) { echo 'ok'; exit; }

$from = $m['from'] ?? [];
$id = (int)($from['id'] ?? 0);
$chat = $m['chat']['id'] ?? null;
if (!in_array($id, $config['allowed_users'], true) || !$chat) { echo 'ok'; exit; }

$name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
save_member(['id'=>$id,'name'=>user_label($id, $name)]);

if (isset($m['photo']) && is_array($m['photo'])) {
  $largest = end($m['photo']);
  try { bot_handle_photo((int)$chat, $id, (string)$largest['file_id']); } catch (Throwable $e) { error_log((string)$e); }
  echo 'ok';
  exit;
}
if (isset($m['document'])) {
  try { bot_handle_document((int)$chat, $id, $m['document']); } catch (Throwable $e) { error_log((string)$e); }
  echo 'ok';
  exit;
}

$text = trim((string)($m['text'] ?? ''));
$url = rtrim($config['app_url'], '/');

try { handle_bot_command($chat, $id, $text, $url); }
catch (RuntimeException $e) { telegram('sendMessage', ['chat_id' => $chat, 'text' => '⚠️ ' . $e->getMessage()]); }

echo 'ok';
