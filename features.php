<?php
declare(strict_types=1);
// Family budget features built on top of lib.php: shared entry validation, editing,
// transfers between members, savings goals, recurring payments, month forecast,
// search, bank statement import, backups and the monthly summary.

/* ========== SCHEMA ========== */
function features_schema(PDO $pdo): void {
  if (is_sqlite()) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS transfers(id INTEGER PRIMARY KEY AUTOINCREMENT,from_id INTEGER NOT NULL,to_id INTEGER NOT NULL,amount NUMERIC NOT NULL,note TEXT NOT NULL DEFAULT '',occurred_on TEXT NOT NULL,created_by INTEGER NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS goals(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,target NUMERIC NOT NULL,deadline TEXT NULL,archived INTEGER NOT NULL DEFAULT 0,created_by INTEGER NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS goal_moves(id INTEGER PRIMARY KEY AUTOINCREMENT,goal_id INTEGER NOT NULL,telegram_id INTEGER NOT NULL,amount NUMERIC NOT NULL,occurred_on TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recurring(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT NOT NULL DEFAULT 'expense',amount NUMERIC NOT NULL,category TEXT NOT NULL,category_group TEXT NULL,note TEXT NOT NULL DEFAULT '',day INTEGER NOT NULL,payer_id INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1,done_period TEXT NULL,skipped_period TEXT NULL,reminded_period TEXT NULL,created_by INTEGER NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_actions(id INTEGER PRIMARY KEY AUTOINCREMENT,telegram_id INTEGER NOT NULL,payload TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    if (!has_column($pdo, 'transactions', 'recurring_id')) $pdo->exec("ALTER TABLE transactions ADD COLUMN recurring_id INTEGER NULL;");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_transfers_date ON transfers(occurred_on);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_goal_moves_goal ON goal_moves(goal_id);");
    statement_jobs_schema($pdo);
    return;
  }
  $cs = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS transfers(id BIGINT AUTO_INCREMENT PRIMARY KEY,from_id BIGINT NOT NULL,to_id BIGINT NOT NULL,amount DECIMAL(14,2) NOT NULL,note VARCHAR(500) NOT NULL DEFAULT '',occurred_on DATE NOT NULL,created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(occurred_on)) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS goals(id BIGINT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(80) NOT NULL,target DECIMAL(14,2) NOT NULL,deadline DATE NULL,archived TINYINT NOT NULL DEFAULT 0,created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS goal_moves(id BIGINT AUTO_INCREMENT PRIMARY KEY,goal_id BIGINT NOT NULL,telegram_id BIGINT NOT NULL,amount DECIMAL(14,2) NOT NULL,occurred_on DATE NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(goal_id)) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS recurring(id BIGINT AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(8) NOT NULL DEFAULT 'expense',amount DECIMAL(14,2) NOT NULL,category VARCHAR(80) NOT NULL,category_group VARCHAR(16) NULL,note VARCHAR(500) NOT NULL DEFAULT '',day TINYINT NOT NULL,payer_id BIGINT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1,done_period CHAR(7) NULL,skipped_period CHAR(7) NULL,reminded_period CHAR(7) NULL,created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS bot_actions(id BIGINT AUTO_INCREMENT PRIMARY KEY,telegram_id BIGINT NOT NULL,payload TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $cs");
  if (!has_column($pdo, 'transactions', 'recurring_id')) $pdo->exec("ALTER TABLE transactions ADD COLUMN recurring_id BIGINT NULL");
  statement_jobs_schema($pdo);
}

function mark_balance_changed(): void { set_setting('balance_widget_dirty', '1'); }

function is_member_id(int $id): bool { global $config; return in_array($id, $config['allowed_users'], true); }

function valid_date(string $date, bool $allowFuture = false): bool {
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return false;
  return $allowFuture || $date <= date('Y-m-d');
}

function parse_amount($v): ?float {
  if (is_string($v)) $v = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $v);
  $f = filter_var($v, FILTER_VALIDATE_FLOAT);
  if ($f === false || $f <= 0 || $f > 100000000) return null;
  return round($f, 2);
}

/* ========== ENTRIES ========== */
// Normalises a new or edited operation; throws with a user-facing message
function validate_entry(array $d): array {
  $kind = $d['kind'] ?? '';
  $amount = parse_amount($d['amount'] ?? null);
  $category = trim((string)($d['custom_category'] ?? '')) ?: trim((string)($d['category'] ?? ''));
  $note = trim((string)($d['note'] ?? ''));
  $date = (string)($d['date'] ?? date('Y-m-d'));
  if (!in_array($kind, ['expense', 'topup'], true) || $amount === null || $category === '' || mb_strlen($category) > 80
      || mb_strlen($note) > 500 || !valid_date($date)) {
    throw new RuntimeException('Проверьте сумму, дату и категорию');
  }
  $groupInput = $d['category_group'] ?? null;
  $group = in_array($groupInput, ['fixed', 'variable'], true) ? $groupInput : category_group_of($category, stored_category_group($category));
  return ['kind' => $kind, 'amount' => $amount, 'category' => $category, 'group' => $group, 'note' => $note, 'date' => $date];
}

function stored_category_group(string $category): ?string {
  $q = db()->prepare("SELECT category_group FROM transactions WHERE category=? AND category_group IS NOT NULL ORDER BY id DESC LIMIT 1");
  $q->execute([$category]);
  $g = $q->fetchColumn();
  return $g === false ? null : (string)$g;
}

function insert_transaction(int $memberId, array $e, ?int $recurringId = null): int {
  $q = db()->prepare('INSERT INTO transactions(telegram_id,kind,amount,category,category_group,note,occurred_on,recurring_id) VALUES(?,?,?,?,?,?,?,?)');
  $q->execute([$memberId, $e['kind'], $e['amount'], $e['category'], $e['group'], $e['note'], $e['date'], $recurringId]);
  mark_balance_changed();
  return (int)db()->lastInsertId();
}

function get_transaction(int $id): ?array {
  $q = db()->prepare('SELECT * FROM transactions WHERE id=?');
  $q->execute([$id]);
  $r = $q->fetch();
  return $r ?: null;
}

// Any family member can correct any operation, including who paid
function update_transaction(int $id, array $d): array {
  $old = get_transaction($id);
  if (!$old) throw new RuntimeException('Запись не найдена');
  $e = validate_entry($d);
  $payer = isset($d['payer_id']) ? (int)$d['payer_id'] : (int)$old['telegram_id'];
  if (!is_member_id($payer)) throw new RuntimeException('Неверный участник');
  db()->prepare('UPDATE transactions SET telegram_id=?,kind=?,amount=?,category=?,category_group=?,note=?,occurred_on=? WHERE id=?')
    ->execute([$payer, $e['kind'], $e['amount'], $e['category'], $e['group'], $e['note'], $e['date'], $id]);
  mark_balance_changed();
  return $e + ['id' => $id, 'payer_id' => $payer];
}

function delete_transaction(int $id): bool {
  $q = db()->prepare('DELETE FROM transactions WHERE id=?');
  $q->execute([$id]);
  if ($q->rowCount() > 0) { mark_balance_changed(); return true; }
  return false;
}

/* ========== CATEGORY GUESSING ========== */
// Keyword stems → default categories; used by the bot and the statement import
const CATEGORY_KEYWORDS = [
  'Продукты' => ['продукт', 'магнум', 'magnum', 'small', 'смолл', 'галмарт', 'galmart', 'анвар', 'супермаркет', 'гипермаркет', 'овощ', 'фрукт', 'хлеб', 'молок', 'мясо', 'рынок', 'базар', 'arbuz', 'арбуз', 'airba fresh', 'grocery', 'метро кэш', 'metro cash', 'toimart', 'дикси', 'ашан', 'еда домой', 'продукты'],
  'Кафе и рестораны' => ['кафе', 'ресторан', 'кофе', 'coffee', 'кофейн', 'обед', 'ужин', 'завтрак', 'бар ', 'пицц', 'pizza', 'суши', 'sushi', 'роллы', 'бургер', 'burger', 'kfc', 'mcdonald', 'макдон', 'шаурм', 'донер', 'wolt', 'glovo', 'chocofood', 'яндекс еда', 'yandex eda', 'starbucks', 'столов', 'фастфуд', 'доставка еды', 'чайхан', 'кальян'],
  'Транспорт' => ['такси', 'taxi', 'яндекс го', 'yandex.go', 'yandex go', 'uber', 'indriver', 'индрайв', 'бензин', 'азс', 'заправк', 'топливо', 'helios', 'гелиос', 'sinooil', 'qazaq oil', 'парковк', 'parking', 'автобус', 'метро', 'onay', 'онай', 'проезд', 'автомойк', 'шиномонтаж', 'сто ', 'техосмотр'],
  'Дом' => ['коммунал', 'квартплат', 'аренд', 'электроэнерг', 'свет за', 'газ ', 'вода', 'отоплен', 'ремонт', 'мебел', 'посуд', 'бытов', 'хозтовар', 'leroy', 'леруа', 'икеа', 'ikea', 'химчистк', 'клининг', 'кск', 'осмд', 'алсеко', 'alseco'],
  'Здоровье' => ['аптек', 'apteka', 'pharm', 'врач', 'клиник', 'больниц', 'анализ', 'invitro', 'инвитро', 'олимп', 'стоматолог', 'зубн', 'лекарств', 'витамин', 'медицин', 'массаж', 'окулист'],
  'Дети' => ['детск', 'ребен', 'ребён', 'сад ', 'садик', 'школ', 'игрушк', 'памперс', 'подгузн', 'кружок', 'секци', 'репетитор', 'няня', 'детский мир'],
  'Подписки' => ['подписк', 'netflix', 'spotify', 'youtube', 'яндекс плюс', 'yandex plus', 'icloud', 'apple.com', 'google one', 'chatgpt', 'openai', 'claude', 'интернет', 'связь', 'мобильн', 'телефон', 'beeline', 'билайн', 'kcell', 'activ', 'tele2', 'altel', 'kazakhtelecom', 'казахтелеком', 'ivi', 'okko', 'кинопоиск'],
  'Кредиты' => ['кредит', 'рассрочк', 'ипотек', 'kaspi red', 'каспи ред', 'red ', 'займ', 'долг', 'погашен'],
  'Покупки' => ['одежд', 'обув', 'wildberries', 'вайлдберриз', 'ozon', 'озон', 'kaspi магазин', 'kaspi.kz магазин', 'техник', 'электроник', 'sulpak', 'сулпак', 'technodom', 'технодом', 'mechta', 'мечта', 'zara', 'lc waikiki', 'косметик', 'парфюм', 'подарок', 'подарк', 'aliexpress', 'temu', 'покупк', 'магазин'],
  'Путешествия' => ['билет', 'авиа', 'air astana', 'эйр астана', 'fly arystan', 'scat', 'отель', 'hotel', 'гостиниц', 'booking', 'airbnb', 'поезд', 'жд ', 'тур ', 'путешеств', 'виза', 'отпуск'],
  'Развлечения' => ['кино', 'cinema', 'kinopark', 'кинопарк', 'chaplin', 'концерт', 'театр', 'боулинг', 'бильярд', 'квест', 'игр', 'steam', 'playstation', 'развлеч', 'парк ', 'аттракцион', 'музей', 'клуб'],
];

function categorize_text(string $text): ?string {
  $t = ' ' . mb_strtolower(trim($text)) . ' ';
  if (trim($t) === '') return null;
  // Exact or prefix match with a category the family already uses (incl. custom ones)
  foreach (known_categories() as $c) {
    $name = mb_strtolower($c['category']);
    if (mb_strlen($name) >= 3 && (str_contains($t, ' ' . $name . ' ') || str_contains($t, ' ' . mb_substr($name, 0, max(4, mb_strlen($name) - 2))))) return $c['category'];
  }
  foreach (CATEGORY_KEYWORDS as $category => $words) {
    foreach ($words as $w) if (str_contains($t, $w)) return $category;
  }
  return null;
}

/* ========== TRANSFERS ========== */
// Money handed from one member to the other: personal balances move, the shared one does not
function add_transfer(int $from, int $to, $amount, string $note, string $date, int $createdBy): int {
  $a = parse_amount($amount);
  $note = trim($note);
  if (!is_member_id($from) || !is_member_id($to) || $from === $to) throw new RuntimeException('Выберите, кто кому передал');
  if ($a === null || !valid_date($date) || mb_strlen($note) > 500) throw new RuntimeException('Проверьте сумму и дату перевода');
  db()->prepare('INSERT INTO transfers(from_id,to_id,amount,note,occurred_on,created_by) VALUES(?,?,?,?,?,?)')->execute([$from, $to, $a, $note, $date, $createdBy]);
  mark_balance_changed();
  return (int)db()->lastInsertId();
}

function delete_transfer(int $id): bool {
  $q = db()->prepare('DELETE FROM transfers WHERE id=?');
  $q->execute([$id]);
  if ($q->rowCount() > 0) { mark_balance_changed(); return true; }
  return false;
}

function list_transfers(int $limit = 10): array {
  $names = allowed_members_map();
  $q = db()->prepare('SELECT id,from_id,to_id,amount,note,occurred_on FROM transfers ORDER BY occurred_on DESC,id DESC LIMIT ' . max(1, min(200, $limit)));
  $q->execute();
  $out = [];
  foreach ($q as $r) {
    $out[] = ['id' => (int)$r['id'], 'type' => 'transfer', 'amount' => (float)$r['amount'], 'note' => $r['note'], 'occurred_on' => $r['occurred_on'],
      'from_id' => (int)$r['from_id'], 'to_id' => (int)$r['to_id'], 'from_name' => $names[(int)$r['from_id']] ?? '?', 'to_name' => $names[(int)$r['to_id']] ?? '?'];
  }
  return $out;
}

// [member id => net change of personal balance] from transfers before $until
function transfer_effects_until(string $until): array {
  $q = db()->prepare('SELECT from_id,to_id,amount FROM transfers WHERE occurred_on<?');
  $q->execute([$until]);
  $fx = [];
  foreach ($q as $r) {
    $fx[(int)$r['from_id']] = ($fx[(int)$r['from_id']] ?? 0) - (float)$r['amount'];
    $fx[(int)$r['to_id']] = ($fx[(int)$r['to_id']] ?? 0) + (float)$r['amount'];
  }
  return $fx;
}

/* ========== GOALS ========== */
// Money put into a goal leaves the available balance of the member who put it in
function goal_moves_until(string $until): array {
  $q = db()->prepare('SELECT telegram_id,SUM(amount) total FROM goal_moves WHERE occurred_on<? GROUP BY telegram_id');
  $q->execute([$until]);
  $out = [];
  foreach ($q as $r) $out[(int)$r['telegram_id']] = (float)$r['total'];
  return $out;
}

function goals_list(bool $withArchived = false): array {
  $names = allowed_members_map();
  $goals = db()->query('SELECT id,title,target,deadline,archived,created_at FROM goals' . ($withArchived ? '' : ' WHERE archived=0') . ' ORDER BY archived,id')->fetchAll();
  $moves = [];
  foreach (db()->query('SELECT goal_id,telegram_id,SUM(amount) total FROM goal_moves GROUP BY goal_id,telegram_id') as $r) $moves[(int)$r['goal_id']][(int)$r['telegram_id']] = (float)$r['total'];
  $today = new DateTimeImmutable('today');
  $out = [];
  foreach ($goals as $g) {
    $id = (int)$g['id'];
    $saved = array_sum($moves[$id] ?? []);
    $target = (float)$g['target'];
    $left = max(0, $target - $saved);
    $monthly = null; $monthsLeft = null;
    if ($g['deadline'] && $left > 0) {
      $dl = new DateTimeImmutable($g['deadline']);
      $diff = $today->diff($dl);
      $monthsLeft = $dl < $today ? 0 : max(1, $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0));
      $monthly = $monthsLeft > 0 ? round($left / $monthsLeft) : $left;
    }
    $by = [];
    foreach ($moves[$id] ?? [] as $mid => $sum) if (abs($sum) >= 0.01) $by[] = ['id' => $mid, 'name' => $names[$mid] ?? '?', 'amount' => $sum];
    $out[] = ['id' => $id, 'title' => $g['title'], 'target' => $target, 'saved' => round($saved, 2), 'left' => round($left, 2),
      'percent' => $target > 0 ? round(min(100, $saved / $target * 100), 1) : 0, 'deadline' => $g['deadline'], 'months_left' => $monthsLeft,
      'monthly_needed' => $monthly, 'archived' => (bool)$g['archived'], 'by' => $by];
  }
  return $out;
}

function goal_save(array $d, array $member): int {
  $title = trim((string)($d['title'] ?? ''));
  $target = parse_amount($d['target'] ?? null);
  $deadline = trim((string)($d['deadline'] ?? ''));
  if ($title === '' || mb_strlen($title) > 60 || $target === null) throw new RuntimeException('Укажите название и сумму цели');
  if ($deadline !== '' && !valid_date($deadline, true)) throw new RuntimeException('Неверная дата цели');
  $id = (int)($d['id'] ?? 0);
  if ($id) {
    $q = db()->prepare('UPDATE goals SET title=?,target=?,deadline=? WHERE id=?');
    $q->execute([$title, $target, $deadline ?: null, $id]);
    return $id;
  }
  db()->prepare('INSERT INTO goals(title,target,deadline,created_by) VALUES(?,?,?,?)')->execute([$title, $target, $deadline ?: null, $member['id']]);
  return (int)db()->lastInsertId();
}

function goal_saved(int $goalId): float {
  $q = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM goal_moves WHERE goal_id=?');
  $q->execute([$goalId]);
  return (float)$q->fetchColumn();
}

// Positive amount puts money into the goal, negative takes it back into the budget
function goal_move(int $goalId, $amount, array $member): void {
  $q = db()->prepare('SELECT id FROM goals WHERE id=? AND archived=0');
  $q->execute([$goalId]);
  if (!$q->fetchColumn()) throw new RuntimeException('Цель не найдена');
  $raw = is_string($amount) ? str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $amount) : $amount;
  $v = filter_var($raw, FILTER_VALIDATE_FLOAT);
  if ($v === false || $v == 0.0 || abs($v) > 100000000) throw new RuntimeException('Введите сумму');
  $v = round($v, 2);
  if ($v < 0 && -$v > goal_saved($goalId) + 0.005) throw new RuntimeException('В копилке меньше этой суммы');
  db()->prepare('INSERT INTO goal_moves(goal_id,telegram_id,amount,occurred_on) VALUES(?,?,?,?)')->execute([$goalId, $member['id'], $v, date('Y-m-d')]);
  mark_balance_changed();
}

// Closing a goal returns what is left in it to whoever put it there
function goal_close(int $goalId): void {
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $q = $pdo->prepare('SELECT telegram_id,SUM(amount) total FROM goal_moves WHERE goal_id=? GROUP BY telegram_id');
    $q->execute([$goalId]);
    $ins = $pdo->prepare('INSERT INTO goal_moves(goal_id,telegram_id,amount,occurred_on) VALUES(?,?,?,?)');
    foreach ($q->fetchAll() as $r) if ((float)$r['total'] > 0.005) $ins->execute([$goalId, (int)$r['telegram_id'], -(float)$r['total'], date('Y-m-d')]);
    $pdo->prepare('UPDATE goals SET archived=1 WHERE id=?')->execute([$goalId]);
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  mark_balance_changed();
}

/* ========== RECURRING PAYMENTS ========== */
function recurring_date_in(string $period, int $day): string {
  $first = new DateTimeImmutable($period . '-01');
  return $first->format('Y-m-') . str_pad((string)min($day, (int)$first->format('t')), 2, '0', STR_PAD_LEFT);
}

function recurring_list(): array {
  $names = allowed_members_map();
  $period = date('Y-m');
  $today = date('Y-m-d');
  $out = [];
  foreach (db()->query('SELECT * FROM recurring ORDER BY active DESC,day,id') as $r) {
    $due = recurring_date_in($period, (int)$r['day']);
    $status = $r['done_period'] === $period ? 'done' : ($r['skipped_period'] === $period ? 'skipped' : ($due <= $today ? 'due' : 'upcoming'));
    $out[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'amount' => (float)$r['amount'], 'category' => $r['category'],
      'group' => category_group_of($r['category'], $r['category_group'] ?: null), 'note' => $r['note'], 'day' => (int)$r['day'],
      'payer_id' => (int)$r['payer_id'], 'payer_name' => (int)$r['payer_id'] ? ($names[(int)$r['payer_id']] ?? '?') : 'Любой',
      'active' => (bool)$r['active'], 'status' => $r['active'] ? $status : 'paused', 'due_date' => $due];
  }
  return $out;
}

function get_recurring(int $id): ?array {
  $q = db()->prepare('SELECT * FROM recurring WHERE id=?');
  $q->execute([$id]);
  return $q->fetch() ?: null;
}

function recurring_save(array $d, array $member): int {
  $e = validate_entry(['kind' => $d['kind'] ?? 'expense', 'amount' => $d['amount'] ?? null, 'category' => $d['category'] ?? '',
    'category_group' => $d['category_group'] ?? null, 'note' => $d['note'] ?? '', 'date' => date('Y-m-d')]);
  $day = (int)($d['day'] ?? 0);
  $payer = (int)($d['payer_id'] ?? 0);
  if ($day < 1 || $day > 31) throw new RuntimeException('День месяца — от 1 до 31');
  if ($payer !== 0 && !is_member_id($payer)) throw new RuntimeException('Неверный плательщик');
  $active = !isset($d['active']) || (bool)$d['active'] ? 1 : 0;
  $id = (int)($d['id'] ?? 0);
  if ($id) {
    db()->prepare('UPDATE recurring SET kind=?,amount=?,category=?,category_group=?,note=?,day=?,payer_id=?,active=? WHERE id=?')
      ->execute([$e['kind'], $e['amount'], $e['category'], $e['group'], $e['note'], $day, $payer, $active, $id]);
    return $id;
  }
  db()->prepare('INSERT INTO recurring(kind,amount,category,category_group,note,day,payer_id,active,created_by) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute([$e['kind'], $e['amount'], $e['category'], $e['group'], $e['note'], $day, $payer, $active, $member['id']]);
  return (int)db()->lastInsertId();
}

function recurring_delete(int $id): void { db()->prepare('DELETE FROM recurring WHERE id=?')->execute([$id]); }

// Records this month's payment; a fixed payer is credited even if the other member taps
function recurring_pay(int $id, array $member, $amountOverride = null): array {
  $r = get_recurring($id);
  if (!$r) throw new RuntimeException('Платёж не найден');
  $period = date('Y-m');
  if ($r['done_period'] === $period) throw new RuntimeException('Уже отмечен в этом месяце');
  $amount = $amountOverride !== null ? parse_amount($amountOverride) : (float)$r['amount'];
  if ($amount === null) throw new RuntimeException('Введите сумму');
  $payer = (int)$r['payer_id'] ?: (int)$member['id'];
  $date = min(date('Y-m-d'), recurring_date_in($period, (int)$r['day']));
  $e = ['kind' => $r['kind'], 'amount' => $amount, 'category' => $r['category'], 'group' => category_group_of($r['category'], $r['category_group'] ?: null),
    'note' => $r['note'], 'date' => $date];
  $txId = insert_transaction($payer, $e, $id);
  db()->prepare('UPDATE recurring SET done_period=? WHERE id=?')->execute([$period, $id]);
  $payerMember = ['id' => $payer, 'name' => user_label($payer)];
  $limits = $e['kind'] === 'expense' ? check_limit_alerts($e['category'], $e['date'], $payerMember, $amount) : [];
  return ['tx_id' => $txId, 'entry' => $e, 'payer' => $payerMember, 'limits' => $limits];
}

function recurring_skip(int $id): void { db()->prepare('UPDATE recurring SET skipped_period=? WHERE id=?')->execute([date('Y-m'), $id]); }

/* ========== FORECAST ========== */
// End-of-month projection: spent so far + everyday pace for the remaining days + unpaid recurring
function month_forecast(): array {
  [$from, $until] = normalize_period(null, null, 'month');
  $today = new DateTimeImmutable('today');
  $day = (int)$today->format('j');
  $daysInMonth = (int)$today->format('t');
  $q = db()->prepare("SELECT COALESCE(SUM(amount),0) total, COALESCE(SUM(CASE WHEN recurring_id IS NULL THEN amount ELSE 0 END),0) everyday FROM transactions WHERE kind='expense' AND occurred_on>=? AND occurred_on<?");
  $q->execute([$from, $until]);
  $r = $q->fetch();
  $spent = (float)$r['total'];
  $pace = (float)$r['everyday'] / max(1, $day);
  $unpaid = 0.0;
  foreach (recurring_list() as $rec) if ($rec['kind'] === 'expense' && in_array($rec['status'], ['due', 'upcoming'], true)) $unpaid += $rec['amount'];
  $projected = round($spent + $pace * ($daysInMonth - $day) + $unpaid);
  $limit = null;
  foreach (limits_status(0)['items'] as $i) if ($i['scope'] === 'family' && $i['category'] === TOTAL_LIMIT && $i['period'] === 'month') $limit = $i['limit'];
  return ['spent' => $spent, 'pace' => round($pace), 'unpaid_recurring' => $unpaid, 'projected' => $projected, 'days_left' => $daysInMonth - $day,
    'limit' => $limit, 'vs_limit' => $limit !== null ? round($projected - $limit) : null, 'reliable' => $day >= 5];
}

/* ========== SEARCH ========== */
// Case-insensitive search over all operations (SQLite LIKE is ASCII-only, so filter in PHP)
function search_operations(string $query, int $limit = 100): array {
  $query = trim($query);
  if (mb_strlen($query) < 2) return [];
  $needle = mb_strtolower($query);
  $digits = preg_replace('/[\s\x{00A0}]/u', '', $query);
  $amount = preg_match('/^\d+([.,]\d{1,2})?$/', $digits) ? (float)str_replace(',', '.', $digits) : null;
  $rows = db()->query("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id ORDER BY t.occurred_on DESC,t.id DESC LIMIT 20000");
  $out = [];
  foreach ($rows as $r) {
    $hay = mb_strtolower($r['category'] . ' ' . $r['note'] . ' ' . $r['display_name']);
    if (!str_contains($hay, $needle) && !($amount !== null && abs((float)$r['amount'] - $amount) < 0.01)) continue;
    $r['group'] = category_group_of($r['category'], $r['cg'] ?: null);
    $out[] = $r;
    if (count($out) >= $limit) break;
  }
  return $out;
}

/* ========== BANK STATEMENT IMPORT ========== */
// Parses text of a Kaspi Gold statement ("01.10.26  - 3 450,00 ₸  Покупка  MAGNUM ...").
// Purchases are pre-selected; transfers, top-ups and withdrawals are offered unticked.
function parse_bank_statement(string $text, int $payerId): array {
  $text = str_replace(["\u{00A0}", "\u{2009}", "\u{202F}", "\r"], [' ', ' ', ' ', ''], $text);
  $re = '/(\d{2})\.(\d{2})\.(\d{2}|\d{4})\s+([+\-−–])\s*(\d[\d ]*(?:[.,]\d{1,2})?)\s*(?:₸|т\b|тг|KZT)?\s+(Покупк[аи]|Пополнени[ея]|Перевод[ы]?|Сняти[ея]|Разное|Плат[её]ж[и]?|Оплата)\s*([^\n]*)/u';
  preg_match_all($re, $text, $m, PREG_SET_ORDER);
  $rows = [];
  foreach ($m as $x) {
    $year = strlen($x[3]) === 2 ? '20' . $x[3] : $x[3];
    $date = "$year-{$x[2]}-{$x[1]}";
    if (!valid_date($date)) continue;
    $amount = (float)str_replace([' ', ','], ['', '.'], $x[5]);
    if ($amount <= 0) continue;
    $sign = in_array($x[4], ['+'], true) ? '+' : '-';
    $type = mb_strtolower($x[6]);
    $details = trim(preg_replace('/\s{2,}/u', ' ', $x[7]));
    $kind = $sign === '+' ? 'topup' : 'expense';
    $isPurchase = str_starts_with($type, 'покупк') || str_starts_with($type, 'плат') || str_starts_with($type, 'оплат');
    $category = $kind === 'topup' ? 'Пополнение' : (categorize_text($details) ?? ($isPurchase ? 'Другое' : 'Другое'));
    $rows[] = ['date' => $date, 'kind' => $kind, 'amount' => $amount, 'type' => $x[6], 'note' => mb_substr($details, 0, 200),
      'category' => $category, 'group' => category_group_of($category), 'purchase' => $isPurchase && $kind === 'expense',
      'include' => $isPurchase && $kind === 'expense', 'duplicate' => false];
  }
  return mark_statement_duplicates($rows, $payerId);
}

// Operations that already exist for this member (same date, kind and amount) are duplicates
function mark_statement_duplicates(array $rows, int $payerId): array {
  if (!$rows) return [];
  $dates = array_column($rows, 'date');
  $q = db()->prepare('SELECT occurred_on,kind,amount FROM transactions WHERE occurred_on>=? AND occurred_on<=? AND telegram_id=?');
  $q->execute([min($dates), max($dates), $payerId]);
  $existing = [];
  foreach ($q as $r) { $k = $r['occurred_on'] . '|' . $r['kind'] . '|' . number_format((float)$r['amount'], 2, '.', ''); $existing[$k] = ($existing[$k] ?? 0) + 1; }
  foreach ($rows as &$row) {
    $k = $row['date'] . '|' . $row['kind'] . '|' . number_format((float)$row['amount'], 2, '.', '');
    $row['duplicate'] = false;
    $row['include'] = !empty($row['purchase']);
    if (($existing[$k] ?? 0) > 0) { $existing[$k]--; $row['duplicate'] = true; $row['include'] = false; }
  }
  unset($row);
  return $rows;
}

// Returns the ids of the created operations (so a bot import can be undone)
function import_rows(array $rows, int $payerId): array {
  if (!is_member_id($payerId)) throw new RuntimeException('Неверный участник');
  if (count($rows) > 2000) throw new RuntimeException('Слишком много строк за раз');
  $entries = [];
  foreach ($rows as $i => $r) {
    try { $entries[] = validate_entry(is_array($r) ? $r : []); }
    catch (RuntimeException $e) { throw new RuntimeException('Строка ' . ($i + 1) . ': ' . $e->getMessage()); }
  }
  $pdo = db();
  $pdo->beginTransaction();
  $ids = [];
  try { foreach ($entries as $e) $ids[] = insert_transaction($payerId, $e); $pdo->commit(); }
  catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  return $ids;
}

/* ========== TELEGRAM UPLOADS ========== */
function telegram_multipart(string $method, array $fields): array {
  global $config;
  if (getenv('FAMFIN_DRY_TELEGRAM')) { fwrite(STDERR, "[dry $method] " . json_encode(array_map(fn($v) => $v instanceof CURLFile ? 'file:' . basename($v->getFilename()) : $v, $fields), JSON_UNESCAPED_UNICODE) . "\n"); return ['ok' => true, 'result' => ['message_id' => random_int(1000, 9999)]]; }
  $ch = curl_init('https://api.telegram.org/bot' . $config['bot_token'] . '/' . $method);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
  $raw = curl_exec($ch);
  if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException($err); }
  curl_close($ch);
  return json_decode($raw, true) ?: [];
}

/* ========== BACKUPS ========== */
// Consistent snapshot (VACUUM INTO), gzipped next to the database; keeps 30 days
function make_backup(): array {
  global $config;
  if (!is_sqlite()) throw new RuntimeException('Резервное копирование настроено только для SQLite');
  $dir = dirname($config['db']['path']) . '/backups';
  if (!is_dir($dir)) mkdir($dir, 0700, true);
  $raw = $dir . '/budget-' . date('Ymd-His') . '.sqlite';
  db()->exec("VACUUM INTO " . db()->quote($raw));
  $gz = $raw . '.gz';
  file_put_contents($gz, gzencode((string)file_get_contents($raw), 9));
  unlink($raw);
  chmod($gz, 0600);
  $files = glob($dir . '/budget-*.sqlite.gz') ?: [];
  rsort($files);
  foreach (array_slice($files, 30) as $old) @unlink($old);
  $count = (int)db()->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
  return ['file' => $gz, 'size' => filesize($gz), 'transactions' => $count];
}

function send_backup(string $chatId, array $b): array {
  $caption = "🗄 Резервная копия бюджета · " . date('d.m.Y H:i') . "\nОпераций: {$b['transactions']} · " . max(1, (int)round($b['size'] / 1024)) . " КБ\n\nВосстановление: распакуйте .gz и положите файл вместо budget.sqlite на сервере.";
  return telegram_multipart('sendDocument', ['chat_id' => $chatId, 'caption' => $caption, 'disable_notification' => 'true',
    'document' => new CURLFile($b['file'], 'application/gzip', basename($b['file']))]);
}

/* ========== MONTHLY SUMMARY ========== */
function monthly_report_text(?string $anyDayOfMonth = null): string {
  $d = new DateTimeImmutable($anyDayOfMonth ?? 'first day of last month');
  $from = $d->modify('first day of this month');
  $until = $from->modify('first day of next month');
  $s = range_summary($from->format('Y-m-d'), $until->format('Y-m-d'));
  $q = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM goal_moves WHERE occurred_on>=? AND occurred_on<?');
  $q->execute([$from->format('Y-m-d'), $until->format('Y-m-d')]);
  $saved = (float)$q->fetchColumn();
  $months = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
  $lines = ['🗓 Итоги за ' . $months[(int)$from->format('n') - 1] . ' ' . $from->format('Y'), ''];
  $lines[] = 'Потрачено: ' . fmt_money($s['expenses']) . " ({$s['count']} оп.)";
  $lines[] = '· обязательные ' . fmt_money($s['fixed']) . ', переменные ' . fmt_money($s['variable']);
  $lines[] = 'Пополнения: ' . fmt_money($s['topups']);
  if (abs($saved) >= 1) $lines[] = ($saved > 0 ? 'Отложено в цели: ' : 'Взято из целей: ') . fmt_money(abs($saved));
  $diff = $s['topups'] - $s['expenses'] - $saved;
  $lines[] = ($diff >= 0 ? 'Осталось сверх трат: ' : 'Потрачено больше, чем пришло, на ') . fmt_money(abs($diff));
  $per = [];
  foreach ($s['members'] as $m) $per[] = $m['name'] . ' ' . fmt_money($m['expenses']);
  $lines[] = 'Кто сколько: ' . implode(' · ', $per);
  if ($s['categories']) {
    $lines[] = '';
    $lines[] = 'Топ категорий:';
    $i = 0;
    foreach ($s['categories'] as $name => $c) { $lines[] = (++$i) . '. ' . $name . ' — ' . fmt_money($c['total']); if ($i >= 3) break; }
  }
  $over = [];
  foreach (limits_status(0, $until->modify('-1 day')->format('Y-m-d'))['items'] as $l) {
    if ($l['scope'] === 'family' && $l['period'] === 'month' && $l['remaining'] < 0) $over[] = '🔴 ' . $l['label'] . ': превышен на ' . fmt_money(-$l['remaining']);
  }
  if ($over) { $lines[] = ''; $lines[] = 'Превышенные лимиты:'; array_push($lines, ...$over); }
  return implode("\n", $lines);
}

/* ========== STATEMENTS SENT TO THE BOT ========== */
// A PDF statement sent to the bot becomes a job; statement_worker.php (cron, CLI) downloads
// it, extracts text with Ghostscript and replies with a summary and import buttons.

function statement_jobs_schema(PDO $pdo): void {
  if (is_sqlite()) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS statement_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,telegram_id INTEGER NOT NULL,chat_id INTEGER NOT NULL,file_id TEXT NOT NULL,file_name TEXT NOT NULL DEFAULT '',message_id INTEGER NULL,status TEXT NOT NULL DEFAULT 'new',error TEXT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS statement_jobs(id BIGINT AUTO_INCREMENT PRIMARY KEY,telegram_id BIGINT NOT NULL,chat_id BIGINT NOT NULL,file_id VARCHAR(255) NOT NULL,file_name VARCHAR(255) NOT NULL DEFAULT '',message_id BIGINT NULL,status VARCHAR(8) NOT NULL DEFAULT 'new',error VARCHAR(500) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

// Text of a PDF via Ghostscript's txtwrite device (keeps table columns on one line)
function pdf_to_text(string $file): string {
  global $config;
  $gs = $config['gs_path'] ?? '/usr/bin/gs';
  if (!is_executable($gs)) throw new RuntimeException('На сервере не найден Ghostscript для чтения PDF');
  $proc = proc_open([$gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=txtwrite', '-sOutputFile=-', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($proc)) throw new RuntimeException('Не удалось запустить чтение PDF');
  $text = stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]); // gs 10.02 prints a harmless "finalizing subclassing device" warning
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($proc);
  return (string)$text;
}

function telegram_download(string $fileId, string $dest): void {
  global $config;
  $f = telegram('getFile', ['file_id' => $fileId]);
  $path = $f['result']['file_path'] ?? null;
  if (!$path) throw new RuntimeException('Telegram не отдал файл: ' . ($f['description'] ?? 'неизвестная ошибка'));
  $ch = curl_init('https://api.telegram.org/file/bot' . $config['bot_token'] . '/' . $path);
  $fh = fopen($dest, 'wb');
  curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 60, CURLOPT_FAILONERROR => true]);
  $ok = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  fclose($fh);
  if (!$ok) throw new RuntimeException('Не удалось скачать файл: ' . $err);
}

function statement_summary(array $rows, int $payerId, string $fileName = ''): string {
  $dates = array_column($rows, 'date');
  $buy = array_filter($rows, fn($r) => $r['purchase'] && !$r['duplicate']);
  $other = array_filter($rows, fn($r) => !$r['purchase'] && !$r['duplicate']);
  $dups = array_filter($rows, fn($r) => $r['duplicate']);
  $sum = array_sum(array_column($buy, 'amount'));
  $byCat = [];
  foreach ($buy as $r) $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + $r['amount'];
  arsort($byCat);
  $fmt = fn($iso) => (new DateTimeImmutable($iso))->format('d.m.Y');
  $lines = ['📄 Выписка' . ($fileName !== '' ? " «{$fileName}»" : '') . ' · ' . $fmt(min($dates)) . ' — ' . $fmt(max($dates)), '👤 Чья: ' . user_label($payerId), '', 'Найдено операций: ' . count($rows)];
  $lines[] = '🛒 Новые покупки: ' . count($buy) . ($sum ? ' на ' . fmt_money($sum) : '');
  $i = 0;
  foreach ($byCat as $cat => $v) { $lines[] = '   · ' . $cat . ' — ' . fmt_money($v); if (++$i >= 8) break; }
  $unknown = count(array_filter($buy, fn($r) => $r['category'] === 'Другое'));
  if ($unknown) $lines[] = "   ($unknown без категории — попадут в «Другое», можно поправить в приложении)";
  if ($other) $lines[] = '↔️ Переводы, пополнения, снятия: ' . count($other) . ' — по умолчанию не импортирую';
  if ($dups) $lines[] = '♻️ Уже есть в бюджете: ' . count($dups) . ' — пропущу';
  return implode("\n", $lines);
}

function statement_markup(int $userId, int $jobId, array $rows, int $payerId): array {
  $buy = count(array_filter($rows, fn($r) => $r['purchase'] && !$r['duplicate']));
  $all = count(array_filter($rows, fn($r) => !$r['duplicate']));
  $labels = []; $opts = [];
  if ($buy) { $labels[] = "✅ Импортировать покупки ($buy)"; $opts[] = 'buy'; }
  if ($all > $buy) { $labels[] = "➕ Всё новое ($all)"; $opts[] = 'all'; }
  $labels[] = '📋 Список'; $opts[] = 'list';
  foreach (allowed_members_map() as $id => $name) if ($id !== $payerId) { $labels[] = "👤 Это выписка: $name"; $opts[] = 'payer:' . $id; }
  $labels[] = '✖️ Отмена'; $opts[] = 'cancel';
  return bot_keyboard($userId, ['type' => 'statement', 'job' => $jobId, 'payer' => $payerId, 'rows' => $rows, 'opts' => $opts], $labels, 1);
}

function statement_list_messages(array $rows): array {
  $out = []; $buf = '';
  foreach ($rows as $r) {
    $d = (new DateTimeImmutable($r['date']))->format('d.m');
    $mark = $r['duplicate'] ? '♻️' : ($r['purchase'] ? '🛒' : '↔️');
    $line = "$mark $d " . ($r['kind'] === 'expense' ? '−' : '+') . number_format($r['amount'], 0, ',', ' ') . " · {$r['category']} · " . mb_substr($r['note'] ?: $r['type'], 0, 40) . "\n";
    if (mb_strlen($buf . $line) > 3800) { $out[] = $buf; $buf = ''; }
    $buf .= $line;
  }
  if ($buf !== '') $out[] = $buf;
  return $out;
}

// Called by statement_worker.php for each waiting job
function process_statement_job(array $job): void {
  $tmp = tempnam(sys_get_temp_dir(), 'stmt');
  try {
    telegram_download($job['file_id'], $tmp);
    $head = (string)file_get_contents($tmp, false, null, 0, 5);
    $text = $head === '%PDF-' ? pdf_to_text($tmp) : (string)file_get_contents($tmp);
    $rows = parse_bank_statement($text, (int)$job['telegram_id']);
    if (!$rows) throw new RuntimeException('Не нашёл в файле операций. Нужна выписка Kaspi Gold в PDF (Kaspi → Kaspi Gold → Выписка).');
    $payload = ['chat_id' => (int)$job['chat_id'], 'text' => statement_summary($rows, (int)$job['telegram_id'], $job['file_name']),
      'reply_markup' => statement_markup((int)$job['telegram_id'], (int)$job['id'], $rows, (int)$job['telegram_id'])];
    if ($job['message_id']) telegram('editMessageText', $payload + ['message_id' => (int)$job['message_id']]);
    else telegram('sendMessage', $payload);
    db()->prepare("UPDATE statement_jobs SET status='done' WHERE id=?")->execute([$job['id']]);
  } catch (Throwable $e) {
    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Не удалось разобрать файл';
    db()->prepare("UPDATE statement_jobs SET status='error',error=? WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 500), $job['id']]);
    $payload = ['chat_id' => (int)$job['chat_id'], 'text' => '⚠️ ' . $msg];
    if ($job['message_id']) telegram('editMessageText', $payload + ['message_id' => (int)$job['message_id']]);
    else telegram('sendMessage', $payload);
    if (!($e instanceof RuntimeException)) error_log((string)$e);
  } finally {
    @unlink($tmp);
  }
}
