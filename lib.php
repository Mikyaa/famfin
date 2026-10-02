<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Qyzylorda');

final class AuthException extends RuntimeException {}

function is_https(): bool {
  global $config;
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
  return str_starts_with((string)($config['app_url'] ?? ''), 'https://');
}

function db(): PDO {
  global $config; static $pdo;
  if ($pdo) return $pdo;
  $c = $config['db'];
  $driver = $c['driver'] ?? 'mysql';
  $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
  if ($driver === 'sqlite') {
    $dir = dirname($c['path']);
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
    $pdo->exec("CREATE TABLE IF NOT EXISTS members(telegram_id INTEGER PRIMARY KEY,display_name TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions(id INTEGER PRIMARY KEY AUTOINCREMENT,telegram_id INTEGER NOT NULL,kind TEXT NOT NULL CHECK(kind IN ('expense','topup')),amount NUMERIC NOT NULL,category TEXT NOT NULL,category_group TEXT NULL,note TEXT NOT NULL DEFAULT '',occurred_on TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(telegram_id) REFERENCES members(telegram_id));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_codes(id INTEGER PRIMARY KEY AUTOINCREMENT,telegram_id INTEGER NOT NULL,code TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,expires_at TEXT NOT NULL,used INTEGER DEFAULT 0);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts(ip TEXT PRIMARY KEY,attempts INTEGER DEFAULT 0,blocked_until TEXT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    migrate_sqlite($pdo);
    seed_local_demo($pdo);
  } else {
    $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}", $c['user'], $c['password'], $opts);
    migrate_mysql($pdo);
  }
  return $pdo;
}

function has_column(PDO $pdo, string $table, string $column): bool {
  if (is_sqlite()) {
    foreach ($pdo->query("PRAGMA table_info($table)") as $r) if ($r['name'] === $column) return true;
    return false;
  }
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
  $q->execute([$table, $column]);
  return (int)$q->fetchColumn() > 0;
}

function migrate_sqlite(PDO $pdo): void {
  if (!has_column($pdo, 'transactions', 'category_group')) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN category_group TEXT NULL;");
  }
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_date ON transactions(occurred_on);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_member_date ON transactions(telegram_id,occurred_on);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_group_date ON transactions(category_group,occurred_on);");
}

function migrate_mysql(PDO $pdo): void {
  if (!has_column($pdo, 'transactions', 'category_group')) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN category_group VARCHAR(16) NULL AFTER category");
    $pdo->exec("CREATE INDEX transactions_group_date_idx ON transactions(category_group, occurred_on)");
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS auth_codes(id INT AUTO_INCREMENT PRIMARY KEY,telegram_id BIGINT NOT NULL,code VARCHAR(6) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,expires_at TIMESTAMP NOT NULL,used TINYINT DEFAULT 0,INDEX(code,used,expires_at))");
  $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts(ip VARCHAR(45) PRIMARY KEY,attempts INT DEFAULT 0,blocked_until TIMESTAMP NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
}

function is_sqlite(): bool { global $config; return ($config['db']['driver'] ?? 'mysql') === 'sqlite'; }

function category_group_of(string $category, ?string $stored = null): string {
  global $config;
  if ($stored === 'fixed' || $stored === 'variable') return $stored;
  $groups = $config['category_groups'] ?? [];
  $fixed = $groups['fixed'] ?? [];
  if (in_array($category, $fixed, true)) return 'fixed';
  return 'variable';
}

function seed_local_demo(PDO $pdo): void {
  global $config;
  // Only register members so auth works on a fresh database
  $m = $pdo->prepare('INSERT OR IGNORE INTO members(telegram_id,display_name) VALUES(?,?)');
  foreach ($config['allowed_users'] as $u) {
    $m->execute([$u, user_label($u, 'User')]);
  }
}

function telegram(string $method, array $payload, int $timeout = 10): array {
  global $config;
  if (empty($config['bot_token'])) throw new RuntimeException('Настройте токен бота в config.php');
  $ch = curl_init('https://api.telegram.org/bot' . $config['bot_token'] . '/' . $method);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException($err); }
  curl_close($ch);
  return json_decode($raw, true) ?: [];
}

function json_out(array $data, int $status = 200): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function session_secret(): string {
  global $config;
  return hash_hmac('sha256', 'session-v1|' . ($config['bot_token'] ?? ''), $config['webhook_secret'] ?: 'family-budget');
}

function issue_session_cookie(int $telegram_id, string $name): void {
  $expires = time() + 60 * 60 * 24 * 30;
  $payload = $telegram_id . '|' . $expires . '|' . rawurlencode($name);
  $sig = hash_hmac('sha256', $payload, session_secret());
  $value = $payload . '|' . $sig;
  setcookie('fb_session', $value, [
    'expires' => $expires,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => is_https(),
  ]);
  $_COOKIE['fb_session'] = $value;
}

function read_session_cookie(): ?array {
  $raw = $_COOKIE['fb_session'] ?? '';
  if (!$raw) return null;
  $parts = explode('|', $raw);
  if (count($parts) !== 4) return null;
  [$id, $expires, $name, $sig] = $parts;
  $payload = $id . '|' . $expires . '|' . $name;
  if (!hash_equals(hash_hmac('sha256', $payload, session_secret()), $sig)) return null;
  if ((int)$expires < time()) return null;
  return ['id' => (int)$id, 'name' => rawurldecode($name)];
}

function verify_login_widget(array $data): ?array {
  global $config;
  $hash = $data['hash'] ?? '';
  if (!$hash || empty($config['bot_token'])) return null;
  unset($data['hash']);
  ksort($data);
  $pairs = [];
  foreach ($data as $k => $v) $pairs[] = "$k=$v";
  $secret = hash('sha256', $config['bot_token'], true);
  $check = hash_hmac('sha256', implode("\n", $pairs), $secret);
  if (!hash_equals($check, $hash)) return null;
  if (abs(time() - (int)($data['auth_date'] ?? 0)) > 900) return null;
  $id = (int)($data['id'] ?? 0);
  if (!in_array($id, $config['allowed_users'], true)) return null;
  return ['id' => $id, 'name' => user_label($id, trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')))];
}

function verify_miniapp_initdata(string $raw): ?array {
  global $config;
  if (!$raw || empty($config['bot_token'])) return null;
  parse_str($raw, $data);
  $hash = $data['hash'] ?? '';
  if (!$hash) return null;
  unset($data['hash']);
  ksort($data);
  $pairs = [];
  foreach ($data as $k => $v) $pairs[] = "$k=$v";
  $secret = hash_hmac('sha256', $config['bot_token'], 'WebAppData', true);
  $check = hash_hmac('sha256', implode("\n", $pairs), $secret);
  if (!hash_equals($check, $hash)) return null;
  if (abs(time() - (int)($data['auth_date'] ?? 0)) > 86400) return null;
  $u = json_decode($data['user'] ?? '{}', true) ?: [];
  $id = (int)($u['id'] ?? 0);
  if (!in_array($id, $config['allowed_users'], true)) return null;
  return ['id' => $id, 'name' => user_label($id, trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')))];
}

function user_label(int $id, string $fallback = ''): string {
  global $config;
  $labels = $config['user_labels'] ?? [];
  return $labels[$id] ?? ($fallback ?: 'Участник');
}

function auth_member(): array {
  global $config;
  $local = ($config['local_test_mode'] ?? false) === true
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
  if ($local) {
    $id = (int)($_SERVER['HTTP_X_BUDGET_TEST_USER'] ?? 854102139);
    if (in_array($id, $config['allowed_users'], true)) {
      return ['id' => $id, 'name' => user_label($id)];
    }
  }
  $initData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
  if ($initData && $initData !== 'LOCAL_TEST') {
    $m = verify_miniapp_initdata($initData);
    if ($m) return $m;
  }
  $sess = read_session_cookie();
  if ($sess && in_array($sess['id'], $config['allowed_users'], true)) return $sess;
  throw new AuthException('Откройте сайт через Telegram-бота или войдите через Telegram.');
}

function save_member(array $m): void {
  $pdo = db();
  if (is_sqlite()) {
    $q = $pdo->prepare('INSERT INTO members(telegram_id,display_name) VALUES(?,?) ON CONFLICT(telegram_id) DO UPDATE SET display_name=excluded.display_name');
  } else {
    $q = $pdo->prepare('INSERT INTO members(telegram_id,display_name) VALUES(?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name)');
  }
  $q->execute([$m['id'], $m['name']]);
}

function allowed_members_map(): array {
  global $config;
  $map = [];
  $ids = $config['allowed_users'];
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $q = db()->prepare("SELECT telegram_id,display_name FROM members WHERE telegram_id IN ($placeholders)");
  $q->execute(array_values($ids));
  foreach ($q as $r) $map[(int)$r['telegram_id']] = $r['display_name'];
  foreach ($ids as $id) if (!isset($map[$id])) $map[$id] = user_label($id);
  return $map;
}

function normalize_period(?string $from, ?string $to, ?string $preset): array {
  $today = new DateTimeImmutable('today');
  if ($from && $to) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      throw new RuntimeException('Неверный формат даты');
    }
    $f = new DateTimeImmutable($from);
    $t = new DateTimeImmutable($to);
    if ($f > $t) throw new RuntimeException('Дата начала позже даты конца');
    if ($f->diff($t)->days > 3660) throw new RuntimeException('Диапазон больше 10 лет');
    return [$f->format('Y-m-d'), $t->modify('+1 day')->format('Y-m-d'), 'custom'];
  }
  switch ($preset) {
    case 'today': return [$today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), 'today'];
    case 'year':  return [$today->modify('january this year')->format('Y-m-d'), $today->modify('january next year')->format('Y-m-d'), 'year'];
    case 'all':   return ['1970-01-01', $today->modify('+1 day')->format('Y-m-d'), 'all'];
    case 'month': return [$today->modify('first day of this month midnight')->format('Y-m-d'), $today->modify('first day of next month midnight')->format('Y-m-d'), 'month'];
    case 'week':
    default:      return [$today->modify('monday this week')->format('Y-m-d'), $today->modify('monday this week')->modify('+7 days')->format('Y-m-d'), 'week'];
  }
}

function balances_until(string $untilExclusive): array {
  global $config;
  $names = allowed_members_map();
  $per = [];
  foreach ($names as $id => $name) $per[(string)$id] = ['id'=>$id,'name'=>$name,'topups'=>0.0,'expenses'=>0.0,'balance'=>0.0,'count'=>0];
  $q = db()->prepare('SELECT telegram_id,kind,SUM(amount) total,COUNT(*) n FROM transactions WHERE occurred_on<? GROUP BY telegram_id,kind');
  $q->execute([$untilExclusive]);
  $totalTop = 0.0; $totalExp = 0.0;
  foreach ($q as $r) {
    $v = (float)$r['total'];
    $id = (string)$r['telegram_id'];
    if (!isset($per[$id])) continue;
    if ($r['kind'] === 'expense') { $per[$id]['expenses'] += $v; $totalExp += $v; }
    else { $per[$id]['topups'] += $v; $totalTop += $v; }
    $per[$id]['count'] += (int)$r['n'];
  }
  foreach ($per as &$p) $p['balance'] = $p['topups'] - $p['expenses'];
  unset($p);
  return [
    'currency' => $config['currency'],
    'until' => (new DateTimeImmutable($untilExclusive))->modify('-1 day')->format('Y-m-d'),
    'shared' => ['topups'=>$totalTop, 'expenses'=>$totalExp, 'balance'=>$totalTop - $totalExp],
    'members' => array_values($per),
  ];
}

function range_summary(string $from, string $until): array {
  global $config;
  $names = allowed_members_map();
  $members = [];
  foreach ($names as $id => $name) {
    $members[(string)$id] = ['id'=>$id,'name'=>$name,'expenses'=>0.0,'topups'=>0.0,'count'=>0,'categories'=>[],'fixed'=>0.0,'variable'=>0.0];
  }
  $q = db()->prepare('SELECT kind,telegram_id,category,COALESCE(category_group,\'\') cg,SUM(amount) total,COUNT(*) n FROM transactions WHERE occurred_on>=? AND occurred_on<? GROUP BY kind,telegram_id,category,category_group');
  $q->execute([$from, $until]);
  $expenses = 0.0; $topups = 0.0; $categories = []; $fixed = 0.0; $variable = 0.0; $count = 0;
  foreach ($q as $r) {
    $v = (float)$r['total'];
    $id = (string)$r['telegram_id'];
    $group = category_group_of($r['category'], $r['cg'] ?: null);
    if ($r['kind'] === 'expense') {
      $expenses += $v;
      $count += (int)$r['n'];
      $categories[$r['category']] = ($categories[$r['category']] ?? ['total'=>0.0,'group'=>$group,'count'=>0]);
      $categories[$r['category']]['total'] += $v;
      $categories[$r['category']]['count'] += (int)$r['n'];
      if ($group === 'fixed') $fixed += $v; else $variable += $v;
      if (isset($members[$id])) {
        $members[$id]['expenses'] += $v;
        $members[$id]['count'] += (int)$r['n'];
        $members[$id]['categories'][$r['category']] = ($members[$id]['categories'][$r['category']] ?? 0) + $v;
        if ($group === 'fixed') $members[$id]['fixed'] += $v; else $members[$id]['variable'] += $v;
      }
    } else {
      $topups += $v;
      if (isset($members[$id])) $members[$id]['topups'] += $v;
    }
  }
  uasort($categories, fn($a,$b) => $b['total'] <=> $a['total']);
  foreach ($members as &$mm) arsort($mm['categories']);
  unset($mm);
  return [
    'from' => $from,
    'to' => (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d'),
    'currency' => $config['currency'],
    'expenses' => $expenses,
    'topups' => $topups,
    'balance_change' => $topups - $expenses,
    'count' => $count,
    'fixed' => $fixed,
    'variable' => $variable,
    'members' => array_values($members),
    'categories' => $categories,
  ];
}

function previous_period(string $from, string $until): array {
  $f = new DateTimeImmutable($from);
  $u = new DateTimeImmutable($until);
  $days = (int)$f->diff($u)->days;
  return [$f->modify("-$days days")->format('Y-m-d'), $f->format('Y-m-d')];
}

function daily_series(string $from, string $until, int $cap = 180): array {
  $f = new DateTimeImmutable($from);
  $u = new DateTimeImmutable($until);
  $days = (int)$f->diff($u)->days;
  if ($days > $cap) $f = $u->modify("-$cap days");
  $series = [];
  for ($d = $f; $d < $u; $d = $d->modify('+1 day')) $series[$d->format('Y-m-d')] = ['date'=>$d->format('Y-m-d'),'expense'=>0.0,'topup'=>0.0];
  if (!$series) return [];
  $q = db()->prepare('SELECT occurred_on,kind,SUM(amount) total FROM transactions WHERE occurred_on>=? AND occurred_on<? GROUP BY occurred_on,kind');
  $q->execute([array_key_first($series), $until]);
  foreach ($q as $r) {
    $d = $r['occurred_on'];
    if (!isset($series[$d])) continue;
    $series[$d][$r['kind'] === 'expense' ? 'expense' : 'topup'] += (float)$r['total'];
  }
  return array_values($series);
}

function week_summary(): array { [$f,$u] = normalize_period(null,null,'week'); return range_summary($f,$u); }
function month_summary(): array { [$f,$u] = normalize_period(null,null,'month'); return range_summary($f,$u); }

function format_summary(array $s, string $title, ?array $balances = null): string {
  $c = $s['currency'];
  $body = "📊 $title {$s['from']} — {$s['to']}\n\n"
    . 'Расходы: ' . number_format($s['expenses'], 0, ',', ' ') . " $c\n"
    . 'Пополнения: ' . number_format($s['topups'], 0, ',', ' ') . " $c\n"
    . 'Изменение: ' . number_format($s['balance_change'], 0, ',', ' ') . " $c\n";
  if ($balances) {
    $body .= "\n💰 Остатки на {$balances['until']}:\n"
      . 'Общий: ' . number_format($balances['shared']['balance'], 0, ',', ' ') . " $c\n";
    foreach ($balances['members'] as $p) {
      $body .= $p['name'] . ': ' . number_format($p['balance'], 0, ',', ' ') . " $c\n";
    }
  }
  $body .= "\nЗа период по людям:\n";
  foreach ($s['members'] as $p) {
    $body .= $p['name'] . ': ' . number_format($p['expenses'], 0, ',', ' ') . " $c\n";
  }
  if ($s['categories']) {
    $body .= "\nТоп категорий:\n";
    $i = 0;
    foreach ($s['categories'] as $cat => $v) {
      $body .= $cat . ': ' . number_format($v['total'], 0, ',', ' ') . " $c\n";
      if (++$i >= 6) break;
    }
  }
  return $body;
}

function format_balances(array $b): string {
  $c = $b['currency'];
  $out = "💰 Остатки на {$b['until']}:\n\nОбщий баланс: " . number_format($b['shared']['balance'], 0, ',', ' ') . " $c\n\n";
  foreach ($b['members'] as $p) {
    $out .= $p['name'] . ': ' . number_format($p['balance'], 0, ',', ' ') . " $c\n";
  }
  return trim($out);
}

function validate_date_not_future(string $date): bool {
  $d = new DateTimeImmutable($date);
  $today = new DateTimeImmutable('today');
  return $d <= $today;
}

function generate_auth_code(int $telegram_id): string {
  $pdo = db();
  // Invalidate old codes for this user
  $pdo->prepare("UPDATE auth_codes SET used=1 WHERE telegram_id=? AND used=0")->execute([$telegram_id]);
  // Generate 6-digit code
  $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
  $expires = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
  $q = $pdo->prepare('INSERT INTO auth_codes(telegram_id,code,expires_at) VALUES(?,?,?)');
  $q->execute([$telegram_id, $code, $expires]);
  return $code;
}

function check_rate_limit(string $ip): ?string {
  $pdo = db();
  $q = $pdo->prepare('SELECT attempts, blocked_until FROM auth_attempts WHERE ip=?');
  $q->execute([$ip]);
  $row = $q->fetch();
  if (!$row) return null;
  if ($row['blocked_until'] && $row['blocked_until'] > date('Y-m-d H:i:s')) {
    $remaining = (new DateTimeImmutable($row['blocked_until']))->getTimestamp() - time();
    return "Слишком много попыток. Подождите " . max(1, $remaining) . " сек.";
  }
  // Reset if block expired
  if ($row['blocked_until'] && $row['blocked_until'] <= date('Y-m-d H:i:s')) {
    $pdo->prepare("UPDATE auth_attempts SET attempts=0, blocked_until=NULL WHERE ip=?")->execute([$ip]);
  }
  return null;
}

function record_failed_attempt(string $ip): ?string {
  $pdo = db();
  $now = date('Y-m-d H:i:s');
  if (is_sqlite()) {
    $pdo->prepare("INSERT INTO auth_attempts(ip,attempts,updated_at) VALUES(?,1,?) ON CONFLICT(ip) DO UPDATE SET attempts=attempts+1, updated_at=?")->execute([$ip, $now, $now]);
  } else {
    $pdo->prepare("INSERT INTO auth_attempts(ip,attempts,updated_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1, updated_at=VALUES(updated_at)")->execute([$ip, $now]);
  }
  $q = $pdo->prepare('SELECT attempts FROM auth_attempts WHERE ip=?');
  $q->execute([$ip]);
  $attempts = (int)$q->fetchColumn();
  // Global guard against distributed guessing: too many failures from any IPs burn all active codes
  if (is_sqlite()) {
    $pdo->prepare("INSERT INTO auth_attempts(ip,attempts,updated_at) VALUES('global',1,?) ON CONFLICT(ip) DO UPDATE SET attempts=attempts+1, updated_at=?")->execute([$now, $now]);
  } else {
    $pdo->prepare("INSERT INTO auth_attempts(ip,attempts,updated_at) VALUES('global',1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1, updated_at=VALUES(updated_at)")->execute([$now]);
  }
  $g = $pdo->query("SELECT attempts FROM auth_attempts WHERE ip='global'")->fetchColumn();
  if ((int)$g >= 30) {
    $pdo->exec("UPDATE auth_codes SET used=1 WHERE used=0");
    $pdo->exec("UPDATE auth_attempts SET attempts=0 WHERE ip='global'");
  }
  if ($attempts >= 5) {
    $blocked = (new DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s');
    $pdo->prepare("UPDATE auth_attempts SET blocked_until=?, attempts=0 WHERE ip=?")->execute([$blocked, $ip]);
    return "Слишком много попыток. Сессия заблокирована на 1 минуту.";
  }
  return null;
}

function clear_attempts(string $ip): void {
  db()->prepare("DELETE FROM auth_attempts WHERE ip=?")->execute([$ip]);
}

function verify_auth_code(string $code, string $ip): ?array {
  global $config;
  // Check rate limit
  $blocked = check_rate_limit($ip);
  if ($blocked) throw new RuntimeException($blocked);

  $pdo = db();
  $now = date('Y-m-d H:i:s');
  $q = $pdo->prepare('SELECT telegram_id FROM auth_codes WHERE code=? AND used=0 AND expires_at>?');
  $q->execute([$code, $now]);
  $row = $q->fetch();
  if (!$row) {
    $error = record_failed_attempt($ip);
    if ($error) throw new RuntimeException($error);
    return null;
  }
  $id = (int)$row['telegram_id'];
  if (!in_array($id, $config['allowed_users'], true)) return null;
  // Mark code as used
  $pdo->prepare("UPDATE auth_codes SET used=1 WHERE code=? AND telegram_id=?")->execute([$code, $id]);
  // Clear attempts on success
  clear_attempts($ip);
  clear_attempts('global');
  return ['id' => $id, 'name' => user_label($id)];
}

function handle_bot_command(int $chatId, int $userId, string $text, string $appUrl): void {
  global $config;
  if (!in_array($userId, $config['allowed_users'], true)) {
    telegram('sendMessage', ['chat_id' => $chatId, 'text' => 'Доступ запрещен.']);
    return;
  }
  // Strip bot username suffix from command
  $cmd = preg_replace('~@\w+$~', '', explode(' ', trim($text))[0]);
  switch ($cmd) {
    case '/start':
      $markup = ['inline_keyboard' => [
        [['text' => 'Открыть бюджет', 'web_app' => ['url' => $appUrl]]],
      ]];
      telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Семейный бюджет 💰\n\nДобавляйте расходы и пополнения, смотрите общий и личные итоги.\n\nОткройте Mini App кнопкой ниже или зайдите на сайт — вход через код из бота.",
        'reply_markup' => $markup
      ]);
      break;
    case '/login':
      $code = generate_auth_code($userId);
      telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "🔐 Код для входа на сайт:\n\n<b>$code</b>\n\nДействует 5 минут. Введите его на странице входа: $appUrl",
        'parse_mode' => 'HTML',
      ]);
      break;
    case '/balance':
      $b = balances_until((new DateTimeImmutable('tomorrow'))->format('Y-m-d'));
      telegram('sendMessage', ['chat_id' => $chatId, 'text' => format_balances($b)]);
      break;
    case '/week':
      $s = week_summary();
      $b = balances_until((new DateTimeImmutable('tomorrow'))->format('Y-m-d'));
      telegram('sendMessage', ['chat_id' => $chatId, 'text' => format_summary($s, 'Неделя', $b)]);
      break;
    case '/month':
      $s = month_summary();
      $b = balances_until((new DateTimeImmutable('tomorrow'))->format('Y-m-d'));
      telegram('sendMessage', ['chat_id' => $chatId, 'text' => format_summary($s, 'Месяц', $b)]);
      break;
    case '/help':
    case '/add':
    default:
      $markup = ['inline_keyboard' => [[['text' => 'Открыть бюджет', 'web_app' => ['url' => $appUrl]]]]];
      telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Команды:\n/start — открыть приложение\n/login — получить код для входа на сайт\n/balance — текущие остатки\n/week — сводка за неделю\n/month — сводка за месяц\n/help — эта справка\n\nРасходы и пополнения — в Mini App.",
        'reply_markup' => $markup,
      ]);
      break;
  }
}

