<?php
declare(strict_types=1);
// Quick entry for Siri / Shortcuts: GET or POST quick.php?t=<personal token>&text=кафе 5000
// Answers with plain text that Siri reads out; the bot also confirms in Telegram with buttons.
require __DIR__ . '/lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$text = trim((string)($_POST['text'] ?? $_GET['text'] ?? ''));
if ($text === '') {
  $raw = (string)file_get_contents('php://input');
  $json = json_decode($raw, true);
  $text = trim(is_array($json) ? (string)($json['text'] ?? '') : $raw);
}
$userId = quick_user($token);
if ($userId === null) { http_response_code(403); echo 'Ссылка недействительна. Возьмите новую в профиле бюджета.'; exit; }
// Widget feed: quick.php?t=…&mode=summary (text) or mode=json (Scriptable widget)
$mode = (string)($_GET['mode'] ?? '');
if ($mode === 'json') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(widget_feed(), JSON_UNESCAPED_UNICODE); exit; }
if ($mode === 'summary') { echo widget_text(); exit; }
if ($text === '' || mb_strlen($text) > 300) { http_response_code(422); echo 'Скажите, например: кафе пять тысяч'; exit; }
set_actor($userId);
try {
  echo handle_free_text($userId, $text, $userId);
} catch (RuntimeException $e) {
  http_response_code(422);
  echo $e->getMessage();
} catch (Throwable $e) {
  error_log((string)$e);
  http_response_code(500);
  echo 'Не получилось записать, попробуйте позже';
}
