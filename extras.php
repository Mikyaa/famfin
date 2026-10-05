<?php
declare(strict_types=1);
// Second wave of features: accounts (wallets), category management, big-expense alerts,
// debt reminders, deposit interest, receipts by QR, month series for charts, Excel/PDF
// export, and answers to questions in the bot (rule-based, optionally Claude).

/* ========== SCHEMA ========== */
function extras_schema(PDO $pdo): void {
  if (is_sqlite()) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts(name TEXT PRIMARY KEY,kind TEXT NOT NULL DEFAULT 'card',created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
    if (!has_column($pdo, 'statement_jobs', 'kind')) $pdo->exec("ALTER TABLE statement_jobs ADD COLUMN kind TEXT NOT NULL DEFAULT 'statement';");
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts(name VARCHAR(80) PRIMARY KEY,kind VARCHAR(10) NOT NULL DEFAULT 'card',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!has_column($pdo, 'statement_jobs', 'kind')) $pdo->exec("ALTER TABLE statement_jobs ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'statement'");
  }
  // Default categories become editable rows once; from then on the table is the source of truth
  $seeded = $pdo->query("SELECT v FROM settings WHERE k='categories_seeded'")->fetchColumn();
  if ($seeded === false) {
    global $config;
    $ins = $pdo->prepare(is_sqlite() ? 'INSERT OR IGNORE INTO custom_categories(name,grp) VALUES(?,?)' : 'INSERT IGNORE INTO custom_categories(name,grp) VALUES(?,?)');
    foreach (['fixed', 'variable'] as $g) foreach ($config['category_groups'][$g] ?? [] as $c) $ins->execute([$c, $g]);
    $pdo->prepare(is_sqlite() ? "INSERT OR REPLACE INTO settings(k,v) VALUES('categories_seeded','1')" : "REPLACE INTO settings(k,v) VALUES('categories_seeded','1')")->execute();
  }
}

/* ========== ACCOUNTS ========== */
// An account is a name ("Kaspi Gold *5052", "Наличные"); operations carry it in import_account.
function accounts_list(): array {
  $names = [];
  foreach (db()->query('SELECT name,kind FROM accounts') as $r) $names[(string)$r['name']] = $r['kind'];
  foreach (db()->query("SELECT DISTINCT import_account FROM transactions WHERE import_account IS NOT NULL AND import_account<>''") as $r) $names[(string)$r['import_account']] ??= 'card';
  foreach (db()->query("SELECT DISTINCT account FROM balance_adjustments WHERE account<>''") as $r) $names[(string)$r['account']] ??= 'card';
  $counts = [];
  foreach (db()->query("SELECT import_account a,COUNT(*) n FROM transactions WHERE import_account IS NOT NULL GROUP BY import_account") as $r) $counts[(string)$r['a']] = (int)$r['n'];
  $today = date('Y-m-d');
  $out = [];
  foreach ($names as $name => $kind) $out[] = ['name' => (string)$name, 'kind' => $kind, 'balance' => round(account_net((string)$name, $today), 2), 'count' => $counts[$name] ?? 0];
  usort($out, fn($a, $b) => [$a['kind'] === 'cash', $a['name']] <=> [$b['kind'] === 'cash', $b['name']]);
  return $out;
}

function account_add(string $name, string $kind): void {
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  if ($name === '' || mb_strlen($name) > 80) throw new RuntimeException('Назовите счёт');
  $kind = in_array($kind, ['card', 'cash', 'other'], true) ? $kind : 'card';
  $sql = is_sqlite() ? 'INSERT INTO accounts(name,kind) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET kind=excluded.kind' : 'INSERT INTO accounts(name,kind) VALUES(?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind)';
  db()->prepare($sql)->execute([$name, $kind]);
}

function account_rename(string $old, string $new, string $kind): void {
  $new = trim(preg_replace('/\s+/u', ' ', $new));
  if ($new === '' || mb_strlen($new) > 80) throw new RuntimeException('Назовите счёт');
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $pdo->prepare('UPDATE transactions SET import_account=? WHERE import_account=?')->execute([$new, $old]);
    $pdo->prepare('UPDATE balance_adjustments SET account=? WHERE account=?')->execute([$new, $old]);
    $pdo->prepare('DELETE FROM accounts WHERE name=?')->execute([$old]);
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  account_add($new, $kind);
  mark_balance_changed();
}

// Removes the account from the list; its operations stay, just without an account
function account_delete(string $name): void {
  db()->prepare('UPDATE transactions SET import_account=NULL WHERE import_account=?')->execute([$name]);
  db()->prepare('DELETE FROM balance_adjustments WHERE account=?')->execute([$name]);
  db()->prepare('DELETE FROM accounts WHERE name=?')->execute([$name]);
  mark_balance_changed();
}

function account_operations(string $name, int $limit = 60): array {
  $q = db()->prepare("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,t.import_account account,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id WHERE t.import_account=? ORDER BY t.occurred_on DESC,t.id DESC LIMIT " . max(1, min(500, $limit)));
  $q->execute([$name]);
  $rows = $q->fetchAll();
  foreach ($rows as &$r) $r['group'] = category_group_of($r['category'], $r['cg'] ?: null);
  return $rows;
}

/* ========== CATEGORY MANAGEMENT ========== */
function categories_overview(): array {
  $stats = [];
  foreach (db()->query("SELECT category,COUNT(*) n,SUM(amount) s FROM transactions WHERE kind='expense' GROUP BY category") as $r) $stats[(string)$r['category']] = ['n' => (int)$r['n'], 's' => (float)$r['s']];
  $out = [];
  foreach (category_groups_map() as $name => $g) $out[] = ['name' => (string)$name, 'group' => $g, 'count' => $stats[$name]['n'] ?? 0, 'total' => $stats[$name]['s'] ?? 0.0];
  return $out;
}

// Rename and/or regroup; renaming onto an existing name merges the two
function category_update(string $old, string $new, string $group, array $member): void {
  $new = trim(preg_replace('/\s+/u', ' ', $new));
  if ($new === '' || mb_strlen($new) > 80) throw new RuntimeException('Название категории — от 1 до 80 символов');
  if (in_array(mb_strtolower($new), ['*', 'пополнение', 'возврат', 'со своих счетов', 'зарплата'], true)) throw new RuntimeException('Это название занято');
  $group = $group === 'fixed' ? 'fixed' : 'variable';
  $pdo = db();
  $pdo->beginTransaction();
  try {
    if ($new !== $old) move_category_data($old, $new);
    $pdo->prepare('DELETE FROM custom_categories WHERE name=?')->execute([$old]);
    $pdo->prepare(is_sqlite() ? 'INSERT INTO custom_categories(name,grp,created_by) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET grp=excluded.grp' : 'INSERT INTO custom_categories(name,grp,created_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE grp=VALUES(grp)')
      ->execute([$new, $group, $member['id']]);
    $pdo->prepare("UPDATE transactions SET category_group=? WHERE category=? AND kind='expense'")->execute([$group, $new]);
    $pdo->prepare('UPDATE recurring SET category_group=? WHERE category=?')->execute([$group, $new]);
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function move_category_data(string $from, string $to): void {
  $pdo = db();
  $pdo->prepare("UPDATE transactions SET category=? WHERE category=? AND kind='expense'")->execute([$to, $from]);
  $pdo->prepare('UPDATE recurring SET category=? WHERE category=?')->execute([$to, $from]);
  // A limit on the old name moves over unless the target already has one for the same owner and period
  foreach ($pdo->query('SELECT id,scope_id,period FROM spend_limits WHERE category=' . $pdo->quote($from))->fetchAll() as $l) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM spend_limits WHERE category=? AND scope_id=? AND period=?');
    $q->execute([$to, $l['scope_id'], $l['period']]);
    if ((int)$q->fetchColumn() > 0) $pdo->prepare('DELETE FROM spend_limits WHERE id=?')->execute([$l['id']]);
    else $pdo->prepare('UPDATE spend_limits SET category=? WHERE id=?')->execute([$to, $l['id']]);
  }
}

// Deleting moves the category's operations, payments and limits into another category
function category_delete(string $name, string $moveTo): void {
  $moveTo = trim($moveTo);
  if ($moveTo === '' || $moveTo === $name) throw new RuntimeException('Выберите, куда перенести операции');
  $pdo = db();
  $pdo->beginTransaction();
  try {
    move_category_data($name, $moveTo);
    $pdo->prepare('DELETE FROM custom_categories WHERE name=?')->execute([$name]);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM custom_categories WHERE name=?');
    $exists->execute([$moveTo]);
    if (!(int)$exists->fetchColumn()) $pdo->prepare('INSERT INTO custom_categories(name,grp) VALUES(?,?)')->execute([$moveTo, category_group_of($moveTo)]);
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/* ========== BIG EXPENSE ALERT ========== */
function big_expense_threshold(): int { return max(0, (int)get_setting('big_expense_threshold', '50000')); }

function notify_big_expense(array $member, array $e): void {
  $t = big_expense_threshold();
  if ($t <= 0 || $e['kind'] !== 'expense' || (float)$e['amount'] < $t) return;
  notify_family('💸 ' . $member['name'] . ' записал(а) крупную трату: ' . $e['category'] . ' −' . fmt_money((float)$e['amount']) . ($e['note'] !== '' ? "\n📝 " . $e['note'] : ''), (int)$member['id']);
}

/* ========== DEBT REMINDERS ========== */
// The day before, on the day, and the day after the due date — each once
function send_debt_reminders(): int {
  $today = new DateTimeImmutable('today');
  $sent = 0;
  foreach (debts_list() as $d) {
    if (!$d['due_on'] || $d['amount'] <= 0) continue;
    $diff = (int)$today->diff(new DateTimeImmutable($d['due_on']))->format('%r%a');
    $kind = $diff === 1 ? 'soon' : ($diff === 0 ? 'today' : ($diff === -1 ? 'late' : null));
    if (!$kind) continue;
    $key = "debt_remind:{$d['id']}:{$d['due_on']}:$kind";
    if (get_setting($key) !== null) continue;
    $when = ['soon' => 'завтра', 'today' => 'сегодня', 'late' => 'вчера (срок прошёл)'][$kind];
    $text = $d['direction'] === 'owed_to_me'
      ? "🔔 {$d['person']} должен(на) вернуть вам " . fmt_money($d['amount']) . " — $when."
      : "🔔 Пора вернуть {$d['person']} " . fmt_money($d['amount']) . " — $when.";
    if ($d['note'] !== '') $text .= "\n📝 {$d['note']}";
    notify_family($text);
    set_setting($key, date('Y-m-d'));
    $sent++;
  }
  return $sent;
}

/* ========== DEPOSIT INTEREST ========== */
function deposit_interest_estimate(array $dep): float {
  return ($dep['rate'] && $dep['amount'] > 0) ? round($dep['amount'] * $dep['rate'] / 100 / 12) : 0.0;
}

// On the 1st: offer to record the month's interest with one tap (first tap wins)
function send_deposit_interest_suggestions(): int {
  global $config;
  $period = date('Y-m');
  $sent = 0;
  foreach (deposits_list() as $dep) {
    $est = deposit_interest_estimate($dep);
    if ($est < 1 || get_setting("dep_int_offer:{$dep['id']}:$period") !== null) continue;
    foreach ($config['allowed_users'] as $uid) {
      $markup = bot_keyboard($uid, ['type' => 'dep_int', 'id' => $dep['id'], 'amount' => $est, 'period' => $period, 'opts' => ['record', 'skip']], ['✅ Записать ' . fmt_money($est), '⏭ Пропустить']);
      send_member_message($uid, "🏦 Проценты по депозиту «{$dep['title']}»" . ($dep['bank'] ? " ({$dep['bank']})" : '') . "\nПо ставке " . str_replace('.', ',', (string)$dep['rate']) . "% за месяц примерно " . fmt_money($est) . ". Записать в журнал?\nЕсли банк начислил другую сумму — внесите её в приложении.", $markup);
    }
    set_setting("dep_int_offer:{$dep['id']}:$period", date('Y-m-d'));
    $sent++;
  }
  return $sent;
}

/* ========== RECEIPTS (QR of a fiscal check) ========== */
// Kazakhstan fiscal QR: <ofd url>?i=<fiscal sign>&f=<register>&s=<sum>&t=<YYYYMMDDTHHMMSS>
function parse_receipt_url(string $text): ?array {
  if (!preg_match('~https?://\S+~u', $text, $m)) return null;
  $parts = parse_url($m[0]);
  if (empty($parts['query'])) return null;
  parse_str($parts['query'], $q);
  if (!isset($q['i'], $q['f'], $q['s'], $q['t'])) return null;
  $amount = (float)str_replace([' ', ','], ['', '.'], (string)$q['s']);
  if ($amount <= 0 || !preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2}))?/', (string)$q['t'], $t)) return null;
  $date = "{$t[1]}-{$t[2]}-{$t[3]}";
  if (!valid_date($date, true)) return null;
  if ($date > date('Y-m-d')) $date = date('Y-m-d');
  $time = isset($t[4]) ? "{$t[4]}:{$t[5]}" : '';
  return ['amount' => round($amount, 2), 'date' => $date, 'time' => $time, 'key' => 'receipt:' . preg_replace('/\W/', '', (string)$q['i']) . ':' . preg_replace('/\W/', '', (string)$q['f']),
    'host' => $parts['host'] ?? ''];
}

// Text of a QR code in an image, via tools/qr.mjs (jsQR + Jimp installed in tools_dir)
function decode_qr_image(string $file): ?string {
  global $config;
  $node = $config['node_path'] ?? '/var/www/mirzhan/data/.nvm/versions/node/v22.23.2/bin/node';
  $tools = $config['tools_dir'] ?? '/var/www/mirzhan/data/famfin-tools';
  if (!is_executable($node) || !is_dir($tools . '/node_modules/jsqr')) throw new RuntimeException('Распознавание QR не установлено на сервере');
  $proc = proc_open([$node, __DIR__ . '/tools/qr.mjs', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['FAMFIN_TOOLS' => $tools, 'PATH' => '/usr/bin:/bin']);
  if (!is_resource($proc)) throw new RuntimeException('Не удалось запустить распознавание QR');
  $out = trim((string)stream_get_contents($pipes[1]));
  stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($proc);
  return $out !== '' ? $out : null;
}

function receipt_exists(string $key): bool {
  $q = db()->prepare('SELECT COUNT(*) FROM transactions WHERE import_key=?');
  $q->execute([$key]);
  return (int)$q->fetchColumn() > 0;
}

/* ========== CHART SERIES ========== */
function months_series(string $untilExclusive, int $count = 6): array {
  $end = (new DateTimeImmutable($untilExclusive))->modify('-1 day')->modify('first day of this month');
  $out = [];
  for ($i = $count - 1; $i >= 0; $i--) {
    $from = $end->modify("-$i months");
    $to = $from->modify('first day of next month');
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN kind='expense' THEN amount END),0) e, COALESCE(SUM(CASE WHEN kind='topup' THEN amount END),0) t FROM transactions WHERE occurred_on>=? AND occurred_on<?");
    $q->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
    $r = $q->fetch();
    $out[] = ['month' => $from->format('Y-m'), 'expenses' => (float)$r['e'], 'topups' => (float)$r['t']];
  }
  return $out;
}

/* ========== EXPORT ========== */
function export_rows(string $from, string $until): array {
  $q = db()->prepare("SELECT t.occurred_on,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.import_account,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id WHERE t.occurred_on>=? AND t.occurred_on<? ORDER BY t.occurred_on,t.id LIMIT 20000");
  $q->execute([$from, $until]);
  return $q->fetchAll();
}

// Minimal .xlsx (two sheets: operations and categories) built with ZipArchive
function export_xlsx(string $from, string $until): string {
  $rows = export_rows($from, $until);
  $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  $col = fn(int $i) => chr(65 + $i);
  $sheet = function(array $table) use ($x, $col) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($table as $ri => $cells) {
      $xml .= '<row r="' . ($ri + 1) . '">';
      foreach ($cells as $ci => $v) {
        $ref = $col($ci) . ($ri + 1);
        $style = $ri === 0 ? ' s="1"' : '';
        $xml .= is_float($v) || is_int($v) ? "<c r=\"$ref\"$style><v>$v</v></c>" : "<c r=\"$ref\" t=\"inlineStr\"$style><is><t>" . $x($v) . '</t></is></c>';
      }
      $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
  };
  $ops = [['Дата', 'Тип', 'Сумма', 'Категория', 'Группа', 'Комментарий', 'Кто', 'Счёт']];
  $cats = [];
  foreach ($rows as $r) {
    $g = category_group_of($r['category'], $r['cg'] ?: null);
    $ops[] = [$r['occurred_on'], $r['kind'] === 'expense' ? 'Расход' : 'Пополнение', (float)$r['amount'], $r['category'], $g === 'fixed' ? 'Обязательные' : 'Переменные', $r['note'], $r['display_name'], (string)$r['import_account']];
    if ($r['kind'] === 'expense') $cats[$r['category']] = ($cats[$r['category']] ?? 0) + (float)$r['amount'];
  }
  arsort($cats);
  $total = array_sum($cats);
  $catTable = [['Категория', 'Сумма', 'Доля, %']];
  foreach ($cats as $c => $v) $catTable[] = [(string)$c, round($v, 2), $total ? round($v / $total * 100, 1) : 0.0];
  $catTable[] = ['Итого', round($total, 2), 100.0];
  $file = tempnam(sys_get_temp_dir(), 'xlsx');
  $zip = new ZipArchive();
  $zip->open($file, ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
  $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Операции" sheetId="1" r:id="rId1"/><sheet name="Категории" sheetId="2" r:id="rId2"/></sheets></workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
  $zip->addFromString('xl/worksheets/sheet1.xml', $sheet($ops));
  $zip->addFromString('xl/worksheets/sheet2.xml', $sheet($catTable));
  $zip->close();
  return $file;
}

// PDF: the analysis card for the period (same drawing as the weekly report), converted by Imagick
function export_pdf(string $from, string $until): string {
  require_once __DIR__ . '/report_image.php';
  $png = tempnam(sys_get_temp_dir(), 'rep') . '.png';
  render_weekly_report_png(period_report_data($from, $until), $png);
  $img = new Imagick($png);
  $img->setImageFormat('pdf');
  $pdf = tempnam(sys_get_temp_dir(), 'pdf');
  $img->writeImage($pdf);
  $img->clear();
  @unlink($png);
  return $pdf;
}

/* ========== QUESTIONS IN THE BOT ========== */
const RU_MONTHS_IN = ['январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'ма' => 5, 'июн' => 6, 'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12];

function looks_like_question(string $text): bool {
  $t = mb_strtolower(trim($text));
  return str_ends_with($t, '?') || (bool)preg_match('/^(сколько|кто|что|какие|какой|какая|сравни|покажи|на что|где|когда|почему|как |итог|баланс|остаток)/u', $t);
}

// [from, untilExclusive, label] for phrases like "в сентябре", "на прошлой неделе", "сегодня"
function question_period(string $t): array {
  $today = new DateTimeImmutable('today');
  if (str_contains($t, 'сегодня')) return [$today->format('Y-m-d'), $today->modify('+1 day')->format('Y-m-d'), 'сегодня'];
  if (str_contains($t, 'вчера')) return [$today->modify('-1 day')->format('Y-m-d'), $today->format('Y-m-d'), 'вчера'];
  if (preg_match('/прошл\w* недел/u', $t)) { $f = $today->modify('monday this week')->modify('-7 days'); return [$f->format('Y-m-d'), $f->modify('+7 days')->format('Y-m-d'), 'на прошлой неделе']; }
  if (preg_match('/(эт\w* недел|за недел|на недел)/u', $t)) { $f = $today->modify('monday this week'); return [$f->format('Y-m-d'), $f->modify('+7 days')->format('Y-m-d'), 'на этой неделе']; }
  if (preg_match('/прошл\w* месяц/u', $t)) { $f = $today->modify('first day of last month'); return [$f->format('Y-m-d'), $f->modify('first day of next month')->format('Y-m-d'), 'в прошлом месяце']; }
  if (preg_match('/(за год|в этом году|этот год)/u', $t)) return [$today->format('Y') . '-01-01', ($today->format('Y') + 1) . '-01-01', 'в этом году'];
  if (preg_match('/(январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр)\w*(?:\s+(\d{4}))?/u', $t, $m)) {
    $key = str_starts_with($m[1], 'ма') ? 'ма' : $m[1];
    $month = RU_MONTHS_IN[$key] ?? null;
    if ($month) {
      $year = isset($m[2]) ? (int)$m[2] : (int)$today->format('Y');
      if (!isset($m[2]) && $month > (int)$today->format('n')) $year--;
      $f = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
      return [$f->format('Y-m-d'), $f->modify('first day of next month')->format('Y-m-d'), 'в ' . $m[0]];
    }
  }
  $f = $today->modify('first day of this month');
  return [$f->format('Y-m-d'), $f->modify('first day of next month')->format('Y-m-d'), 'в этом месяце'];
}

function answer_question(string $text, int $userId): ?string {
  global $config;
  $t = mb_strtolower(trim($text));
  if (!empty($config['anthropic_api_key'])) {
    try { $ai = ai_answer($text, $userId); if ($ai !== null) return $ai; } catch (Throwable $e) { error_log('ai answer failed: ' . $e->getMessage()); }
  }
  [$from, $until, $label] = question_period($t);
  if (preg_match('/(остат|баланс)/u', $t)) return format_balances(balances_until((new DateTimeImmutable('tomorrow'))->format('Y-m-d')));
  $s = range_summary($from, $until);
  $category = null;
  foreach (category_groups_map() as $name => $g) if (str_contains($t, mb_strtolower(mb_substr((string)$name, 0, max(4, mb_strlen((string)$name) - 2))))) { $category = (string)$name; break; }
  $category ??= preg_match('/на ([\p{L}\s]+?)(\s+(в|за|на)\s|\?|$)/u', $t, $cm) ? categorize_text($cm[1]) : null;
  if (str_contains($t, 'сравни')) {
    $prevFrom = (new DateTimeImmutable($from))->modify('-1 month')->format('Y-m-d');
    $p = range_summary($prevFrom, $from);
    $diff = $s['expenses'] - $p['expenses'];
    $out = "📊 Расходы $label: " . fmt_money($s['expenses']) . "\nМесяцем раньше: " . fmt_money($p['expenses']) . "\nРазница: " . ($diff >= 0 ? '+' : '−') . fmt_money(abs($diff));
    $changes = [];
    foreach ($s['categories'] as $c => $v) $changes[$c] = $v['total'] - ($p['categories'][$c]['total'] ?? 0);
    arsort($changes);
    $up = array_slice(array_filter($changes, fn($v) => $v > 0), 0, 3, true);
    if ($up) { $out .= "\n\nВыросли больше всего:"; foreach ($up as $c => $v) $out .= "\n· $c +" . fmt_money($v); }
    return $out;
  }
  if (preg_match('/кто (больше|меньше)/u', $t)) {
    $rows = [];
    foreach ($s['members'] as $m) $rows[$m['name']] = $category ? ($m['categories'][$category] ?? 0) : $m['expenses'];
    arsort($rows);
    $out = '👥 ' . ($category ? "На «{$category}» $label" : "Траты $label") . ':';
    foreach ($rows as $n => $v) $out .= "\n· $n — " . fmt_money($v);
    return $out;
  }
  if (preg_match('/(на что|больше всего|топ|куда)/u', $t)) {
    $out = "🏷 Больше всего потратили $label:";
    $i = 0;
    foreach ($s['categories'] as $c => $v) { $out .= "\n" . (++$i) . ". $c — " . fmt_money($v['total']); if ($i >= 5) break; }
    return $i ? $out : "Трат $label не было.";
  }
  if (preg_match('/(сколько|потрат|ушло|трат)/u', $t)) {
    $who = null;
    foreach ($s['members'] as $m) if (str_contains($t, mb_strtolower(mb_substr($m['name'], 0, 4)))) $who = $m;
    if ($category) {
      $v = $who ? ($who['categories'][$category] ?? 0) : ($s['categories'][$category]['total'] ?? 0);
      return '🧾 ' . ($who ? $who['name'] . ' потратил(а)' : 'Потратили') . " на «{$category}» $label: " . fmt_money($v);
    }
    $v = $who ? $who['expenses'] : $s['expenses'];
    return '🧾 ' . ($who ? $who['name'] . ' потратил(а)' : 'Потратили') . " $label: " . fmt_money($v) . " ({$s['count']} оп.)";
  }
  return null;
}

// Optional: Claude answers over a compact summary of the family's data
function ai_answer(string $question, int $userId): ?string {
  global $config;
  $autoload = $config['anthropic_autoload'] ?? '/var/www/mirzhan/data/famfin-lib/vendor/autoload.php';
  if (!is_file($autoload)) return null;
  require_once $autoload;
  $today = new DateTimeImmutable('today');
  $months = [];
  for ($i = 5; $i >= 0; $i--) {
    $f = $today->modify('first day of this month')->modify("-$i months");
    $s = range_summary($f->format('Y-m-d'), $f->modify('first day of next month')->format('Y-m-d'));
    $cats = [];
    foreach ($s['categories'] as $c => $v) $cats[$c] = round($v['total']);
    $members = [];
    foreach ($s['members'] as $m) $members[$m['name']] = ['expenses' => round($m['expenses']), 'topups' => round($m['topups']), 'by_category' => array_map('round', $m['categories'])];
    $months[$f->format('Y-m')] = ['expenses' => round($s['expenses']), 'topups' => round($s['topups']), 'by_category' => $cats, 'members' => $members];
  }
  $b = balances_until($today->modify('+1 day')->format('Y-m-d'));
  $data = ['today' => $today->format('Y-m-d'), 'currency' => 'KZT (₸)', 'asked_by' => user_label($userId), 'months' => $months,
    'balance' => ['shared' => round($b['shared']['balance']), 'members' => array_column(array_map(fn($m) => ['n' => $m['name'], 'b' => round($m['balance'])], $b['members']), 'b', 'n')],
    'limits' => array_map(fn($l) => ['category' => $l['label'], 'period' => $l['period'], 'limit' => $l['limit'], 'spent' => $l['spent']], limits_status($userId)['items']),
    'debts' => array_map(fn($d) => ['person' => $d['person'], 'direction' => $d['direction'], 'amount' => $d['amount']], debts_list()),
    'deposits' => array_map(fn($d) => ['title' => $d['title'], 'amount' => $d['amount'], 'rate' => $d['rate']], deposits_list())];
  $client = new \Anthropic\Client(apiKey: $config['anthropic_api_key']);
  $message = $client->messages->create(
    model: $config['anthropic_model'] ?? 'claude-opus-5-5',
    maxTokens: 16000,
    system: 'Ты помощник семейного бюджета в Telegram. Отвечай по-русски, коротко (до 6 строк), только по данным из JSON ниже; суммы в тенге с пробелами между тысячами. Если данных не хватает — так и скажи. Без markdown-таблиц.' . "\n\nДанные:\n" . json_encode($data, JSON_UNESCAPED_UNICODE),
    messages: [['role' => 'user', 'content' => $question]],
  );
  if ($message->stopReason === 'refusal') return null;
  $text = '';
  foreach ($message->content as $block) if ($block->type === 'text') $text .= $block->text;
  return trim($text) !== '' ? '🤖 ' . trim($text) : null;
}
