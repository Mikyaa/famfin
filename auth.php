<?php
declare(strict_types=1);
require __DIR__.'/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($method === 'POST') {
  $d = json_decode(file_get_contents('php://input'), true) ?: [];
  $code = trim((string)($d['code'] ?? ''));

  if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['error' => 'Введите 6-значный код'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  try {
    $member = verify_auth_code($code, $ip);
    if (!$member) {
      http_response_code(401);
      echo json_encode(['error' => 'Неверный или просроченный код'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    save_member($member);
    issue_session_cookie($member['id'], $member['name']);
    echo json_encode(['ok' => true, 'name' => $member['name']], JSON_UNESCAPED_UNICODE);
  } catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

// GET — redirect to main page
header('Location: /');
exit;
