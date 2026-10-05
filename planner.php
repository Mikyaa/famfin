<?php
declare(strict_types=1);
// Third wave: change log and trash, monthly plan (envelopes), month calendar, subscription
// finder, shopping list, voice and Siri input, foreign currencies, year summary.

/* ========== SCHEMA ========== */
function planner_schema(PDO $pdo): void {
  $sqlite = is_sqlite();
  $t = $sqlite ? 'TEXT' : 'VARCHAR(255)';
  $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
  $ts = $sqlite ? 'TEXT DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
  $cs = $sqlite ? '' : ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log(id $id,telegram_id BIGINT NULL,action VARCHAR(16) NOT NULL,entity VARCHAR(16) NOT NULL,entity_id BIGINT NULL,summary $t NOT NULL,created_at $ts)$cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS trash(id $id,entity VARCHAR(16) NOT NULL,payload TEXT NOT NULL,summary $t NOT NULL,deleted_by BIGINT NULL,deleted_at $ts)$cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS plan_items(period CHAR(7) NOT NULL,category VARCHAR(80) NOT NULL,amount DECIMAL(14,2) NOT NULL,PRIMARY KEY(period,category))$cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS shopping(id $id,title VARCHAR(120) NOT NULL,done INTEGER NOT NULL DEFAULT 0,created_by BIGINT NULL,created_at $ts,done_at VARCHAR(20) NULL)$cs");
  if (!has_column($pdo, 'transactions', 'orig_amount')) $pdo->exec('ALTER TABLE transactions ADD COLUMN orig_amount DECIMAL(14,2) NULL');
  if (!has_column($pdo, 'transactions', 'orig_currency')) $pdo->exec('ALTER TABLE transactions ADD COLUMN orig_currency VARCHAR(3) NULL');
  if (!has_column($pdo, 'deposits', 'currency')) $pdo->exec("ALTER TABLE deposits ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'KZT'");
  family_schema($pdo);
}

/* ========== CHANGE LOG AND TRASH ========== */
// Who is acting in this request (set by api.php / bot), so low-level writes can be attributed
function set_actor(?int $id): void { $GLOBALS['FAMFIN_ACTOR'] = $id; }
function actor(): ?int { return $GLOBALS['FAMFIN_ACTOR'] ?? null; }
function audit_mute(bool $on): void { $GLOBALS['FAMFIN_AUDIT_MUTE'] = $on; }

function audit(string $action, string $entity, ?int $entityId, string $summary): void {
  if (!empty($GLOBALS['FAMFIN_AUDIT_MUTE'])) return;
  db()->prepare('INSERT INTO audit_log(telegram_id,action,entity,entity_id,summary) VALUES(?,?,?,?,?)')->execute([actor(), $action, $entity, $entityId, mb_substr($summary, 0, 250)]);
}

function tx_summary(array $t): string {
  $sign = $t['kind'] === 'topup' ? '+' : '−';
  return $t['category'] . ' ' . $sign . fmt_money((float)$t['amount']) . ' · ' . (new DateTimeImmutable($t['occurred_on'] ?? $t['date']))->format('d.m.Y') . (($t['note'] ?? '') !== '' ? ' · ' . $t['note'] : '');
}

// Keeps a deleted row restorable for 30 days
function trash_put(string $entity, array $row, string $summary): void {
  db()->prepare('INSERT INTO trash(entity,payload,summary,deleted_by) VALUES(?,?,?,?)')->execute([$entity, json_encode($row, JSON_UNESCAPED_UNICODE), $summary, actor()]);
}

function trash_list(): array {
  $names = allowed_members_map();
  $out = [];
  foreach (db()->query('SELECT id,entity,summary,deleted_by,deleted_at FROM trash ORDER BY id DESC LIMIT 200') as $r) {
    $out[] = ['id' => (int)$r['id'], 'entity' => $r['entity'], 'summary' => $r['summary'], 'who' => $names[(int)$r['deleted_by']] ?? '—', 'at' => $r['deleted_at']];
  }
  return $out;
}

function trash_restore(int $id): string {
  $q = db()->prepare('SELECT * FROM trash WHERE id=?');
  $q->execute([$id]);
  $r = $q->fetch();
  if (!$r) throw new RuntimeException('Запись уже восстановлена или удалена навсегда');
  $row = json_decode($r['payload'], true);
  $table = ['transaction' => 'transactions', 'transfer' => 'transfers'][$r['entity']] ?? null;
  if (!$table || !$row) throw new RuntimeException('Эту запись нельзя восстановить');
  // Same id if it is still free, so links (labels, recurring) keep working
  $exists = db()->prepare("SELECT COUNT(*) FROM $table WHERE id=?");
  $exists->execute([$row['id']]);
  if ((int)$exists->fetchColumn() > 0) unset($row['id']);
  $cols = array_keys($row);
  db()->prepare("INSERT INTO $table(" . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
  db()->prepare('DELETE FROM trash WHERE id=?')->execute([$id]);
  audit('restore', $r['entity'], isset($row['id']) ? (int)$row['id'] : null, 'Восстановлено: ' . $r['summary']);
  mark_balance_changed();
  return $r['summary'];
}

function trash_purge(): int {
  $q = db()->prepare('DELETE FROM trash WHERE deleted_at<?');
  $q->execute([(new DateTimeImmutable('-30 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);
  return $q->rowCount();
}

function audit_list(int $limit = 150): array {
  $names = allowed_members_map();
  $labels = ['create' => 'добавил(а)', 'update' => 'изменил(а)', 'delete' => 'удалил(а)', 'restore' => 'восстановил(а)', 'import' => 'импортировал(а)', 'settings' => 'изменил(а) настройки'];
  $out = [];
  foreach (db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))) as $r) {
    $out[] = ['who' => $names[(int)$r['telegram_id']] ?? 'Бот', 'action' => $r['action'], 'verb' => $labels[$r['action']] ?? $r['action'], 'summary' => $r['summary'], 'at' => $r['created_at']];
  }
  return $out;
}

/* ========== MONTHLY PLAN (envelopes) ========== */
// Income of the month is spread over categories; each envelope shows planned, spent and left
function plan_status(?string $period = null): array {
  $period = $period ?: date('Y-m');
  $from = $period . '-01';
  $until = (new DateTimeImmutable($from))->modify('first day of next month')->format('Y-m-d');
  $s = range_summary($from, $until);
  $q = db()->prepare('SELECT category,amount FROM plan_items WHERE period=?');
  $q->execute([$period]);
  $plan = [];
  foreach ($q as $r) $plan[(string)$r['category']] = (float)$r['amount'];
  $items = [];
  foreach (category_groups_map() as $cat => $g) {
    $spent = $s['categories'][$cat]['total'] ?? 0.0;
    if (!isset($plan[$cat]) && $spent == 0.0) continue;
    $p = $plan[$cat] ?? 0.0;
    $items[] = ['category' => (string)$cat, 'group' => $g, 'planned' => $p, 'spent' => $spent, 'left' => round($p - $spent, 2)];
  }
  usort($items, fn($a, $b) => [$b['planned'] > 0, $b['planned']] <=> [$a['planned'] > 0, $a['planned']]);
  $planned = array_sum($plan);
  return ['period' => $period, 'income' => $s['topups'], 'planned' => $planned, 'unallocated' => round($s['topups'] - $planned, 2),
    'spent' => $s['expenses'], 'items' => $items];
}

// Short version for the main tab tile
function plan_brief(): array {
  $q = db()->prepare('SELECT COUNT(*) FROM plan_items WHERE period=?');
  $q->execute([date('Y-m')]);
  if ((int)$q->fetchColumn() === 0) return ['has' => false];
  $p = plan_status();
  $over = count(array_filter($p['items'], fn($i) => $i['planned'] > 0 && $i['left'] < 0));
  return ['has' => true, 'planned' => $p['planned'], 'spent' => array_sum(array_map(fn($i) => $i['planned'] > 0 ? $i['spent'] : 0, $p['items'])), 'over' => $over];
}

function plan_save(string $period, array $amounts): void {
  if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new RuntimeException('Неверный месяц');
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $pdo->prepare('DELETE FROM plan_items WHERE period=?')->execute([$period]);
    $ins = $pdo->prepare('INSERT INTO plan_items(period,category,amount) VALUES(?,?,?)');
    foreach ($amounts as $cat => $v) {
      $cat = trim((string)$cat);
      $v = (float)preg_replace('/[^\d.]/', '', str_replace(',', '.', (string)$v));
      if ($cat === '' || $v <= 0) continue;
      $ins->execute([$period, mb_substr($cat, 0, 80), round($v, 2)]);
    }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  $months = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
  audit('settings', 'plan', null, 'План на ' . $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4));
}

function plan_copy_previous(string $period): int {
  $prev = (new DateTimeImmutable($period . '-01'))->modify('-1 month')->format('Y-m');
  $q = db()->prepare('SELECT category,amount FROM plan_items WHERE period=?');
  $q->execute([$prev]);
  $rows = $q->fetchAll(PDO::FETCH_KEY_PAIR);
  if (!$rows) throw new RuntimeException('В прошлом месяце плана не было');
  plan_save($period, $rows);
  return count($rows);
}

/* ========== CALENDAR ========== */
function month_calendar(string $period): array {
  if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
  $first = new DateTimeImmutable($period . '-01');
  $until = $first->modify('first day of next month');
  $events = [];
  $add = function(string $date, string $type, string $title, ?float $amount, string $tone = '') use (&$events) { $events[$date][] = ['type' => $type, 'title' => $title, 'amount' => $amount, 'tone' => $tone]; };
  foreach (db()->query('SELECT * FROM recurring WHERE active=1') as $r) {
    $due = recurring_date_in($period, (int)$r['day']);
    $done = $r['done_period'] === $period;
    $add($due, 'payment', $r['category'] . ($r['note'] !== '' ? ' · ' . $r['note'] : '') . ($done ? ' (оплачено)' : ''), (float)$r['amount'], $done ? 'done' : 'neg');
  }
  foreach (debts_list() as $d) if ($d['due_on'] && $d['due_on'] >= $first->format('Y-m-d') && $d['due_on'] < $until->format('Y-m-d')) {
    $add($d['due_on'], 'debt', ($d['direction'] === 'owed_to_me' ? 'Вернут: ' : 'Вернуть: ') . $d['person'], $d['amount'], $d['direction'] === 'owed_to_me' ? 'pos' : 'neg');
  }
  foreach (deposits_list() as $dep) { $est = deposit_interest_estimate($dep); if ($est >= 1) $add($first->format('Y-m-d'), 'interest', 'Проценты: ' . $dep['title'], round($est * ($dep['amount'] > 0 ? $dep['amount_kzt'] / $dep['amount'] : 1)), 'pos'); }
  foreach (goals_list() as $g) if ($g['deadline'] && $g['deadline'] >= $first->format('Y-m-d') && $g['deadline'] < $until->format('Y-m-d')) $add($g['deadline'], 'goal', 'Цель: ' . $g['title'], $g['left'], '');
  // Salary days: the usual day of the "Зарплата" top-ups of the last months
  $q = db()->prepare("SELECT occurred_on,amount FROM transactions WHERE kind='topup' AND category='Зарплата' AND occurred_on>=? ORDER BY occurred_on");
  $q->execute([$first->modify('-4 months')->format('Y-m-d')]);
  $days = []; $sums = [];
  foreach ($q as $r) { $days[] = (int)substr($r['occurred_on'], 8, 2); $sums[] = (float)$r['amount']; }
  if (count($days) >= 2) {
    sort($days);
    $day = $days[intdiv(count($days), 2)];
    $add(recurring_date_in($period, $day), 'salary', 'Зарплата (обычно в этот день)', round(array_sum($sums) / count($sums)), 'pos');
  }
  $spent = [];
  $q = db()->prepare("SELECT occurred_on,SUM(amount) s FROM transactions WHERE kind='expense' AND occurred_on>=? AND occurred_on<? GROUP BY occurred_on");
  $q->execute([$first->format('Y-m-d'), $until->format('Y-m-d')]);
  foreach ($q as $r) $spent[$r['occurred_on']] = (float)$r['s'];
  ksort($events);
  return ['period' => $period, 'events' => $events, 'spent' => $spent];
}

/* ========== SUBSCRIPTION FINDER ========== */
// Same merchant, similar amount, in at least 3 different months of the last 6 → probably a subscription
function find_subscriptions(): array {
  $q = db()->prepare("SELECT occurred_on,amount,category,note FROM transactions WHERE kind='expense' AND occurred_on>=? AND recurring_id IS NULL");
  $q->execute([(new DateTimeImmutable('first day of this month'))->modify('-6 months')->format('Y-m-d')]);
  $groups = [];
  foreach ($q as $r) {
    $name = trim(preg_replace('/[\d*#№:.,\-]+/u', ' ', mb_strtolower((string)$r['note'])));
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if (mb_strlen($name) < 3) continue;
    $groups[$name][] = $r;
  }
  $existing = array_map(fn($r) => mb_strtolower($r['note'] . ' ' . $r['category']), recurring_list());
  $out = [];
  foreach ($groups as $name => $rows) {
    $months = array_unique(array_map(fn($r) => substr($r['occurred_on'], 0, 7), $rows));
    if (count($months) < 3) continue;
    $amounts = array_map(fn($r) => (float)$r['amount'], $rows);
    sort($amounts);
    $median = $amounts[intdiv(count($amounts), 2)];
    $similar = array_filter($amounts, fn($a) => abs($a - $median) <= max(50, $median * 0.15));
    // Mostly one similar charge per month, not daily coffee
    if (count($similar) < 3 || count($rows) > count($months) * 2) continue;
    $already = (bool)array_filter($existing, fn($e) => str_contains($e, $name));
    $days = array_map(fn($r) => (int)substr($r['occurred_on'], 8, 2), $rows);
    sort($days);
    $out[] = ['name' => $rows[0]['note'], 'category' => $rows[count($rows) - 1]['category'], 'amount' => round($median), 'months' => count($months),
      'day' => $days[intdiv(count($days), 2)], 'per_year' => round($median * 12), 'already' => $already];
  }
  usort($out, fn($a, $b) => $b['per_year'] <=> $a['per_year']);
  return $out;
}

/* ========== SHOPPING LIST ========== */
function shopping_list(): array {
  return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'done' => (bool)$r['done']], db()->query('SELECT id,title,done FROM shopping ORDER BY done,id')->fetchAll());
}

// "молоко, хлеб и яйца" → three items
function shopping_add(string $text, int $userId): array {
  $parts = preg_split('/\s*(?:,|;|\n|\sи\s)\s*/u', trim($text)) ?: [];
  $added = [];
  $ins = db()->prepare('INSERT INTO shopping(title,created_by) VALUES(?,?)');
  foreach ($parts as $p) {
    $p = trim($p, " .\t");
    if ($p === '' || mb_strlen($p) > 120) continue;
    $ins->execute([mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1), $userId]);
    $added[] = $p;
  }
  return $added;
}

// Bot message with one button per item (tap = bought / not bought)
function shopping_message(int $userId): array {
  $items = shopping_list();
  if (!$items) return ["🛒 Список покупок пуст.
Добавьте: «купить молоко, хлеб»", null];
  $labels = []; $opts = [];
  foreach (array_slice($items, 0, 40) as $i) { $labels[] = ($i['done'] ? '✅ ' : '⬜ ') . mb_substr($i['title'], 0, 40); $opts[] = (string)$i['id']; }
  $labels[] = '🧹 Убрать купленное'; $opts[] = 'clear';
  $labels[] = '🔄 Обновить'; $opts[] = 'refresh';
  $left = count(array_filter($items, fn($i) => !$i['done']));
  return ["🛒 Список покупок · осталось купить: $left
Нажмите на пункт, чтобы отметить.", bot_keyboard($userId, ['type' => 'shop', 'opts' => $opts], $labels, 1)];
}

function bot_send_shopping(int $chatId, int $userId): void {
  [$text, $markup] = shopping_message($userId);
  $p = ['chat_id' => $chatId, 'text' => $text];
  if ($markup) $p['reply_markup'] = $markup;
  telegram('sendMessage', $p);
}

function shopping_toggle(int $id): void { db()->prepare('UPDATE shopping SET done=1-done,done_at=? WHERE id=?')->execute([date('Y-m-d'), $id]); }
function shopping_delete(int $id): void { db()->prepare('DELETE FROM shopping WHERE id=?')->execute([$id]); }
function shopping_clear_done(): array {
  $titles = db()->query('SELECT title FROM shopping WHERE done=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
  db()->exec('DELETE FROM shopping WHERE done=1');
  return $titles;
}

/* ========== SPOKEN NUMBERS (voice and Siri) ========== */
// "двенадцать тысяч триста" → 12300, "полторы тысячи" → 1500, "пять с половиной тысяч" → 5500
function spoken_numbers_to_digits(string $text): string {
  $units = ['ноль' => 0, 'один' => 1, 'одна' => 1, 'одну' => 1, 'два' => 2, 'две' => 2, 'три' => 3, 'четыре' => 4, 'пять' => 5, 'шесть' => 6, 'семь' => 7, 'восемь' => 8, 'девять' => 9,
    'десять' => 10, 'одиннадцать' => 11, 'двенадцать' => 12, 'тринадцать' => 13, 'четырнадцать' => 14, 'пятнадцать' => 15, 'шестнадцать' => 16, 'семнадцать' => 17, 'восемнадцать' => 18, 'девятнадцать' => 19,
    'двадцать' => 20, 'тридцать' => 30, 'сорок' => 40, 'пятьдесят' => 50, 'шестьдесят' => 60, 'семьдесят' => 70, 'восемьдесят' => 80, 'девяносто' => 90,
    'сто' => 100, 'двести' => 200, 'триста' => 300, 'четыреста' => 400, 'пятьсот' => 500, 'шестьсот' => 600, 'семьсот' => 700, 'восемьсот' => 800, 'девятьсот' => 900, 'полторы' => 1.5, 'полтора' => 1.5];
  $mult = ['тысяча' => 1000, 'тысячи' => 1000, 'тысяч' => 1000, 'тыщ' => 1000, 'тыщи' => 1000, 'миллион' => 1000000, 'миллиона' => 1000000, 'миллионов' => 1000000];
  $words = preg_split('/\s+/u', mb_strtolower(trim($text))) ?: [];
  $out = []; $total = 0.0; $chunk = 0.0; $active = false;
  $flush = function() use (&$out, &$total, &$chunk, &$active) { if ($active) { $out[] = (string)round($total + $chunk, 2); } $total = 0.0; $chunk = 0.0; $active = false; };
  for ($i = 0; $i < count($words); $i++) {
    $w = $words[$i];
    if (isset($units[$w])) { $chunk += $units[$w]; $active = true; continue; }
    if (is_numeric($w) && $active === false && isset($words[$i + 1], $mult[$words[$i + 1]])) { $chunk = (float)$w; $active = true; continue; }
    if ($active && $w === 'с' && ($words[$i + 1] ?? '') === 'половиной') { $chunk += 0.5; $i++; continue; }
    if ($active && isset($mult[$w])) { $total += max(1, $chunk) * $mult[$w]; $chunk = 0.0; continue; }
    $flush();
    $out[] = $w;
  }
  $flush();
  return implode(' ', $out);
}

/* ========== VOICE MESSAGES ========== */
function speech_to_text(string $file): string {
  global $config;
  $tools = $config['tools_dir'] ?? '/var/www/mirzhan/data/famfin-tools';
  $py = $tools . '/venv/bin/python';
  $model = $config['vosk_model'] ?? ($tools . '/vosk-model-small-ru-0.22');
  if (!is_executable($py) || !is_dir($model)) throw new RuntimeException('Распознавание речи не установлено на сервере');
  $proc = proc_open([$py, __DIR__ . '/tools/stt.py', $file, $model], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($proc)) throw new RuntimeException('Не удалось запустить распознавание речи');
  $out = trim((string)stream_get_contents($pipes[1]));
  stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  proc_close($proc);
  return $out;
}

function bot_handle_voice(int $chatId, int $userId, array $voice): void {
  if ((int)($voice['duration'] ?? 0) > 60) { telegram('sendMessage', ['chat_id' => $chatId, 'text' => '🎤 Слишком длинное сообщение — скажите коротко: «кафе пять тысяч».']); return; }
  $r = telegram('sendMessage', ['chat_id' => $chatId, 'text' => '🎤 Слушаю…']);
  db()->prepare("INSERT INTO statement_jobs(telegram_id,chat_id,file_id,file_name,message_id,kind) VALUES(?,?,?,?,?,'voice')")
    ->execute([$userId, $chatId, (string)$voice['file_id'], 'voice.ogg', $r['result']['message_id'] ?? null]);
}

function process_voice_job(array $job): void {
  $tmp = tempnam(sys_get_temp_dir(), 'voc');
  $chat = (int)$job['chat_id'];
  $say = fn(string $t) => $job['message_id'] ? telegram('editMessageText', ['chat_id' => $chat, 'message_id' => (int)$job['message_id'], 'text' => $t]) : telegram('sendMessage', ['chat_id' => $chat, 'text' => $t]);
  try {
    telegram_download($job['file_id'], $tmp);
    $heard = speech_to_text($tmp);
    db()->prepare("UPDATE statement_jobs SET status='done' WHERE id=?")->execute([$job['id']]);
    if ($heard === '') { $say('🎤 Не разобрал слова. Попробуйте ещё раз, чуть громче и медленнее.'); return; }
    $text = spoken_numbers_to_digits($heard);
    $say("🎤 Распознал: «{$text}»");
    if (!bot_handle_text($chat, (int)$job['telegram_id'], $text)) {
      telegram('sendMessage', ['chat_id' => $chat, 'text' => 'Не понял, что записать. Скажите сумму и на что: «такси две тысячи», «продукты пятнадцать тысяч».']);
    }
  } catch (Throwable $e) {
    db()->prepare("UPDATE statement_jobs SET status='error',error=? WHERE id=?")->execute([mb_substr($e->getMessage(), 0, 500), $job['id']]);
    $say('⚠️ ' . ($e instanceof RuntimeException ? $e->getMessage() : 'Не удалось распознать голосовое'));
  } finally {
    @unlink($tmp);
  }
}

/* ========== QUICK INPUT (Siri, Shortcuts) ========== */
function quick_token(int $userId, bool $renew = false): string {
  $key = 'quick_token:' . $userId;
  $t = get_setting($key);
  if ($t === null || $renew) { $t = bin2hex(random_bytes(16)); set_setting($key, $t); }
  return $t;
}

function quick_user(string $token): ?int {
  global $config;
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
  foreach ($config['allowed_users'] as $id) if (hash_equals((string)get_setting('quick_token:' . $id, ''), $token)) return $id;
  return null;
}

// Shared by voice messages and Siri: a question, a shopping item, or an operation
function handle_free_text(int $userId, string $text, ?int $chatId = null): string {
  $text = spoken_numbers_to_digits($text);
  // Siri phrases: "добавь трату кафе 5000", "запиши расход такси 2000"
  $text = trim(preg_replace('/^(?:добавь|добавить|запиши|записать|внеси)?\s*(?:трату|траты|расход|покупку)?\s*:?\s*/u', '', mb_strtolower($text))) ?: $text;
  $t = mb_strtolower($text);
  if (preg_match('/^(?:купить|в список|добавь в список|список)\s*:?\s*(.+)$/u', $t, $m)) {
    $added = shopping_add($m[1], $userId);
    return $added ? '🛒 В списке покупок: ' . implode(', ', $added) : 'Не понял, что добавить в список';
  }
  if (looks_like_question($text) && ($a = answer_question($text, $userId)) !== null) return $a;
  $e = parse_entry_text($text);
  if (!$e) return 'Не понял. Скажите, например: «кафе пять тысяч» или «купить молоко и хлеб».';
  if ($e['category'] === null) $e['category'] = 'Другое';
  $saved = bot_save_entry($userId, $e);
  if ($chatId) telegram('sendMessage', ['chat_id' => $chatId, 'text' => bot_entry_text($saved['entry'], user_label($userId)) . bot_limit_lines($saved['limits']), 'reply_markup' => bot_tx_markup($userId, $saved['id'])]);
  return bot_entry_text($saved['entry'], user_label($userId), '✅ Записал') . bot_limit_lines($saved['limits']);
}

/* ========== CURRENCIES ========== */
const FX_CODES = ['USD' => '$', 'EUR' => '€', 'RUB' => '₽'];

// KZT per one unit, from the National Bank of Kazakhstan (cached for the day)
function fx_rates(): array {
  $cached = json_decode((string)get_setting('fx_rates', ''), true);
  if (is_array($cached) && ($cached['date'] ?? '') === date('Y-m-d')) return $cached['rates'];
  // After a failed fetch wait an hour before asking the bank again, so pages never hang on it
  if ((int)get_setting('fx_retry_after', '0') > time() || !empty($GLOBALS['FAMFIN_NO_FX'])) return is_array($cached) ? $cached['rates'] : [];
  $rates = [];
  try {
    $ch = curl_init('https://nationalbank.kz/rss/rates_all.xml');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $xml = curl_exec($ch);
    curl_close($ch);
    if ($xml && ($doc = @simplexml_load_string((string)$xml))) {
      foreach ($doc->channel->item ?? [] as $item) {
        $code = (string)$item->title;
        if (isset(FX_CODES[$code])) $rates[$code] = (float)$item->description / max(1, (float)$item->quant);
      }
    }
  } catch (Throwable $e) { error_log('fx: ' . $e->getMessage()); }
  if ($rates) { set_setting('fx_rates', json_encode(['date' => date('Y-m-d'), 'rates' => $rates])); return $rates; }
  set_setting('fx_retry_after', (string)(time() + 3600));
  return is_array($cached) ? $cached['rates'] : [];
}

function fmt_cur(float $v, string $currency): string {
  if ($currency === 'KZT' || $currency === '') return fmt_money($v);
  return number_format($v, $v == floor($v) ? 0 : 2, ',', ' ') . ' ' . (FX_CODES[$currency] ?? $currency);
}

function to_kzt(float $amount, string $currency): float {
  if ($currency === 'KZT' || $currency === '') return $amount;
  $r = fx_rates()[$currency] ?? null;
  if (!$r) throw new RuntimeException('Нет курса ' . $currency . ' — попробуйте позже');
  return round($amount * $r, 2);
}

// "20$", "20 usd", "€15", "500 руб" in a phrase (lower case)
const FX_PATTERNS = [
  'USD' => '/(\$|(?<![a-z])usd(?![a-z])|(?<![а-я])доллар(?:ов|а|ы)?(?![а-я])|(?<![а-я])бакс(?:ов|а|ы)?(?![а-я]))/u',
  'EUR' => '/(€|(?<![a-z])eur(?![a-z])|(?<![а-я])евро(?![а-я]))/u',
  'RUB' => '/(₽|(?<![a-z])rub(?![a-z])|(?<![а-я])руб(?:л(?:ей|я|ь))?\.?(?![а-я]))/u',
];

/* ========== YEAR SUMMARY ========== */
function year_summary_text(int $year): string {
  $from = "$year-01-01"; $until = ($year + 1) . '-01-01';
  $s = range_summary($from, $until);
  $q = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM goal_moves WHERE occurred_on>=? AND occurred_on<?');
  $q->execute([$from, $until]);
  $saved = (float)$q->fetchColumn();
  $months = months_series($until, 12);
  $top = ''; $topV = -1;
  foreach ($months as $m) if ($m['expenses'] > $topV) { $topV = $m['expenses']; $top = $m['month']; }
  $names = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
  $lines = ["🎉 Итоги $year года", '', 'Потрачено: ' . fmt_money($s['expenses']) . " ({$s['count']} оп.)", 'Пополнения: ' . fmt_money($s['topups'])];
  if (abs($saved) >= 1) $lines[] = 'Отложено в цели: ' . fmt_money($saved);
  $lines[] = 'В среднем в месяц: ' . fmt_money($s['expenses'] / ($year === (int)date('Y') ? (int)date('n') : 12));
  if ($topV > 0) $lines[] = 'Самый дорогой месяц: ' . $names[(int)substr($top, 5) - 1] . ' — ' . fmt_money($topV);
  $i = 0;
  if ($s['categories']) { $lines[] = ''; $lines[] = 'Топ категорий:'; foreach ($s['categories'] as $c => $v) { $lines[] = (++$i) . ". $c — " . fmt_money($v['total']); if ($i >= 5) break; } }
  $dep = array_sum(array_map(fn($d) => $d['amount_kzt'] ?? $d['amount'], deposits_list()));
  if ($dep > 0) $lines[] = "\nНа депозитах сейчас: " . fmt_money($dep);
  return implode("\n", $lines);
}

function send_year_summary(int $year, ?array $to = null): void {
  global $config;
  require_once __DIR__ . '/report_image.php';
  $png = tempnam(sys_get_temp_dir(), 'year') . '.png';
  render_weekly_report_png(period_report_data("$year-01-01", ($year + 1) . '-01-01'), $png);
  $caption = mb_substr(year_summary_text($year), 0, 1000);
  foreach ($to ?? $config['allowed_users'] as $uid) {
    if (!empty($config['local_test_mode']) && !getenv('FAMFIN_DRY_TELEGRAM')) { error_log("[year to $uid] $caption"); continue; }
    try { telegram_multipart('sendPhoto', ['chat_id' => (string)$uid, 'caption' => $caption, 'photo' => new CURLFile($png, 'image/png', "itogi-$year.png")]); } catch (Throwable $e) { error_log('year send: ' . $e->getMessage()); }
  }
  @unlink($png);
}
