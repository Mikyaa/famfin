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
    if (!has_column($pdo, 'transactions', 'import_key')) $pdo->exec("ALTER TABLE transactions ADD COLUMN import_key TEXT NULL;");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tx_import_key ON transactions(import_key);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_transfers_date ON transfers(occurred_on);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_goal_moves_goal ON goal_moves(goal_id);");
    statement_jobs_schema($pdo);
    adjustments_schema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_categories(name TEXT PRIMARY KEY,grp TEXT NOT NULL DEFAULT 'variable',created_by INTEGER NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    return;
  }
  $cs = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS transfers(id BIGINT AUTO_INCREMENT PRIMARY KEY,from_id BIGINT NOT NULL,to_id BIGINT NOT NULL,amount DECIMAL(14,2) NOT NULL,note VARCHAR(500) NOT NULL DEFAULT '',occurred_on DATE NOT NULL,created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(occurred_on)) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS goals(id BIGINT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(80) NOT NULL,target DECIMAL(14,2) NOT NULL,deadline DATE NULL,archived TINYINT NOT NULL DEFAULT 0,created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS goal_moves(id BIGINT AUTO_INCREMENT PRIMARY KEY,goal_id BIGINT NOT NULL,telegram_id BIGINT NOT NULL,amount DECIMAL(14,2) NOT NULL,occurred_on DATE NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(goal_id)) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS recurring(id BIGINT AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(8) NOT NULL DEFAULT 'expense',amount DECIMAL(14,2) NOT NULL,category VARCHAR(80) NOT NULL,category_group VARCHAR(16) NULL,note VARCHAR(500) NOT NULL DEFAULT '',day TINYINT NOT NULL,payer_id BIGINT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1,done_period CHAR(7) NULL,skipped_period CHAR(7) NULL,reminded_period CHAR(7) NULL,created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS bot_actions(id BIGINT AUTO_INCREMENT PRIMARY KEY,telegram_id BIGINT NOT NULL,payload TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) $cs");
  if (!has_column($pdo, 'transactions', 'recurring_id')) $pdo->exec("ALTER TABLE transactions ADD COLUMN recurring_id BIGINT NULL");
  if (!has_column($pdo, 'transactions', 'import_key')) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN import_key CHAR(40) NULL");
    $pdo->exec("CREATE INDEX transactions_import_key_idx ON transactions(import_key)");
  }
  statement_jobs_schema($pdo);
  adjustments_schema($pdo);
  $pdo->exec("CREATE TABLE IF NOT EXISTS custom_categories(name VARCHAR(80) PRIMARY KEY,grp VARCHAR(16) NOT NULL DEFAULT 'variable',created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ========== CUSTOM CATEGORIES ========== */
// Created from the entry form or the limits screen; available everywhere right away
function custom_categories(): array {
  $out = [];
  foreach (db()->query('SELECT name,grp FROM custom_categories ORDER BY created_at,name') as $r) $out[(string)$r['name']] = $r['grp'] === 'fixed' ? 'fixed' : 'variable';
  return $out;
}

function add_custom_category(string $name, string $group, array $member): void {
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  if ($name === '' || mb_strlen($name) > 80) throw new RuntimeException('Название категории — от 1 до 80 символов');
  if (in_array(mb_strtolower($name), ['*', 'пополнение', 'возврат', 'со своих счетов', 'зарплата'], true)) throw new RuntimeException('Это название занято');
  $group = $group === 'fixed' ? 'fixed' : 'variable';
  $sql = is_sqlite()
    ? 'INSERT INTO custom_categories(name,grp,created_by) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET grp=excluded.grp'
    : 'INSERT INTO custom_categories(name,grp,created_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE grp=VALUES(grp)';
  db()->prepare($sql)->execute([$name, $group, $member['id']]);
}

// Lists for the category grid in the app: config defaults plus custom and used expense categories
function category_groups_all(): array {
  $out = ['fixed' => [], 'variable' => []];
  foreach (category_groups_map() as $name => $g) $out[$g === 'fixed' ? 'fixed' : 'variable'][] = (string)$name;
  return $out;
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
  'Продукты' => ['korzinka', 'корзинка', 'фермаг', 'ovochshnoy', 'овощн', 'diona', 'диона', 'аяна', 'береке', 'bereke', 'балмарт', 'mix market', 'кажетмаркет', 'small ', 'продукт', 'магнум', 'magnum', 'small', 'смолл', 'галмарт', 'galmart', 'анвар', 'супермаркет', 'гипермаркет', 'овощ', 'фрукт', 'хлеб', 'молок', 'мясо', 'рынок', 'базар', 'arbuz', 'арбуз', 'airba fresh', 'grocery', 'метро кэш', 'metro cash', 'toimart', 'дикси', 'ашан', 'еда домой', 'продукты'],
  'Кафе и рестораны' => ['wedrink', 'etet', 'et-et', 'омега 75', 'popeyes', 'espressoday', '2 beans', 'qazplov', 'мята', 'тағам', 'canteen', 'servis pitaniya', 'асхана', 'qazan plov', 'плов', 'taptatti', "i'm restaurants", 'restaurant', 'кафе', 'ресторан', 'кофе', 'coffee', 'кофейн', 'обед', 'ужин', 'завтрак', 'бар ', 'пицц', 'pizza', 'суши', 'sushi', 'роллы', 'бургер', 'burger', 'kfc', 'mcdonald', 'макдон', 'шаурм', 'донер', 'wolt', 'glovo', 'chocofood', 'яндекс еда', 'yandex eda', 'starbucks', 'столов', 'фастфуд', 'доставка еды', 'чайхан', 'кальян'],
  'Транспорт' => ['avtobys', 'lrt ', 'lrt', 'такси', 'taxi', 'яндекс го', 'yandex.go', 'yandex go', 'uber', 'indriver', 'индрайв', 'бензин', 'азс', 'заправк', 'топливо', 'helios', 'гелиос', 'sinooil', 'qazaq oil', 'парковк', 'parking', 'автобус', 'метро', 'onay', 'онай', 'проезд', 'автомойк', 'шиномонтаж', 'сто ', 'техосмотр'],
  'Дом' => ['ерц', 'рэк', 'аквафор', 'аварийная служба', 'жастар-3', 'kuat stroy', 'строймаркет', 'оси ', 'коммунал', 'квартплат', 'аренд', 'электроэнерг', 'свет за', 'газ ', 'вода', 'отоплен', 'ремонт', 'мебел', 'посуд', 'бытов', 'хозтовар', 'leroy', 'леруа', 'икеа', 'ikea', 'химчистк', 'клининг', 'кск', 'осмд', 'алсеко', 'alseco'],
  'Здоровье' => ['фарма', 'альфа-мед', 'fitness', 'фитнес', 'аптек', 'apteka', 'pharm', 'врач', 'клиник', 'больниц', 'анализ', 'invitro', 'инвитро', 'олимп', 'стоматолог', 'зубн', 'лекарств', 'витамин', 'медицин', 'массаж', 'окулист'],
  'Дети' => ['детск', 'ребен', 'ребён', 'сад ', 'садик', 'школ', 'игрушк', 'памперс', 'подгузн', 'кружок', 'секци', 'репетитор', 'няня', 'детский мир'],
  'Подписки' => ['подписк', 'netflix', 'spotify', 'youtube', 'яндекс плюс', 'yandex plus', 'icloud', 'apple.com', 'google one', 'chatgpt', 'openai', 'claude', 'интернет', 'связь', 'мобильн', 'телефон', 'beeline', 'билайн', 'kcell', 'activ', 'tele2', 'altel', 'kazakhtelecom', 'казахтелеком', 'ivi', 'okko', 'кинопоиск'],
  'Кредиты' => ['кредит', 'рассрочк', 'ипотек', 'kaspi red', 'каспи ред', 'red ', 'займ', 'долг', 'погашен'],
  'Покупки' => ['defacto', 'kari', 'meloman', 'marwin', 'flowers', 'цвет', 'букет', 'хризантема', 'best prays', 'best price', 'fix price', 'fix-price', 'fixprice', 'kaspi magazin', 'зоомаркет', 'зоо', 'lovely store', 'одежд', 'обув', 'wildberries', 'вайлдберриз', 'ozon', 'озон', 'kaspi магазин', 'kaspi.kz магазин', 'техник', 'электроник', 'sulpak', 'сулпак', 'technodom', 'технодом', 'mechta', 'мечта', 'zara', 'lc waikiki', 'косметик', 'парфюм', 'подарок', 'подарк', 'aliexpress', 'temu', 'покупк', 'магазин'],
  'Путешествия' => ['билет', 'авиа', 'air astana', 'эйр астана', 'fly arystan', 'scat', 'отель', 'hotel', 'гостиниц', 'booking', 'airbnb', 'поезд', 'жд ', 'тур ', 'путешеств', 'виза', 'отпуск'],
  'Развлечения' => ['usetime', 'антикафе', 'anticafe', 'harry potter', 'lordgame', 'компьютерный клуб', 'кино', 'cinema', 'kinopark', 'кинопарк', 'chaplin', 'концерт', 'театр', 'боулинг', 'бильярд', 'квест', 'игр', 'steam', 'playstation', 'развлеч', 'парк ', 'аттракцион', 'музей', 'клуб'],
];

// Food words in Latin script (merchant names like "QAZAN PLOV", "Yumi_Yumi") mean cafés and restaurants
const LATIN_FOOD = '/(?<![a-z])(plov|burger|burgers|pizza|pizzeria|sushi|rolls?|doner|donar|kebab|shawarma|shaurma|lagman|manty|samsa|coffee|espresso|latte|cafe|caf[eé]|bistro|bar|pub|lounge|canteen|grill|bbq|steak|chicken|wings|noodles?|ramen|wok|tacos?|bakery|cake|donuts?|dessert|ice ?cream|bubble ?tea|boba|tea|kitchen|food|foods|dining|restaurant|restoran|chef|yumi|tagam|asxana|kfc|popeyes|hardee|dodo|beans)(?![a-z])/u';

// Broad hints used only when no specific keyword matched
const CATEGORY_FALLBACK = ['маркет' => 'Продукты', 'market' => 'Продукты', 'mart' => 'Продукты', 'гастроном' => 'Продукты', 'store' => 'Покупки', 'shop' => 'Покупки', 'бутик' => 'Покупки', 'coffee' => 'Кафе и рестораны', 'food' => 'Кафе и рестораны'];

function categorize_text(string $text): ?string {
  $t = ' ' . mb_strtolower(trim($text)) . ' ';
  if (trim($t) === '') return null;
  if (trim($t) === 'cu') return 'Продукты';
  // Exact or prefix match with a category the family already uses (incl. custom ones)
  foreach (known_categories() as $c) {
    $name = mb_strtolower($c['category']);
    if (mb_strlen($name) >= 3 && (str_contains($t, ' ' . $name . ' ') || str_contains($t, ' ' . mb_substr($name, 0, max(4, mb_strlen($name) - 2))))) return $c['category'];
  }
  foreach (CATEGORY_KEYWORDS as $category => $words) {
    foreach ($words as $w) if (str_contains($t, $w)) return $category;
  }
  if (preg_match(LATIN_FOOD, str_replace(['_', '*', '.', '"'], ' ', $t))) return 'Кафе и рестораны';
  foreach (CATEGORY_FALLBACK as $w => $category) if (str_contains($t, $w)) return $category;
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
// Purchases are pre-selected; transfers, top-ups, withdrawals and duplicates are offered unticked.
function parse_bank_statement(string $text, int $payerId): array {
  $meta = parse_statement_meta($text);
  $text = str_replace(["\u{00A0}", "\u{2009}", "\u{202F}", "\r"], [' ', ' ', ' ', ''], $text);
  $re = '/(\d{2})\.(\d{2})\.(\d{2}|\d{4})\s+([+\-−–])\s*(\d[\d ]*(?:[.,]\d{1,2})?)\s*(?:₸|т\b|тг|KZT)?\s+(Покупк[аи]|Пополнени[ея]|Поступлени[ея]|Перевод[ы]?|Сняти[ея]|Разное|Плат[её]ж[и]?|Оплата)\s*([^\n]*)/u';
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
    // Incoming money: top-ups, own-account inflows, incoming transfers, purchase refunds
    $incoming = $kind === 'topup';
    $owner = null;
    if ($incoming) {
      $category = $isPurchase ? 'Возврат' : (str_starts_with($type, 'поступлени') ? 'Со своих счетов' : 'Пополнение');
      // "Перевод от Томирис М." is the other member's contribution, not the card holder's
      $owner = member_in_text($details, $payerId);
    } else {
      $category = categorize_text($details) ?? 'Другое';
    }
    $main = ($isPurchase && $kind === 'expense') || $incoming;
    $rows[] = ['date' => $date, 'kind' => $kind, 'amount' => $amount, 'type' => $x[6], 'note' => mb_substr($details, 0, 200),
      'category' => $category, 'group' => category_group_of($category), 'purchase' => $isPurchase && $kind === 'expense',
      'incoming' => $incoming, 'main' => $main, 'payer_id' => $owner, 'account' => $meta['account'],
      'include' => $main, 'duplicate' => false, 'dup' => null];
  }
  return mark_statement_duplicates(statement_keys($rows), $payerId);
}

// Source label of a statement line: date, kind, amount, operation and counterparty, plus the
// occurrence number for identical lines (two equal bus tickets on one day get keys #1 and #2).
// The member is not part of it, so a statement imported again under another name is still known.
function statement_keys(array $rows): array {
  $seen = [];
  foreach ($rows as &$r) {
    if (!empty($r['key'])) continue;
    $base = implode('|', [$r['date'], $r['kind'], number_format((float)$r['amount'], 2, '.', ''), mb_strtolower((string)$r['type']), mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$r['note'])))]);
    $seen[$base] = ($seen[$base] ?? 0) + 1;
    $r['key'] = sha1($base . '|' . $seen[$base]);
  }
  unset($r);
  return $rows;
}

function existing_import_keys(array $keys): array {
  $found = [];
  foreach (array_chunk(array_values(array_unique(array_filter($keys))), 400) as $chunk) {
    $q = db()->prepare('SELECT import_key FROM transactions WHERE import_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
    $q->execute($chunk);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $k) $found[$k] = true;
  }
  return $found;
}

// Manual operations (no source label) of this member that match exactly by date, kind and amount
function manual_matches(int $payerId, string $from, string $to): array {
  $q = db()->prepare('SELECT id,occurred_on,kind,amount FROM transactions WHERE import_key IS NULL AND telegram_id=? AND occurred_on>=? AND occurred_on<=? ORDER BY id');
  $q->execute([$payerId, $from, $to]);
  $pool = [];
  foreach ($q as $r) $pool[$r['occurred_on'] . '|' . $r['kind'] . '|' . number_format((float)$r['amount'], 2, '.', '')][] = (int)$r['id'];
  return $pool;
}

// dup = 'imported' (label already in the budget) or 'manual' (same entry typed by hand)
function mark_statement_duplicates(array $rows, int $payerId): array {
  if (!$rows) return [];
  $rows = statement_keys($rows);
  $dates = array_column($rows, 'date');
  $known = existing_import_keys(array_column($rows, 'key'));
  $pools = [];
  foreach ($rows as &$row) {
    $owner = (int)($row['payer_id'] ?? 0) ?: $payerId;
    $pools[$owner] ??= manual_matches($owner, min($dates), max($dates));
    $pool = &$pools[$owner];
    $row['dup'] = null;
    if (isset($known[$row['key']])) $row['dup'] = 'imported';
    else {
      $k = $row['date'] . '|' . $row['kind'] . '|' . number_format((float)$row['amount'], 2, '.', '');
      if (!empty($pool[$k])) { array_shift($pool[$k]); $row['dup'] = 'manual'; }
    }
    unset($pool);
    $row['duplicate'] = $row['dup'] !== null;
    $row['include'] = (!empty($row['main']) || !empty($row['purchase'])) && !$row['duplicate'];
  }
  unset($row);
  return $rows;
}

// Imports statement rows against the current state of the budget:
// a known source label is skipped, an exact manual entry gets the label instead of a copy,
// everything else is inserted with its label. Returns ids for "undo import".
function import_rows(array $rows, int $payerId): array {
  if (!is_member_id($payerId)) throw new RuntimeException('Неверный участник');
  if (count($rows) > 3000) throw new RuntimeException('Слишком много строк за раз');
  $rows = statement_keys(array_values(array_filter($rows, 'is_array')));
  $entries = [];
  foreach ($rows as $i => $r) {
    try {
      $owner = (int)($r['payer_id'] ?? 0);
      $entries[] = validate_entry($r) + ['key' => $r['key'], 'owner' => is_member_id($owner) ? $owner : $payerId, 'account' => (string)($r['account'] ?? '')];
    }
    catch (RuntimeException $e) { throw new RuntimeException('Строка ' . ($i + 1) . ': ' . $e->getMessage()); }
  }
  $result = ['ids' => [], 'linked' => [], 'skipped' => 0];
  if (!$entries) return $result;
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $known = existing_import_keys(array_column($entries, 'key'));
    $dates = array_column($entries, 'date');
    $pools = [];
    $insert = $pdo->prepare('INSERT INTO transactions(telegram_id,kind,amount,category,category_group,note,occurred_on,import_key,import_account) VALUES(?,?,?,?,?,?,?,?,?)');
    $link = $pdo->prepare('UPDATE transactions SET import_key=?,import_account=? WHERE id=? AND import_key IS NULL');
    foreach ($entries as $e) {
      if (isset($known[$e['key']])) { $result['skipped']++; continue; }
      $pools[$e['owner']] ??= manual_matches($e['owner'], min($dates), max($dates));
      $k = $e['date'] . '|' . $e['kind'] . '|' . number_format((float)$e['amount'], 2, '.', '');
      if (!empty($pools[$e['owner']][$k])) {
        $id = array_shift($pools[$e['owner']][$k]);
        $link->execute([$e['key'], $e['account'] ?: null, $id]);
        $result['linked'][] = $id;
      } else {
        $insert->execute([$e['owner'], $e['kind'], $e['amount'], $e['category'], $e['group'], $e['note'], $e['date'], $e['key'], $e['account'] ?: null]);
        $result['ids'][] = (int)$pdo->lastInsertId();
      }
      $known[$e['key']] = true;
    }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  if ($result['ids']) mark_balance_changed();
  return $result;
}

// "Undo import": removes created operations and unlinks manual ones
function undo_import(array $ids, array $linked): int {
  $n = 0;
  foreach ($ids as $id) if (delete_transaction((int)$id)) $n++;
  $q = db()->prepare('UPDATE transactions SET import_key=NULL,import_account=NULL WHERE id=?');
  foreach ($linked as $id) $q->execute([(int)$id]);
  return $n;
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

function statement_summary(array $rows, int $payerId, string $fileName = '', array $meta = []): string {
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
  foreach (statement_scopes($rows) as $sc) {
    if ($sc['key'] === 'all' || !$sc['buy']) continue;
    $parts = [];
    if ($sc['spend']) $parts[] = 'покупки ' . fmt_money($sc['spend']);
    if ($sc['income']) $parts[] = 'пополнения ' . fmt_money($sc['income']);
    $lines[] = '   ' . mb_strtoupper(mb_substr($sc['label'], 0, 1)) . mb_substr($sc['label'], 1) . ': ' . $sc['buy'] . ' оп. — ' . implode(', ', $parts);
  }
  $i = 0;
  foreach ($byCat as $cat => $v) { $lines[] = '   · ' . $cat . ' — ' . fmt_money($v); if (++$i >= 8) break; }
  $unknown = count(array_filter($buy, fn($r) => $r['category'] === 'Другое'));
  if ($unknown) $lines[] = "   ($unknown без категории — попадут в «Другое», можно поправить в приложении)";
  $in = array_filter($rows, fn($r) => !empty($r['incoming']) && !$r['duplicate']);
  if ($in) {
    $lines[] = '💰 Новые пополнения: ' . count($in) . ' на ' . fmt_money(array_sum(array_column($in, 'amount')));
    $byOwner = [];
    foreach ($in as $r) if (!empty($r['payer_id'])) $byOwner[(int)$r['payer_id']] = ($byOwner[(int)$r['payer_id']] ?? 0) + $r['amount'];
    foreach ($byOwner as $id => $v) $lines[] = '   · от ' . user_label($id) . ' — ' . fmt_money($v) . ' (запишу как её/его пополнение)';
  }
  $out = array_filter($rows, fn($r) => empty($r['main']) && empty($r['purchase']) && !$r['duplicate']);
  if ($out) $lines[] = '↔️ Переводы, снятия, комиссии: ' . count($out) . ' — по умолчанию не импортирую';
  if (!empty($meta['available'])) {
    $lines[] = '🏦 В банке на ' . (new DateTimeImmutable($meta['available']['date']))->format('d.m.Y') . ': ' . fmt_money_exact($meta['available']['amount']) . ($meta['account'] ? ' · ' . $meta['account'] : '');
  }
  $prev = count(array_filter($dups, fn($r) => ($r['dup'] ?? '') === 'imported'));
  $manual = count($dups) - $prev;
  if ($prev) $lines[] = '♻️ Уже импортированы раньше: ' . $prev . ' — пропущу';
  if ($manual) $lines[] = '✋ Уже записаны вручную (та же дата и сумма): ' . $manual . ' — не задвою, только отмечу источник';
  if ((new DateTimeImmutable(min($dates)))->diff(new DateTimeImmutable(max($dates)))->days > 62) {
    $lines[] = '';
    $lines[] = '⚠️ Выписка за большой период. После импорта нажмите «Сверить с банком» — остаток по карте в бюджете станет таким же, как в банке.';
  }
  return implode("\n", $lines);
}

// Import windows offered under the summary: this month, last 3 months, whole statement
function statement_scopes(array $rows): array {
  $month = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
  $three = (new DateTimeImmutable('first day of this month'))->modify('-2 months')->format('Y-m-d');
  $months = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
  $out = [];
  foreach ([['month', $month, 'за ' . $months[(int)date('n') - 1]], ['3m', $three, 'за 3 месяца'], ['all', '0000-00-00', 'за весь период']] as [$key, $from, $label]) {
    $in = array_filter($rows, fn($r) => !$r['duplicate'] && $r['date'] >= $from);
    $buy = array_filter($in, fn($r) => !empty($r['main']) || $r['purchase']);
    $out[] = ['key' => $key, 'from' => $from, 'label' => $label, 'buy' => count($buy),
      'spend' => array_sum(array_map(fn($r) => $r['kind'] === 'expense' ? $r['amount'] : 0, $buy)),
      'income' => array_sum(array_map(fn($r) => $r['kind'] === 'topup' ? $r['amount'] : 0, $buy)), 'all' => count($in)];
  }
  return $out;
}

function statement_markup(int $userId, int $jobId, array $rows, int $payerId, array $meta = []): array {
  $labels = []; $opts = []; $seen = [];
  foreach (statement_scopes($rows) as $sc) {
    // Skip a wider window that adds nothing over the narrower one
    if (!$sc['buy'] || in_array($sc['buy'], $seen, true)) continue;
    $seen[] = $sc['buy'];
    $labels[] = ($sc['key'] === 'month' ? '✅ ' : '') . "Покупки и пополнения {$sc['label']} — {$sc['buy']}";
    $opts[] = 'buy:' . $sc['key'];
  }
  $month = statement_scopes($rows)[0];
  if ($month['all'] > $month['buy']) { $labels[] = "➕ Всё новое {$month['label']} — {$month['all']}"; $opts[] = 'all:month'; }
  if (!empty($meta['available']) && !empty($meta['account'])) { $labels[] = '⚖️ Сверить остаток с банком'; $opts[] = 'reconcile'; }
  $labels[] = '📋 Список'; $opts[] = 'list';
  foreach (allowed_members_map() as $id => $name) if ($id !== $payerId) { $labels[] = "👤 Это выписка: $name"; $opts[] = 'payer:' . $id; }
  $labels[] = '✖️ Отмена'; $opts[] = 'cancel';
  return bot_keyboard($userId, ['type' => 'statement', 'job' => $jobId, 'payer' => $payerId, 'rows' => $rows, 'meta' => $meta, 'opts' => $opts], $labels, 1);
}

function statement_list_messages(array $rows): array {
  $out = []; $buf = '';
  foreach ($rows as $r) {
    $d = (new DateTimeImmutable($r['date']))->format('d.m');
    $mark = ($r['dup'] ?? null) === 'imported' ? '♻️' : (($r['dup'] ?? null) === 'manual' ? '✋' : ($r['purchase'] ? '🛒' : (!empty($r['incoming']) ? '💰' : '↔️')));
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
    $meta = parse_statement_meta($text);
    $payload = ['chat_id' => (int)$job['chat_id'], 'text' => statement_summary($rows, (int)$job['telegram_id'], $job['file_name'], $meta),
      'reply_markup' => statement_markup((int)$job['telegram_id'], (int)$job['id'], $rows, (int)$job['telegram_id'], $meta)];
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

/* ========== BANK RECONCILIATION ========== */
// Imported operations remember their account ("Kaspi Gold *5052"). Reconciliation adds a
// balance adjustment so that the money of that account in the budget equals the bank's
// "Доступно на ..." figure. Adjustments change balances only, never income/expense reports.

function adjustments_schema(PDO $pdo): void {
  if (is_sqlite()) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS balance_adjustments(id INTEGER PRIMARY KEY AUTOINCREMENT,telegram_id INTEGER NOT NULL,account TEXT NOT NULL DEFAULT '',amount NUMERIC NOT NULL,occurred_on TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',created_by INTEGER NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    if (!has_column($pdo, 'transactions', 'import_account')) $pdo->exec("ALTER TABLE transactions ADD COLUMN import_account TEXT NULL;");
    return;
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS balance_adjustments(id BIGINT AUTO_INCREMENT PRIMARY KEY,telegram_id BIGINT NOT NULL,account VARCHAR(80) NOT NULL DEFAULT '',amount DECIMAL(14,2) NOT NULL,occurred_on DATE NOT NULL,note VARCHAR(300) NOT NULL DEFAULT '',created_by BIGINT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  if (!has_column($pdo, 'transactions', 'import_account')) $pdo->exec("ALTER TABLE transactions ADD COLUMN import_account VARCHAR(80) NULL");
}

// Account id and the bank's available balance from the statement header
function parse_statement_meta(string $text): array {
  $text = str_replace(["\u{00A0}", "\u{2009}", "\u{202F}"], ' ', $text);
  $account = '';
  if (preg_match('/Номер карты:\s*(\*\d{4})/u', $text, $m)) $account = 'Kaspi Gold ' . $m[1];
  elseif (preg_match('/Номер счета:\s*(KZ\w{4,})/u', $text, $m)) $account = 'Kaspi ' . substr($m[1], -4);
  $available = null;
  if (preg_match_all('/Доступно на (\d{2})\.(\d{2})\.(\d{2,4}):?\s+([+\-−])\s*(\d[\d ]*,\d{2})/u', $text, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $x) {
      $date = (strlen($x[3]) === 2 ? '20' . $x[3] : $x[3]) . "-{$x[2]}-{$x[1]}";
      $amount = (float)str_replace([' ', ','], ['', '.'], $x[5]) * ($x[4] === '+' ? 1 : -1);
      if (!$available || $date > $available['date']) $available = ['date' => $date, 'amount' => $amount];
    }
  }
  return ['account' => $account, 'available' => $available];
}

// Family member named in the details of an incoming operation ("Томирис М.")
function member_in_text(string $details, int $exceptId): ?int {
  global $config;
  $t = mb_strtolower($details);
  foreach ($config['allowed_users'] as $id) {
    if ($id === $exceptId) continue;
    $names = array_merge([user_label($id)], (array)($config['member_aliases'][$id] ?? []));
    foreach ($names as $name) {
      $first = mb_strtolower(trim(explode(' ', (string)$name)[0]));
      if (mb_strlen($first) >= 3 && preg_match('/(^|[^\p{L}])' . preg_quote($first, '/') . '([^\p{L}]|$)/u', $t)) return $id;
    }
  }
  return null;
}

function adjustments_until(string $until): array {
  $q = db()->prepare('SELECT telegram_id,SUM(amount) total FROM balance_adjustments WHERE occurred_on<? GROUP BY telegram_id');
  $q->execute([$until]);
  $out = [];
  foreach ($q as $r) $out[(int)$r['telegram_id']] = (float)$r['total'];
  return $out;
}

// Money of one bank account in the budget up to a date (inclusive)
function account_net(string $account, string $date): float {
  $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN kind='topup' THEN amount ELSE -amount END),0) FROM transactions WHERE import_account=? AND occurred_on<=?");
  $q->execute([$account, $date]);
  $net = (float)$q->fetchColumn();
  $q = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM balance_adjustments WHERE account=? AND occurred_on<=?');
  $q->execute([$account, $date]);
  return $net + (float)$q->fetchColumn();
}

function reconcile_account(string $account, int $holderId, string $date, float $bankAmount, int $createdBy): array {
  if ($account === '') throw new RuntimeException('В выписке не нашёл номер карты — сверить не с чем');
  $before = account_net($account, $date);
  $diff = round($bankAmount - $before, 2);
  if (abs($diff) < 0.01) return ['id' => null, 'diff' => 0.0, 'before' => $before];
  $note = 'Сверка с банком: ' . $account . ', доступно ' . fmt_money_exact($bankAmount) . ' на ' . (new DateTimeImmutable($date))->format('d.m.Y');
  db()->prepare('INSERT INTO balance_adjustments(telegram_id,account,amount,occurred_on,note,created_by) VALUES(?,?,?,?,?,?)')
    ->execute([$holderId, $account, $diff, $date, $note, $createdBy]);
  mark_balance_changed();
  return ['id' => (int)db()->lastInsertId(), 'diff' => $diff, 'before' => $before];
}

function delete_adjustment(int $id): void {
  db()->prepare('DELETE FROM balance_adjustments WHERE id=?')->execute([$id]);
  mark_balance_changed();
}

function fmt_money_exact(float $v): string {
  return (abs($v - round($v)) < 0.005 ? number_format($v, 0, ',', ' ') : number_format($v, 2, ',', ' ')) . ' ' . currency_symbol();
}

function reconcile_report(array $r, string $account, float $bankAmount, string $date): string {
  $d = (new DateTimeImmutable($date))->format('d.m.Y');
  if ($r['id'] === null) return "⚖️ $account: в бюджете уже ровно " . fmt_money_exact($bankAmount) . " на $d — корректировка не нужна.";
  return "⚖️ Сверено с банком · $account\nВ банке на $d: " . fmt_money_exact($bankAmount) . "\nВ бюджете было: " . fmt_money_exact($r['before']) .
    "\nКорректировка остатка: " . ($r['diff'] > 0 ? '+' : '−') . fmt_money_exact(abs($r['diff'])) .
    "\n\nКорректировка меняет только остаток — в отчётах о доходах и расходах её нет.";
}
