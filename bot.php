<?php
declare(strict_types=1);
// Bot side of the budget: free-text entries ("кафе 5000"), inline keyboards and their
// callbacks, recurring-payment reminders. Keyboards point at a stored bot_actions row,
// so callback_data stays short ("a:<id>:<option>") whatever the category names are.

/* ========== ACTIONS ========== */
function bot_action_create(int $userId, array $payload): int {
  db()->prepare('INSERT INTO bot_actions(telegram_id,payload) VALUES(?,?)')->execute([$userId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
  // Housekeeping: keyboards older than 30 days are dead anyway
  db()->prepare('DELETE FROM bot_actions WHERE created_at<?')->execute([(new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s')]);
  return (int)db()->lastInsertId();
}

function bot_action_get(int $id): ?array {
  $q = db()->prepare('SELECT payload FROM bot_actions WHERE id=?');
  $q->execute([$id]);
  $p = $q->fetchColumn();
  return $p === false ? null : json_decode((string)$p, true);
}

// Keyboard of options (label => value), two per row; returns reply_markup
function bot_keyboard(int $userId, array $payload, array $labels, int $perRow = 2): array {
  $id = bot_action_create($userId, $payload);
  $rows = []; $row = [];
  foreach ($labels as $i => $label) {
    $row[] = ['text' => $label, 'callback_data' => "a:$id:$i"];
    if (count($row) === $perRow) { $rows[] = $row; $row = []; }
  }
  if ($row) $rows[] = $row;
  return ['inline_keyboard' => $rows];
}

/* ========== PARSING ========== */
// "кафе 5000", "12 300 продукты магнум", "вчера такси 1.8к", "+350 000 зарплата", "28.09 аптека 4500"
function parse_entry_text(string $text): ?array {
  $orig = trim(preg_replace('/\s+/u', ' ', $text));
  $t = mb_strtolower($orig);
  $date = date('Y-m-d');
  $consume = function(string $pattern) use (&$t, &$orig) {
    if (!preg_match($pattern, $t, $m, PREG_OFFSET_CAPTURE)) return null;
    // Remove the same span from the original-case text (byte offsets match: same characters, only case differs in length-safe way)
    $start = mb_strlen(substr($t, 0, $m[0][1]));
    $len = mb_strlen($m[0][0]);
    $orig = trim(mb_substr($orig, 0, $start) . ' ' . mb_substr($orig, $start + $len));
    $t = trim(mb_substr($t, 0, $start) . ' ' . mb_substr($t, $start + $len));
    return $m;
  };
  if ($consume('/(^|\s)позавчера(?=\s|$)/u')) $date = date('Y-m-d', strtotime('-2 days'));
  elseif ($consume('/(^|\s)вчера(?=\s|$)/u')) $date = date('Y-m-d', strtotime('-1 day'));
  elseif ($m = $consume('/(^|\s)(0?[1-9]|[12]\d|3[01])\.(0?[1-9]|1[0-2])(?:\.(\d{2}|\d{4}))?(?=\s|$)/u')) {
    $y = isset($m[4]) && $m[4][0] !== '' ? (strlen($m[4][0]) === 2 ? '20' . $m[4][0] : $m[4][0]) : date('Y');
    $cand = sprintf('%04d-%02d-%02d', (int)$y, (int)$m[3][0], (int)$m[2][0]);
    if (valid_date($cand, true) && $cand > date('Y-m-d') && !isset($m[4])) $cand = sprintf('%04d-%02d-%02d', (int)$y - 1, (int)$m[3][0], (int)$m[2][0]);
    if (!valid_date($cand)) return null;
    $date = $cand;
  }
  $plus = (bool)preg_match('/^\+/u', $t);
  $m = $consume('/(?<![\d.,])\+?(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+)(?:[.,](\d{1,2}))?\s*(к|k|тыс\.?|тысяч[аи]?)?(?=[\s₸]|тг|тенге|$)/u');
  if (!$m) return null;
  $amount = (float)(str_replace([' ', "\u{00A0}"], '', $m[1][0]) . (isset($m[2]) && $m[2][0] !== '' ? '.' . $m[2][0] : ''));
  if (isset($m[3]) && $m[3][0] !== '') $amount *= 1000;
  if ($amount <= 0 || $amount > 100000000) return null;
  $consume('/(^|\s)(₸|тг|тенге|kzt)(?=\s|$)/u');
  $t = trim(trim($t), '+-—:,. ');
  $orig = trim(trim($orig), '+-—:,. ');
  $topup = $plus || preg_match('/зарплат|пополн|доход|аванс|преми|кэшбэк|кешбэк|cashback/u', $t);
  if ($topup) {
    $category = str_contains($t, 'зарплат') ? 'Зарплата' : 'Пополнение';
    return ['kind' => 'topup', 'amount' => round($amount, 2), 'category' => $category, 'note' => $orig, 'date' => $date];
  }
  $category = $t !== '' ? categorize_text($t) : null;
  // The words that only name the category are not worth keeping as a note
  $note = ($category !== null && $orig !== '' && mb_stripos($category, $orig) === 0) ? '' : $orig;
  return ['kind' => 'expense', 'amount' => round($amount, 2), 'category' => $category, 'note' => mb_substr($note, 0, 500), 'date' => $date];
}

/* ========== MESSAGES ========== */
function bot_entry_text(array $e, string $who, string $head = '✅ Записал'): string {
  $kind = $e['kind'] === 'topup' ? 'пополнение' : 'расход';
  $sign = $e['kind'] === 'topup' ? '+' : '−';
  $d = new DateTimeImmutable($e['date']);
  $day = $e['date'] === date('Y-m-d') ? 'сегодня' : ($e['date'] === date('Y-m-d', strtotime('-1 day')) ? 'вчера' : $d->format('d.m.Y'));
  $text = "$head $kind: {$e['category']} $sign" . fmt_money((float)$e['amount']);
  if (($e['note'] ?? '') !== '') $text .= "\n📝 " . $e['note'];
  return $text . "\n👤 $who · $day";
}

function bot_limit_lines(array $limits): string {
  $out = [];
  foreach ($limits as $l) {
    if ($l['state'] === 'ok') continue;
    $name = limit_title($l['scope'], $l['category'], $l['period']);
    $out[] = ($l['state'] === 'over' ? '🔴 ' : '🟡 ') . $name . ': ' . ($l['remaining'] >= 0 ? 'осталось ' . fmt_money($l['remaining']) : 'превышен на ' . fmt_money(-$l['remaining']));
  }
  return $out ? "\n\n" . implode("\n", $out) : '';
}

function bot_category_labels(string $kind): array {
  if ($kind === 'topup') return ['Зарплата', 'Пополнение', 'Возврат', 'Подарок'];
  return array_column(known_categories(), 'category');
}

function bot_tx_markup(int $userId, int $txId): array {
  return bot_keyboard($userId, ['type' => 'tx_menu', 'tx' => $txId, 'opts' => ['change', 'undo']], ['🗂 Категория', '↩️ Отменить']);
}

/* ========== TEXT ENTRY ========== */
function bot_handle_text(int $chatId, int $userId, string $text): bool {
  $e = parse_entry_text($text);
  if (!$e) return false;
  $who = user_label($userId);
  if ($e['category'] === null) {
    $labels = bot_category_labels('expense');
    $markup = bot_keyboard($userId, ['type' => 'draft', 'entry' => $e, 'opts' => array_merge($labels, ['__cancel'])], array_merge($labels, ['✖️ Не записывать']));
    telegram('sendMessage', ['chat_id' => $chatId, 'text' => 'Куда отнести ' . fmt_money($e['amount']) . ($e['note'] !== '' ? " («{$e['note']}»)" : '') . '?', 'reply_markup' => $markup]);
    return true;
  }
  $saved = bot_save_entry($userId, $e);
  telegram('sendMessage', ['chat_id' => $chatId, 'text' => bot_entry_text($saved['entry'], $who) . bot_limit_lines($saved['limits']), 'reply_markup' => bot_tx_markup($userId, $saved['id'])]);
  return true;
}

function bot_save_entry(int $userId, array $raw): array {
  $e = validate_entry($raw);
  $id = insert_transaction($userId, $e);
  $limits = $e['kind'] === 'expense' ? check_limit_alerts($e['category'], $e['date'], ['id' => $userId, 'name' => user_label($userId)], (float)$e['amount']) : [];
  return ['id' => $id, 'entry' => $e, 'limits' => $limits];
}

/* ========== STATEMENT FILES ========== */
function bot_handle_document(int $chatId, int $userId, array $doc): void {
  $name = (string)($doc['file_name'] ?? 'файл');
  $isPdf = ($doc['mime_type'] ?? '') === 'application/pdf' || preg_match('/\.pdf$/i', $name);
  $isText = preg_match('/\.txt$/i', $name);
  if (!$isPdf && !$isText) { telegram('sendMessage', ['chat_id' => $chatId, 'text' => 'Пришлите выписку Kaspi Gold в PDF — я разберу её и предложу импорт.']); return; }
  if ((int)($doc['file_size'] ?? 0) > 20 * 1024 * 1024) { telegram('sendMessage', ['chat_id' => $chatId, 'text' => 'Файл больше 20 МБ — Telegram не даст боту его скачать. Выгрузите выписку за меньший период.']); return; }
  $r = telegram('sendMessage', ['chat_id' => $chatId, 'text' => "📄 Получил «{$name}». Разбираю выписку — пришлю итог в течение минуты."]);
  db()->prepare('INSERT INTO statement_jobs(telegram_id,chat_id,file_id,file_name,message_id) VALUES(?,?,?,?,?)')
    ->execute([$userId, $chatId, (string)$doc['file_id'], mb_substr($name, 0, 200), $r['result']['message_id'] ?? null]);
}

// Rows of the chosen window; import_rows() itself skips or links what is already in the budget
function bot_statement_entries(array $rows, bool $all, string $from = '0000-00-00'): array {
  $out = [];
  foreach (statement_keys($rows) as $r) {
    if ((!$all && empty($r['main']) && !$r['purchase']) || $r['date'] < $from) continue;
    $out[] = ['kind' => $r['kind'], 'amount' => $r['amount'], 'category' => $r['category'], 'note' => $r['note'], 'date' => $r['date'], 'key' => $r['key'],
      'payer_id' => $r['payer_id'] ?? null, 'account' => $r['account'] ?? ''];
  }
  return $out;
}

function bot_import_report(array $res, array $entries, int $payer): string {
  $created = array_flip($res['ids']);
  $lines = ['✅ Импорт · ' . user_label($payer), 'Новых операций: ' . count($res['ids'])];
  if ($res['ids']) {
    $byId = [];
    $sumExp = 0.0; $sumTop = 0.0;
    $q = db()->prepare('SELECT kind,amount FROM transactions WHERE id IN (' . implode(',', array_map('intval', $res['ids'])) . ')');
    $q->execute();
    foreach ($q as $t) { if ($t['kind'] === 'expense') $sumExp += (float)$t['amount']; else $sumTop += (float)$t['amount']; }
    if ($sumExp) $lines[] = '   расходы ' . fmt_money($sumExp);
    if ($sumTop) $lines[] = '   пополнения ' . fmt_money($sumTop);
  }
  if ($res['ids']) {
    $q = db()->prepare("SELECT telegram_id,SUM(amount) s FROM transactions WHERE kind='topup' AND id IN (" . implode(',', array_map('intval', $res['ids'])) . ") GROUP BY telegram_id");
    $q->execute();
    foreach ($q as $t) if ((int)$t['telegram_id'] !== $payer) $lines[] = '   из них пополнения ' . user_label((int)$t['telegram_id']) . ': ' . fmt_money((float)$t['s']);
  }
  if ($res['linked']) $lines[] = '✋ Совпали с записанными вручную: ' . count($res['linked']) . ' — не задвоены, отмечены как из выписки';
  if ($res['skipped']) $lines[] = '♻️ Уже были импортированы раньше: ' . $res['skipped'] . ' — пропущены';
  $lines[] = '';
  $lines[] = 'Категории можно поправить в приложении — нажмите на операцию.';
  return implode("\n", $lines);
}

/* ========== CALLBACKS ========== */
function bot_handle_callback(array $cb): void {
  global $config;
  $userId = (int)($cb['from']['id'] ?? 0);
  $chatId = $cb['message']['chat']['id'] ?? null;
  $msgId = $cb['message']['message_id'] ?? null;
  $answer = fn(string $text = '') => telegram('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => $text]);
  if (!in_array($userId, $config['allowed_users'], true) || !$chatId || !$msgId) { $answer('Нет доступа'); return; }
  if (!preg_match('/^a:(\d+):(\d+)$/', (string)($cb['data'] ?? ''), $m)) { $answer(); return; }
  $p = bot_action_get((int)$m[1]);
  $opt = $p['opts'][(int)$m[2]] ?? null;
  if (!$p || $opt === null) { $answer('Кнопка устарела'); return; }
  $edit = function(string $text, ?array $markup = null) use ($chatId, $msgId) {
    $payload = ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text];
    if ($markup) $payload['reply_markup'] = $markup;
    telegram('editMessageText', $payload);
  };
  try {
    switch ($p['type']) {
      case 'draft':
        if ($opt === '__cancel') { $edit('✖️ Не записано'); $answer(); return; }
        $saved = bot_save_entry($userId, ['category' => $opt] + $p['entry']);
        $edit(bot_entry_text($saved['entry'], user_label($userId)) . bot_limit_lines($saved['limits']), bot_tx_markup($userId, $saved['id']));
        $answer('Записано');
        return;
      case 'tx_menu':
        $tx = get_transaction((int)$p['tx']);
        if (!$tx) { $edit('Запись уже удалена'); $answer(); return; }
        if ($opt === 'undo') {
          delete_transaction((int)$p['tx']);
          $edit('↩️ Отменено: ' . $tx['category'] . ' ' . fmt_money((float)$tx['amount']));
          $answer('Запись удалена');
          return;
        }
        $labels = bot_category_labels($tx['kind']);
        $markup = bot_keyboard($userId, ['type' => 'tx_setcat', 'tx' => (int)$p['tx'], 'opts' => array_merge($labels, ['__back'])], array_merge($labels, ['← Назад']));
        $edit('Выберите категорию для ' . fmt_money((float)$tx['amount']) . ':', $markup);
        $answer();
        return;
      case 'tx_setcat':
        $tx = get_transaction((int)$p['tx']);
        if (!$tx) { $edit('Запись уже удалена'); $answer(); return; }
        if ($opt !== '__back') {
          update_transaction((int)$p['tx'], ['kind' => $tx['kind'], 'amount' => $tx['amount'], 'category' => $opt, 'note' => $tx['note'], 'date' => $tx['occurred_on']]);
          $tx = get_transaction((int)$p['tx']);
        }
        $e = ['kind' => $tx['kind'], 'amount' => (float)$tx['amount'], 'category' => $tx['category'], 'note' => $tx['note'], 'date' => $tx['occurred_on']];
        $edit(bot_entry_text($e, user_label((int)$tx['telegram_id']), $opt === '__back' ? '✅ Записано' : '✏️ Категория изменена'), bot_tx_markup($userId, (int)$tx['id']));
        $answer($opt === '__back' ? '' : 'Готово');
        return;
      case 'statement':
        // Several imports from one statement are safe: source labels filter what came in before
        if ($opt === 'cancel') { $edit('✖️ Импорт отменён — ничего не записано'); $answer(); return; }
        if ($opt === 'list') {
          foreach (statement_list_messages($p['rows']) as $chunk) telegram('sendMessage', ['chat_id' => $chatId, 'text' => "🛒 покупка · ↔️ перевод/пополнение · ♻️ уже есть\n\n" . $chunk]);
          $answer();
          return;
        }
        if ($opt === 'reconcile') {
          $meta = $p['meta'] ?? [];
          if (empty($meta['available'])) { $answer('В выписке нет строки «Доступно на»'); return; }
          $r = reconcile_account($meta['account'], (int)$p['payer'], $meta['available']['date'], (float)$meta['available']['amount'], $userId);
          $markup = $r['id'] ? bot_keyboard($userId, ['type' => 'adjust_undo', 'id' => $r['id'], 'opts' => ['undo']], ['↩️ Отменить сверку']) : null;
          telegram('sendMessage', ['chat_id' => $chatId, 'text' => reconcile_report($r, $meta['account'], (float)$meta['available']['amount'], $meta['available']['date']), 'reply_markup' => $markup]);
          $answer($r['id'] ? 'Остаток сверен' : 'Уже совпадает');
          return;
        }
        if (str_starts_with($opt, 'payer:')) {
          $payer = (int)substr($opt, 6);
          if (!is_member_id($payer)) { $answer(); return; }
          // Member attribution is relative to the card holder: recompute it for the new holder
          foreach ($p['rows'] as &$row) { if (!empty($row['incoming'])) $row['payer_id'] = member_in_text((string)$row['note'], $payer); }
          unset($row);
          $rows = mark_statement_duplicates($p['rows'], $payer);
          $edit(statement_summary($rows, $payer, '', $p['meta'] ?? []), statement_markup($userId, (int)$p['job'], $rows, $payer, $p['meta'] ?? []));
          $answer('Выписка: ' . user_label($payer));
          return;
        }
        // "buy:month", "buy:3m", "buy:all", "all:month"; legacy "buy"/"all" mean this month only
        [$what, $scopeKey] = array_pad(explode(':', $opt, 2), 2, 'month');
        $from = '0000-00-00';
        foreach (statement_scopes($p['rows']) as $sc) if ($sc['key'] === $scopeKey) $from = $sc['from'];
        $entries = bot_statement_entries($p['rows'], $what === 'all', $from);
        if (!$entries) { $answer('Нечего импортировать'); return; }
        $res = import_rows($entries, (int)$p['payer']);
        // Nothing new: keep the message (and any earlier "undo" button) as it is
        if (!$res['ids'] && !$res['linked']) { $answer('Всё из этой выписки уже в бюджете'); return; }
        db()->prepare("UPDATE statement_jobs SET status='imported' WHERE id=?")->execute([(int)$p['job']]);
        $labels = ['↩️ Отменить импорт']; $opts = ['undo'];
        $meta = $p['meta'] ?? [];
        if (!empty($meta['available']) && !empty($meta['account'])) { array_unshift($labels, '⚖️ Сверить остаток с банком'); array_unshift($opts, 'reconcile'); }
        $undo = bot_keyboard($userId, ['type' => 'statement_undo', 'ids' => $res['ids'], 'linked' => $res['linked'], 'job' => (int)$p['job'], 'payer' => (int)$p['payer'], 'meta' => $meta, 'opts' => $opts], $labels, 1);
        $edit(bot_import_report($res, $entries, (int)$p['payer']), $undo);
        $answer($res['ids'] ? 'Готово' : 'Новых операций нет');
        return;
      case 'adjust_undo':
        delete_adjustment((int)$p['id']);
        $edit('↩️ Сверка отменена — корректировка остатка удалена');
        $answer('Отменено');
        return;
      case 'statement_undo':
        if ($opt === 'reconcile') {
          $meta = $p['meta'];
          $r = reconcile_account($meta['account'], (int)$p['payer'], $meta['available']['date'], (float)$meta['available']['amount'], $userId);
          $markup = $r['id'] ? bot_keyboard($userId, ['type' => 'adjust_undo', 'id' => $r['id'], 'opts' => ['undo']], ['↩️ Отменить сверку']) : null;
          telegram('sendMessage', ['chat_id' => $chatId, 'text' => reconcile_report($r, $meta['account'], (float)$meta['available']['amount'], $meta['available']['date']), 'reply_markup' => $markup]);
          $answer($r['id'] ? 'Остаток сверен' : 'Уже совпадает');
          return;
        }
        $n = undo_import($p['ids'] ?? [], $p['linked'] ?? []);
        db()->prepare("UPDATE statement_jobs SET status='undone' WHERE id=?")->execute([(int)$p['job']]);
        $edit("↩️ Импорт отменён: удалено операций — $n" . (!empty($p['linked']) ? ', с ручных записей снята отметка источника — ' . count($p['linked']) : ''));
        $answer('Отменено');
        return;
      case 'rec':
        $r = get_recurring((int)$p['rid']);
        if (!$r) { $edit('Платёж удалён'); $answer(); return; }
        if ($opt === 'skip') { recurring_skip((int)$p['rid']); $edit("⏭ {$r['category']}: пропущено в этом месяце"); $answer('Пропущено'); return; }
        $res = recurring_pay((int)$p['rid'], ['id' => $userId, 'name' => user_label($userId)]);
        $edit(bot_entry_text($res['entry'], $res['payer']['name'], '✅ Оплачено и записано') . bot_limit_lines($res['limits']), bot_tx_markup($userId, $res['tx_id']));
        $answer('Записано');
        return;
    }
    $answer();
  } catch (RuntimeException $e) {
    $answer(mb_substr($e->getMessage(), 0, 190));
  }
}

/* ========== RECURRING REMINDERS ========== */
// Called daily: reminds about payments due today (or earlier this month and still open)
function send_recurring_reminders(): int {
  global $config;
  $period = date('Y-m');
  $sent = 0;
  foreach (db()->query('SELECT * FROM recurring WHERE active=1') as $r) {
    if ($r['done_period'] === $period || $r['skipped_period'] === $period || $r['reminded_period'] === $period) continue;
    if (recurring_date_in($period, (int)$r['day']) > date('Y-m-d')) continue;
    $to = (int)$r['payer_id'] ? [(int)$r['payer_id']] : $config['allowed_users'];
    $kind = $r['kind'] === 'topup' ? 'Ожидается пополнение' : 'Пора оплатить';
    $text = "🔔 $kind: {$r['category']} — " . fmt_money((float)$r['amount']) . ($r['note'] !== '' ? "\n📝 {$r['note']}" : '') . "\n\nЗаписать в бюджет?";
    foreach ($to as $uid) {
      $markup = bot_keyboard($uid, ['type' => 'rec', 'rid' => (int)$r['id'], 'opts' => ['pay', 'skip']], ['✅ Оплачено', '⏭ Пропустить']);
      if (!empty($config['local_test_mode']) && !getenv('FAMFIN_DRY_TELEGRAM')) { error_log("[remind $uid] $text"); continue; }
      try { telegram('sendMessage', ['chat_id' => $uid, 'text' => $text, 'reply_markup' => $markup]); $sent++; } catch (Throwable $e) { error_log('reminder failed: ' . $e->getMessage()); }
    }
    db()->prepare('UPDATE recurring SET reminded_period=? WHERE id=?')->execute([$period, $r['id']]);
  }
  return $sent;
}
