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
    $pdo->exec("CREATE TABLE IF NOT EXISTS category_limits(category TEXT PRIMARY KEY,amount NUMERIC NOT NULL,updated_by INTEGER NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS limit_alerts(category TEXT NOT NULL,period TEXT NOT NULL,level INTEGER NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(category,period,level));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY,v TEXT NOT NULL);");
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
  $pdo->exec("CREATE TABLE IF NOT EXISTS category_limits(category VARCHAR(80) PRIMARY KEY,amount DECIMAL(14,2) NOT NULL,updated_by BIGINT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS limit_alerts(category VARCHAR(80) NOT NULL,period CHAR(7) NOT NULL,level TINYINT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(category,period,level)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings(k VARCHAR(40) PRIMARY KEY,v VARCHAR(255) NOT NULL)");
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

/* ========== CATEGORY LIMITS ========== */
// Monthly spending limits shared by the whole family. A limit never blocks an expense;
// it only drives progress, hints and Telegram alerts. '*' is the limit on all expenses.
const TOTAL_LIMIT = '*';
const WARN_PERCENTS = [10, 20, 30];

function currency_symbol(): string {
  global $config;
  $c = $config['currency'] ?? 'KZT';
  return ['KZT'=>'₸','RUB'=>'₽','USD'=>'$','EUR'=>'€'][$c] ?? $c;
}

function fmt_money(float $v): string { return number_format($v, 0, ',', ' ') . ' ' . currency_symbol(); }

function get_setting(string $k, ?string $default = null): ?string {
  $q = db()->prepare('SELECT v FROM settings WHERE k=?');
  $q->execute([$k]);
  $v = $q->fetchColumn();
  return $v === false ? $default : (string)$v;
}

function set_setting(string $k, string $v): void {
  $sql = is_sqlite()
    ? 'INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v'
    : 'INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)';
  db()->prepare($sql)->execute([$k, $v]);
}

function warn_percent(): int {
  $v = (int)get_setting('limit_warn_percent', '20');
  return in_array($v, WARN_PERCENTS, true) ? $v : 20;
}

function get_limits(): array {
  $out = [];
  foreach (db()->query('SELECT category,amount FROM category_limits') as $r) $out[$r['category']] = (float)$r['amount'];
  return $out;
}

function limit_label(string $category): string { return $category === TOTAL_LIMIT ? 'Все расходы' : $category; }

// 0 = fine, 1 = less than warn% left, 2 = exhausted or exceeded
function limit_level(float $spent, float $limit, int $warn): int {
  $remaining = $limit - $spent;
  if ($remaining <= 0) return 2;
  if ($remaining < $limit * $warn / 100) return 1;
  return 0;
}

function limits_status(?string $date = null): array {
  $limits = get_limits();
  $d = new DateTimeImmutable($date ?? 'today');
  $from = $d->modify('first day of this month')->format('Y-m-d');
  $until = $d->modify('first day of next month')->format('Y-m-d');
  $warn = warn_percent();
  $spent = [];
  $groups = [];
  $total = 0.0;
  if ($limits) {
    $q = db()->prepare("SELECT category,MAX(COALESCE(category_group,'')) cg,SUM(amount) total FROM transactions WHERE kind='expense' AND occurred_on>=? AND occurred_on<? GROUP BY category");
    $q->execute([$from, $until]);
    foreach ($q as $r) {
      $spent[$r['category']] = (float)$r['total'];
      $groups[$r['category']] = $r['cg'] ?: null;
      $total += (float)$r['total'];
    }
  }
  $items = [];
  foreach ($limits as $cat => $limit) {
    $s = $cat === TOTAL_LIMIT ? $total : ($spent[$cat] ?? 0.0);
    $level = limit_level($s, $limit, $warn);
    $items[] = [
      'category' => $cat,
      'label' => limit_label($cat),
      'group' => $cat === TOTAL_LIMIT ? 'total' : category_group_of($cat, $groups[$cat] ?? null),
      'limit' => $limit,
      'spent' => $s,
      'remaining' => $limit - $s,
      'percent' => $limit > 0 ? round($s / $limit * 100, 1) : 0,
      'state' => ['ok', 'warn', 'over'][$level],
    ];
  }
  // Total first, then the most-used limits
  usort($items, fn($a, $b) => [$b['category'] === TOTAL_LIMIT, $b['percent']] <=> [$a['category'] === TOTAL_LIMIT, $a['percent']]);
  return [
    'period' => substr($from, 0, 7),
    'from' => $from,
    'to' => (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d'),
    'warn_percent' => $warn,
    'items' => $items,
  ];
}

function known_categories(): array {
  global $config;
  $cats = [];
  foreach (['fixed', 'variable'] as $g) foreach ($config['category_groups'][$g] ?? [] as $c) $cats[$c] = $g;
  foreach (db()->query("SELECT category,MAX(COALESCE(category_group,'')) cg FROM transactions WHERE kind='expense' GROUP BY category") as $r) {
    if (!isset($cats[$r['category']])) $cats[$r['category']] = category_group_of($r['category'], $r['cg'] ?: null);
  }
  foreach (array_keys(get_limits()) as $c) if ($c !== TOTAL_LIMIT && !isset($cats[$c])) $cats[$c] = category_group_of($c);
  $out = [];
  foreach ($cats as $name => $group) $out[] = ['category' => (string)$name, 'group' => $group];
  return $out;
}

function notify_family(string $text, ?int $exceptId = null): void {
  global $config;
  foreach ($config['allowed_users'] as $id) {
    if ($id === $exceptId) continue;
    // Never message real chats from a local test copy
    if (!empty($config['local_test_mode'])) { error_log("[notify $id] $text"); continue; }
    try { telegram('sendMessage', ['chat_id' => $id, 'text' => $text], 5); } catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
  }
}

function save_limits(array $input, ?int $warn, array $member): array {
  if (count($input) > 200) throw new RuntimeException('Слишком много лимитов');
  $current = get_limits();
  $next = [];
  foreach ($input as $cat => $amount) {
    $cat = trim((string)$cat);
    if ($cat === '' || mb_strlen($cat) > 80) throw new RuntimeException('Неверное название категории');
    if ($amount === null || $amount === '' || (is_numeric($amount) && (float)$amount == 0.0)) continue;
    $v = filter_var($amount, FILTER_VALIDATE_FLOAT);
    if ($v === false || $v < 1 || $v > 100000000) throw new RuntimeException('Лимит должен быть от 1 до 100 000 000');
    $next[$cat] = round($v, 2);
  }
  if ($warn !== null && !in_array($warn, WARN_PERCENTS, true)) throw new RuntimeException('Неверный порог предупреждения');

  $pdo = db();
  $period = date('Y-m');
  $changes = [];
  $pdo->beginTransaction();
  try {
    $upsert = $pdo->prepare(is_sqlite()
      ? 'INSERT INTO category_limits(category,amount,updated_by,updated_at) VALUES(?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(category) DO UPDATE SET amount=excluded.amount,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP'
      : 'INSERT INTO category_limits(category,amount,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount),updated_by=VALUES(updated_by)');
    $del = $pdo->prepare('DELETE FROM category_limits WHERE category=?');
    // A changed limit re-arms its alerts for the current month
    $rearm = $pdo->prepare('DELETE FROM limit_alerts WHERE category=? AND period=?');
    foreach ($next as $cat => $v) {
      if (isset($current[$cat]) && abs($current[$cat] - $v) < 0.005) continue;
      $upsert->execute([$cat, $v, $member['id']]);
      $rearm->execute([$cat, $period]);
      $changes[] = isset($current[$cat])
        ? '• ' . limit_label($cat) . ': ' . fmt_money($current[$cat]) . ' → ' . fmt_money($v)
        : '• ' . limit_label($cat) . ': ' . fmt_money($v);
    }
    foreach ($current as $cat => $v) {
      if (isset($next[$cat])) continue;
      $del->execute([$cat]);
      $rearm->execute([$cat, $period]);
      $changes[] = '• ' . limit_label((string)$cat) . ': лимит снят';
    }
    if ($warn !== null && $warn !== warn_percent()) {
      set_setting('limit_warn_percent', (string)$warn);
      $pdo->prepare('DELETE FROM limit_alerts WHERE period=? AND level=1')->execute([$period]);
      $changes[] = "• Предупреждать, когда осталось меньше $warn%";
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  if ($changes) notify_family("⚙️ {$member['name']} изменил(а) лимиты на месяц:\n" . implode("\n", $changes), $member['id']);
  return $changes;
}

// Called after an expense is saved. Sends at most one alert per limit, level and month.
function check_limit_alerts(string $category, string $date, array $member, float $amount): array {
  $status = limits_status($date);
  $relevant = array_values(array_filter($status['items'], fn($i) => $i['category'] === $category || $i['category'] === TOTAL_LIMIT));
  if (!$relevant || substr($date, 0, 7) !== date('Y-m')) return $relevant;
  $insert = db()->prepare(is_sqlite()
    ? 'INSERT OR IGNORE INTO limit_alerts(category,period,level) VALUES(?,?,?)'
    : 'INSERT IGNORE INTO limit_alerts(category,period,level) VALUES(?,?,?)');
  foreach ($relevant as $i) {
    $level = ['ok' => 0, 'warn' => 1, 'over' => 2][$i['state']];
    $fresh = 0;
    for ($l = 1; $l <= $level; $l++) {
      $insert->execute([$i['category'], $status['period'], $l]);
      if ($insert->rowCount() > 0) $fresh = $l;
    }
    if ($fresh === 0 || $fresh !== $level) continue;
    $name = $i['category'] === TOTAL_LIMIT ? 'Общий лимит на месяц' : "Лимит «{$i['label']}»";
    if ($level === 1) {
      $text = "⚠️ $name почти исчерпан\nОсталось " . fmt_money($i['remaining']) . ' из ' . fmt_money($i['limit']) . " (потрачено {$i['percent']}%).";
    } elseif ($i['remaining'] < 0) {
      $text = "🔴 $name превышен на " . fmt_money(-$i['remaining']) . "\nПотрачено " . fmt_money($i['spent']) . ' из ' . fmt_money($i['limit']) . '.';
    } else {
      $text = "🔴 $name исчерпан\nПотрачено " . fmt_money($i['spent']) . ' из ' . fmt_money($i['limit']) . '.';
    }
    $text .= "\nПоследняя трата: {$member['name']} — " . fmt_money($amount) . ($i['category'] === TOTAL_LIMIT ? " ($category)" : '');
    notify_family($text);
  }
  return $relevant;
}

function format_limits(array $s): string {
  if (!$s['items']) return "Лимиты не настроены.\nОткройте бюджет → профиль (кнопка с буквой вверху) → «Лимиты на месяц».";
  $icons = ['ok' => '🟢', 'warn' => '🟡', 'over' => '🔴'];
  $out = "📏 Лимиты на {$s['from']} — {$s['to']}:\n\n";
  foreach ($s['items'] as $i) {
    $tail = $i['remaining'] >= 0 ? 'осталось ' . fmt_money($i['remaining']) : 'превышен на ' . fmt_money(-$i['remaining']);
    $out .= $icons[$i['state']] . ' ' . $i['label'] . ': ' . fmt_money($i['spent']) . ' / ' . fmt_money($i['limit']) . " — $tail\n";
  }
  return trim($out);
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
    case '/limits':
      telegram('sendMessage', ['chat_id' => $chatId, 'text' => format_limits(limits_status())]);
      break;
    case '/help':
    case '/add':
    default:
      $markup = ['inline_keyboard' => [[['text' => 'Открыть бюджет', 'web_app' => ['url' => $appUrl]]]]];
      telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Команды:\n/start — открыть приложение\n/login — получить код для входа на сайт\n/balance — текущие остатки\n/week — сводка за неделю\n/month — сводка за месяц\n/limits — лимиты по категориям\n/help — эта справка\n\nРасходы и пополнения — в Mini App.",
        'reply_markup' => $markup,
      ]);
      break;
  }
}

