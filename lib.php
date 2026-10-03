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
    $pdo->exec("CREATE TABLE IF NOT EXISTS spend_limits(id INTEGER PRIMARY KEY AUTOINCREMENT,scope_id INTEGER NOT NULL DEFAULT 0,category TEXT NOT NULL,period TEXT NOT NULL,amount NUMERIC NOT NULL,created_on TEXT NOT NULL,updated_by INTEGER NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(scope_id,category,period));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS limit_notices(limit_id INTEGER NOT NULL,period_key TEXT NOT NULL,level INTEGER NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(limit_id,period_key,level));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY,v TEXT NOT NULL);");
    migrate_sqlite($pdo);
    features_schema($pdo);
    seed_local_demo($pdo);
  } else {
    $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}", $c['user'], $c['password'], $opts);
    migrate_mysql($pdo);
    features_schema($pdo);
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
  $pdo->exec("CREATE TABLE IF NOT EXISTS spend_limits(id INT AUTO_INCREMENT PRIMARY KEY,scope_id BIGINT NOT NULL DEFAULT 0,category VARCHAR(80) NOT NULL,period VARCHAR(8) NOT NULL,amount DECIMAL(14,2) NOT NULL,created_on DATE NOT NULL,updated_by BIGINT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY spend_limits_unique(scope_id,category,period)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS limit_notices(limit_id INT NOT NULL,period_key CHAR(10) NOT NULL,level TINYINT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(limit_id,period_key,level))");
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
  // Test hook: FAMFIN_DRY_TELEGRAM=1 prints calls instead of reaching real chats
  if (getenv('FAMFIN_DRY_TELEGRAM')) {
    fwrite(STDERR, "[dry $method] " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n");
    return ['ok' => true, 'result' => ['message_id' => random_int(1000, 9999)]];
  }
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
  // Transfers move money between members; goal savings leave the available balance
  $transfers = transfer_effects_until($untilExclusive);
  $saved = goal_moves_until($untilExclusive);
  $totalSaved = 0.0;
  foreach ($per as &$p) {
    $p['transfers'] = $transfers[$p['id']] ?? 0.0;
    $p['saved'] = $saved[$p['id']] ?? 0.0;
    $totalSaved += $p['saved'];
    $p['balance'] = $p['topups'] - $p['expenses'] + $p['transfers'] - $p['saved'];
  }
  unset($p);
  return [
    'currency' => $config['currency'],
    'until' => (new DateTimeImmutable($untilExclusive))->modify('-1 day')->format('Y-m-d'),
    'shared' => ['topups'=>$totalTop, 'expenses'=>$totalExp, 'saved'=>$totalSaved, 'balance'=>$totalTop - $totalExp - $totalSaved],
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

/* ========== SPENDING LIMITS ========== */
// A limit belongs to the family (scope_id 0) or to one member (scope_id = telegram id),
// covers a month or a Monday-based week, and never blocks an expense: it only drives
// progress, hints and Telegram alerts. Category '*' means all expenses.
// With rollover on, the previous period's leftover is added to the limit and an
// overspend is subtracted (one period back, not compounding).
const TOTAL_LIMIT = '*';
const WARN_PERCENTS = [10, 20, 30];
const LIMIT_PERIODS = ['month', 'week'];

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

function rollover_enabled(): bool { return get_setting('limit_rollover', '1') === '1'; }

function limit_label(string $category): string { return $category === TOTAL_LIMIT ? 'Все расходы' : $category; }

function period_window(string $period, DateTimeImmutable $d): array {
  if ($period === 'week') {
    $from = $d->modify('monday this week');
    return [$from->format('Y-m-d'), $from->modify('+7 days')->format('Y-m-d')];
  }
  $from = $d->modify('first day of this month');
  return [$from->format('Y-m-d'), $from->modify('first day of next month')->format('Y-m-d')];
}

function previous_window(string $period, string $from): array {
  $f = new DateTimeImmutable($from);
  return period_window($period, $period === 'week' ? $f->modify('-7 days') : $f->modify('-1 month'));
}

// [telegram_id][category] => sum of expenses in [from, until)
function spend_matrix(string $from, string $until): array {
  $q = db()->prepare("SELECT telegram_id,category,SUM(amount) total FROM transactions WHERE kind='expense' AND occurred_on>=? AND occurred_on<? GROUP BY telegram_id,category");
  $q->execute([$from, $until]);
  $m = [];
  foreach ($q as $r) $m[(int)$r['telegram_id']][(string)$r['category']] = (float)$r['total'];
  return $m;
}

function spent_for(array $matrix, int $scopeId, string $category): float {
  $sum = 0.0;
  foreach ($matrix as $member => $cats) {
    if ($scopeId !== 0 && $member !== $scopeId) continue;
    foreach ($cats as $cat => $v) if ($category === TOTAL_LIMIT || (string)$cat === $category) $sum += $v;
  }
  return $sum;
}

// 0 = fine, 1 = less than warn% left, 2 = exhausted or exceeded
function limit_level(float $spent, float $limit, int $warn): int {
  $remaining = $limit - $spent;
  if ($remaining <= 0) return 2;
  if ($remaining < $limit * $warn / 100) return 1;
  return 0;
}

function limit_rows(int $memberId): array {
  $q = db()->prepare('SELECT id,scope_id,category,period,amount,created_on FROM spend_limits WHERE scope_id IN (0,?)');
  $q->execute([$memberId]);
  return $q->fetchAll();
}

function category_groups_map(): array {
  global $config;
  $map = [];
  foreach (['fixed', 'variable'] as $g) foreach ($config['category_groups'][$g] ?? [] as $c) $map[$c] = $g;
  foreach (db()->query("SELECT category,MAX(COALESCE(category_group,'')) cg FROM transactions GROUP BY category") as $r) {
    if (!isset($map[$r['category']])) $map[(string)$r['category']] = category_group_of((string)$r['category'], $r['cg'] ?: null);
  }
  return $map;
}

// Family limits plus the given member's personal ones, for the periods containing $date
function limits_status(int $memberId, ?string $date = null): array {
  $d = new DateTimeImmutable($date ?? 'today');
  $warn = warn_percent();
  $rollover = rollover_enabled();
  $groups = category_groups_map();
  $matrices = [];
  $matrix = function(string $from, string $until) use (&$matrices) { return $matrices["$from|$until"] ??= spend_matrix($from, $until); };
  $items = [];
  foreach (limit_rows($memberId) as $r) {
    $period = $r['period'];
    $category = (string)$r['category'];
    $scopeId = (int)$r['scope_id'];
    [$from, $until] = period_window($period, $d);
    $base = (float)$r['amount'];
    $spent = spent_for($matrix($from, $until), $scopeId, $category);
    $carry = 0.0;
    if ($rollover && $r['created_on'] < $from) {
      [$pFrom, $pUntil] = previous_window($period, $from);
      $carry = $base - spent_for($matrix($pFrom, $pUntil), $scopeId, $category);
    }
    $limit = $base + $carry;
    $level = limit_level($spent, $limit, $warn);
    $items[] = [
      'id' => (int)$r['id'],
      'scope' => $scopeId === 0 ? 'family' : 'me',
      'category' => $category,
      'label' => limit_label($category),
      'group' => $category === TOTAL_LIMIT ? 'total' : ($groups[$category] ?? category_group_of($category)),
      'period' => $period,
      'from' => $from,
      'to' => (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d'),
      'base' => $base,
      'carry' => round($carry, 2),
      'limit' => round($limit, 2),
      'spent' => $spent,
      'remaining' => round($limit - $spent, 2),
      'percent' => $limit > 0 ? round($spent / $limit * 100, 1) : 100,
      'state' => ['ok', 'warn', 'over'][$level],
    ];
  }
  // Family before personal, month before week, the total first, then the most-used
  usort($items, fn($a, $b) => [$a['scope'] !== 'family', $a['period'] !== 'month', $a['category'] !== TOTAL_LIMIT, -$a['percent']]
    <=> [$b['scope'] !== 'family', $b['period'] !== 'month', $b['category'] !== TOTAL_LIMIT, -$b['percent']]);
  return ['warn_percent' => $warn, 'rollover' => $rollover, 'items' => $items];
}

function known_categories(): array {
  $out = [];
  foreach (category_groups_map() as $name => $group) $out[] = ['category' => (string)$name, 'group' => $group];
  foreach (db()->query("SELECT DISTINCT category FROM spend_limits WHERE category<>'*'") as $r) {
    if (!in_array((string)$r['category'], array_column($out, 'category'), true)) $out[] = ['category' => (string)$r['category'], 'group' => category_group_of((string)$r['category'])];
  }
  return $out;
}

function notify_member(int $id, string $text): void {
  global $config;
  // Never message real chats from a local test copy
  if (!empty($config['local_test_mode'])) { error_log("[notify $id] $text"); return; }
  try { telegram('sendMessage', ['chat_id' => $id, 'text' => $text], 5); } catch (Throwable $e) { error_log('notify failed: ' . $e->getMessage()); }
}

function notify_family(string $text, ?int $exceptId = null): void {
  global $config;
  foreach ($config['allowed_users'] as $id) if ($id !== $exceptId) notify_member($id, $text);
}

function limit_title(string $scope, string $category, string $period): string {
  $per = $period === 'week' ? 'на неделю' : 'на месяц';
  if ($category === TOTAL_LIMIT) return ($scope === 'me' ? 'Ваш личный общий лимит ' : 'Общий лимит ') . $per;
  return ($scope === 'me' ? 'Ваш личный лимит' : 'Лимит') . " «{$category}» $per";
}

// Replaces the family limits and the member's personal limits with $input
function save_limits(array $input, ?int $warn, ?bool $rollover, array $member): array {
  if (count($input) > 400) throw new RuntimeException('Слишком много лимитов');
  if ($warn !== null && !in_array($warn, WARN_PERCENTS, true)) throw new RuntimeException('Неверный порог предупреждения');
  $next = [];
  foreach ($input as $row) {
    if (!is_array($row)) throw new RuntimeException('Неверные данные лимита');
    $scope = $row['scope'] ?? '';
    $period = $row['period'] ?? '';
    $cat = trim((string)($row['category'] ?? ''));
    $amount = $row['amount'] ?? null;
    if (!in_array($scope, ['family', 'me'], true) || !in_array($period, LIMIT_PERIODS, true)) throw new RuntimeException('Неверный тип лимита');
    if ($cat === '' || mb_strlen($cat) > 80) throw new RuntimeException('Неверное название категории');
    if ($amount === null || $amount === '' || (is_numeric($amount) && (float)$amount == 0.0)) continue;
    $v = filter_var($amount, FILTER_VALIDATE_FLOAT);
    if ($v === false || $v < 1 || $v > 100000000) throw new RuntimeException('Лимит должен быть от 1 до 100 000 000');
    $scopeId = $scope === 'family' ? 0 : (int)$member['id'];
    $next["$scopeId|$period|$cat"] = [$scopeId, $period, $cat, round($v, 2)];
  }

  $current = [];
  foreach (limit_rows((int)$member['id']) as $r) $current["{$r['scope_id']}|{$r['period']}|{$r['category']}"] = $r;

  $pdo = db();
  $familyChanges = [];
  $count = 0;
  $line = fn($period, $cat, $text) => '• ' . limit_label($cat) . ($period === 'week' ? ' (неделя)' : ' (месяц)') . ": $text";
  $pdo->beginTransaction();
  try {
    $insert = $pdo->prepare('INSERT INTO spend_limits(scope_id,category,period,amount,created_on,updated_by) VALUES(?,?,?,?,?,?)');
    $update = $pdo->prepare('UPDATE spend_limits SET amount=?,updated_by=? WHERE id=?');
    $delete = $pdo->prepare('DELETE FROM spend_limits WHERE id=?');
    // A changed limit re-arms its alerts
    $rearm = $pdo->prepare('DELETE FROM limit_notices WHERE limit_id=?');
    foreach ($next as $key => [$scopeId, $period, $cat, $v]) {
      $old = $current[$key] ?? null;
      if ($old && abs((float)$old['amount'] - $v) < 0.005) continue;
      if ($old) { $update->execute([$v, $member['id'], $old['id']]); $rearm->execute([$old['id']]); }
      else $insert->execute([$scopeId, $cat, $period, $v, date('Y-m-d'), $member['id']]);
      $count++;
      if ($scopeId === 0) $familyChanges[] = $line($period, $cat, ($old ? fmt_money((float)$old['amount']) . ' → ' : '') . fmt_money($v));
    }
    foreach ($current as $key => $old) {
      if (isset($next[$key])) continue;
      $delete->execute([$old['id']]);
      $rearm->execute([$old['id']]);
      $count++;
      if ((int)$old['scope_id'] === 0) $familyChanges[] = $line($old['period'], (string)$old['category'], 'лимит снят');
    }
    if ($warn !== null && $warn !== warn_percent()) {
      set_setting('limit_warn_percent', (string)$warn);
      $pdo->exec('DELETE FROM limit_notices WHERE level=1');
      $familyChanges[] = "• Предупреждать, когда осталось меньше $warn%";
      $count++;
    }
    if ($rollover !== null && $rollover !== rollover_enabled()) {
      set_setting('limit_rollover', $rollover ? '1' : '0');
      $pdo->exec('DELETE FROM limit_notices');
      $familyChanges[] = $rollover ? '• Остаток переносится на следующий период' : '• Перенос остатка отключён';
      $count++;
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  if ($familyChanges) notify_family("⚙️ {$member['name']} изменил(а) лимиты:\n" . implode("\n", $familyChanges), (int)$member['id']);
  return ['changed' => $count];
}

// Called after an expense is saved. Sends at most one alert per limit, level and period;
// family limits alert everyone, personal ones only their owner.
function check_limit_alerts(string $category, string $date, array $member, float $amount): array {
  $status = limits_status((int)$member['id']);
  $relevant = array_values(array_filter($status['items'], fn($i) =>
    ($i['category'] === $category || $i['category'] === TOTAL_LIMIT) && $date >= $i['from'] && $date <= $i['to']));
  if (!$relevant) return [];
  $insert = db()->prepare(is_sqlite()
    ? 'INSERT OR IGNORE INTO limit_notices(limit_id,period_key,level) VALUES(?,?,?)'
    : 'INSERT IGNORE INTO limit_notices(limit_id,period_key,level) VALUES(?,?,?)');
  foreach ($relevant as $i) {
    $level = ['ok' => 0, 'warn' => 1, 'over' => 2][$i['state']];
    $fresh = 0;
    for ($l = 1; $l <= $level; $l++) {
      $insert->execute([$i['id'], $i['from'], $l]);
      if ($insert->rowCount() > 0) $fresh = $l;
    }
    if ($fresh === 0 || $fresh !== $level) continue;
    $name = limit_title($i['scope'], $i['category'], $i['period']);
    if ($level === 1) {
      $text = "⚠️ $name почти исчерпан\nОсталось " . fmt_money($i['remaining']) . ' из ' . fmt_money($i['limit']) . " (потрачено {$i['percent']}%).";
    } elseif ($i['remaining'] < 0) {
      $text = "🔴 $name превышен на " . fmt_money(-$i['remaining']) . "\nПотрачено " . fmt_money($i['spent']) . ' из ' . fmt_money($i['limit']) . '.';
    } else {
      $text = "🔴 $name исчерпан\nПотрачено " . fmt_money($i['spent']) . ' из ' . fmt_money($i['limit']) . '.';
    }
    if ($i['carry'] != 0) $text .= "\nС учётом переноса: " . ($i['carry'] > 0 ? '+' : '−') . fmt_money(abs($i['carry'])) . '.';
    $text .= "\nПоследняя трата: {$member['name']} — " . fmt_money($amount) . ($i['category'] === TOTAL_LIMIT ? " ($category)" : '');
    if ($i['scope'] === 'family') notify_family($text); else notify_member((int)$member['id'], $text);
  }
  return $relevant;
}

function format_limits(array $s): string {
  if (!$s['items']) return "Лимиты не настроены.\nОткройте бюджет → профиль (кнопка с буквой вверху) → «Лимиты».";
  $icons = ['ok' => '🟢', 'warn' => '🟡', 'over' => '🔴'];
  $out = "📏 Лимиты" . ($s['rollover'] ? ' (с переносом остатка)' : '') . ":\n";
  $section = '';
  foreach ($s['items'] as $i) {
    $head = ($i['scope'] === 'family' ? 'Семейные' : 'Мои личные') . ' · ' . ($i['period'] === 'week' ? 'неделя' : 'месяц') . " ({$i['from']} — {$i['to']})";
    if ($head !== $section) { $out .= "\n$head\n"; $section = $head; }
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
      telegram('sendMessage', ['chat_id' => $chatId, 'text' => format_limits(limits_status($userId))]);
      break;
    case '/backup':
      // Only the backup recipient may pull a copy on demand
      if ((string)$userId !== (string)($config['backup_chat_id'] ?? '')) {
        telegram('sendMessage', ['chat_id' => $chatId, 'text' => 'Копии приходят только владельцу резервных копий.']);
        break;
      }
      $b = make_backup();
      send_backup((string)$chatId, $b);
      break;
    case '/help':
    case '/add':
      bot_send_help($chatId, $appUrl);
      break;
    default:
      if ($text !== '' && $text[0] !== '/' && bot_handle_text($chatId, $userId, $text)) break;
      $markup = ['inline_keyboard' => [[['text' => 'Открыть бюджет', 'web_app' => ['url' => $appUrl]]]]];
      telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Не понял сообщение 🙂\n\nЧтобы записать трату, напишите сумму и на что: «кафе 5000», «12 300 продукты магнум», «вчера такси 1800». Пополнение — с плюсом: «+350 000 зарплата».\n\n/help — все команды",
        'reply_markup' => $markup,
      ]);
      break;
  }
}

function bot_send_help(int $chatId, string $appUrl): void {
  global $config;
  $text = "Как записывать:\n• «кафе 5000» — расход, категория определится сама\n• «12 300 продукты магнум» — с комментарием\n• «вчера такси 1800» или «28.09 аптека 4500» — с датой\n• «+350 000 зарплата» — пополнение\nПосле записи можно сменить категорию или отменить кнопкой.\n\n📄 Пришлите PDF-выписку Kaspi Gold — разберу и предложу импорт покупок.\n\nКоманды:\n/balance — текущие остатки\n/week — сводка за неделю\n/month — сводка за месяц\n/limits — лимиты\n/login — код для входа на сайт\n/start — открыть приложение";
  if (!empty($config['backup_chat_id'])) $text .= "\n/backup — резервная копия (только владельцу)";
  telegram('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => ['inline_keyboard' => [[['text' => 'Открыть бюджет', 'web_app' => ['url' => $appUrl]]]]]]);
}

require __DIR__ . '/features.php';
require __DIR__ . '/bot.php';

