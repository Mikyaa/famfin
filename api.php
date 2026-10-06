<?php
declare(strict_types=1);
require __DIR__.'/lib.php';

function body(): array {
  $d = json_decode(file_get_contents('php://input'), true);
  return is_array($d) ? $d : [];
}

try {
  $member = auth_member();
  save_member($member);
  set_actor((int)$member['id']);
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = $_GET['action'] ?? 'main';

  if ($method === 'GET' && $action === 'main') {
    $today = new DateTimeImmutable('today');
    $tomorrow = $today->modify('+1 day')->format('Y-m-d');
    $balances = balances_until($tomorrow);
    [$mFrom, $mUntil] = normalize_period(null, null, 'month');
    $month = range_summary($mFrom, $mUntil);
    [$wFrom, $wUntil] = normalize_period(null, null, 'week');
    $week = range_summary($wFrom, $wUntil);
    $recent = db()->query("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,t.import_account account,t.orig_amount,t.orig_currency,t.split,t.photo IS NOT NULL has_photo,t.trip_id,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id ORDER BY t.occurred_on DESC,t.id DESC LIMIT 10")->fetchAll();
    foreach ($recent as &$r) $r['group'] = category_group_of($r['category'], $r['cg'] ?: null);
    unset($r);
    json_out([
      'me' => $member,
      'balances' => $balances,
      'month' => $month,
      'week' => $week,
      'recent' => $recent,
      'transfers' => list_transfers(5),
      'goals' => goals_list(),
      'debts' => debts_list(),
      'accounts' => accounts_list(),
      'settings' => ['big_expense_threshold' => big_expense_threshold()],
      'deposits' => deposits_list(),
      'reset' => reset_status(),
      'forecast' => month_forecast(),
      'recurring' => recurring_list(),
      'members' => array_map(fn($id, $name) => ['id' => $id, 'name' => $name], array_keys(allowed_members_map()), allowed_members_map()),
      'limits' => limits_status((int)$member['id']),
      'category_groups' => category_groups_all(),
      'shopping_left' => count(array_filter(shopping_list(), fn($i) => !$i['done'])),
      'plan' => plan_brief(),
      'fx' => fx_rates(),
      'settlement' => settlement_status(),
      'trip' => ($t = trip_active()) ? $t + trip_summary($t) : null,
      'prefs' => ['digest' => digest_enabled((int)$member['id'])] + ['roundup' => roundup_settings()],
    ]);
  }

  /* ----- wave 3: log, trash, plan, calendar, subscriptions, shopping, Siri, year ----- */
  /* ----- wave 4: split, photos, rules, compare, round-up, trips, category PDF ----- */
  if ($method === 'POST' && $action === 'settle') { $s = settle_up((int)$member['id']); json_out(['ok' => true, 'settled' => $s, 'settlement' => settlement_status()]); }
  if ($method === 'POST' && $action === 'photo') {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!empty($d['remove'])) photo_remove((int)($d['id'] ?? 0)); else photo_save((int)($d['id'] ?? 0), (string)($d['data'] ?? ''));
    json_out(['ok' => true]);
  }
  if ($method === 'GET' && $action === 'photo') {
    $f = photo_file((int)($_GET['id'] ?? 0));
    if (!$f) json_out(['error' => 'Фото нет'], 404);
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
  }
  if ($method === 'GET' && $action === 'rules') { json_out(['items' => rules_list()]); }
  if ($method === 'POST' && $action === 'rule_save') { $d = body(); $r = rule_save((string)($d['note'] ?? ''), (string)($d['category'] ?? ''), (int)$member['id']); json_out(['ok' => true] + $r + ['items' => rules_list()]); }
  if ($method === 'POST' && $action === 'rule_delete') { rule_delete((string)(body()['pattern'] ?? '')); json_out(['ok' => true, 'items' => rules_list()]); }
  if ($method === 'POST' && $action === 'prefs') {
    $d = body();
    if (isset($d['digest'])) set_setting('digest:' . (int)$member['id'], $d['digest'] ? '1' : '0');
    if (isset($d['roundup_goal'])) { set_setting('roundup_goal', (string)(int)$d['roundup_goal']); set_setting('roundup_step', (string)(int)($d['roundup_step'] ?? 1000)); audit('settings', 'roundup', null, (int)$d['roundup_goal'] ? 'Копилка-округление включена' : 'Копилка-округление выключена'); }
    json_out(['ok' => true, 'prefs' => ['digest' => digest_enabled((int)$member['id']), 'roundup' => roundup_settings()]]);
  }
  if ($method === 'GET' && $action === 'digest_preview') { json_out(['text' => digest_text((int)$member['id'])]); }
  if ($method === 'GET' && $action === 'trips') { json_out(['items' => trips_list()]); }
  if ($method === 'GET' && $action === 'trip_ops') {
    $q = db()->prepare("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,t.orig_amount,t.orig_currency,t.split,t.photo IS NOT NULL has_photo,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id WHERE t.trip_id=? ORDER BY t.occurred_on DESC,t.id DESC LIMIT 300");
    $q->execute([(int)($_GET['id'] ?? 0)]);
    json_out(['items' => $q->fetchAll()]);
  }
  if ($method === 'POST' && $action === 'trip_save') { trip_save(body()); json_out(['ok' => true, 'items' => trips_list()]); }
  if ($method === 'POST' && $action === 'trip_end') { trip_end((int)(body()['id'] ?? 0)); json_out(['ok' => true, 'items' => trips_list()]); }
  if ($method === 'POST' && $action === 'trip_delete') { trip_delete((int)(body()['id'] ?? 0)); json_out(['ok' => true, 'items' => trips_list()]); }
  if ($method === 'POST' && $action === 'export_categories') {
    $d = body();
    $y = (int)($d['year'] ?? date('Y'));
    if ($y < 2000 || $y > (int)date('Y')) json_out(['error' => 'Неверный год'], 422);
    $cats = is_array($d['categories'] ?? null) ? $d['categories'] : [];
    $file = export_category_pdf("$y-01-01", ($y + 1) . '-01-01', $cats);
    try {
      $r = telegram_multipart('sendDocument', ['chat_id' => (string)$member['id'], 'caption' => "📎 Расходы по категориям за $y год: " . mb_substr(implode(', ', $cats), 0, 300),
        'document' => new CURLFile($file, 'application/pdf', "rashody-$y.pdf")]);
    } finally { @unlink($file); }
    if (!($r['ok'] ?? false)) json_out(['error' => 'Не удалось отправить файл в Telegram'], 502);
    json_out(['ok' => true]);
  }

  if ($method === 'GET' && $action === 'audit') { json_out(['items' => audit_list()]); }
  if ($method === 'GET' && $action === 'trash') { json_out(['items' => trash_list()]); }
  if ($method === 'POST' && $action === 'trash_restore') { $s = trash_restore((int)(body()['id'] ?? 0)); json_out(['ok' => true, 'restored' => $s, 'items' => trash_list()]); }
  if ($method === 'GET' && $action === 'plan') { json_out(plan_status((string)($_GET['period'] ?? date('Y-m'))) + ['categories' => array_keys(category_groups_map())]); }
  if ($method === 'POST' && $action === 'plan_save') {
    $d = body();
    $period = (string)($d['period'] ?? date('Y-m'));
    if (!empty($d['copy_previous'])) plan_copy_previous($period); else plan_save($period, is_array($d['items'] ?? null) ? $d['items'] : []);
    json_out(['ok' => true] + plan_status($period) + ['categories' => array_keys(category_groups_map())]);
  }
  if ($method === 'GET' && $action === 'calendar') { json_out(month_calendar((string)($_GET['month'] ?? date('Y-m')))); }
  if ($method === 'GET' && $action === 'subscriptions') { json_out(['items' => find_subscriptions()]); }
  if ($method === 'GET' && $action === 'shopping') { json_out(['items' => shopping_list()]); }
  if ($method === 'POST' && $action === 'shopping') {
    $d = body();
    $op = (string)($d['op'] ?? '');
    if ($op === 'add') shopping_add((string)($d['text'] ?? ''), (int)$member['id']);
    elseif ($op === 'toggle') shopping_toggle((int)($d['id'] ?? 0));
    elseif ($op === 'delete') shopping_delete((int)($d['id'] ?? 0));
    elseif ($op === 'clear') shopping_clear_done();
    json_out(['ok' => true, 'items' => shopping_list()]);
  }
  if ($method === 'GET' && $action === 'quick_token') {
    $t = quick_token((int)$member['id'], ($_GET['renew'] ?? '') === '1');
    json_out(['url' => rtrim($config['app_url'], '/') . '/quick.php?t=' . $t]);
  }
  if ($method === 'POST' && $action === 'year_send') {
    $y = (int)(body()['year'] ?? date('Y'));
    if ($y < 2000 || $y > (int)date('Y')) json_out(['error' => 'Неверный год'], 422);
    send_year_summary($y, [(int)$member['id']]);
    json_out(['ok' => true]);
  }

  if ($method === 'GET' && $action === 'account') { json_out(['operations' => account_operations((string)($_GET['name'] ?? ''))]); }
  if ($method === 'POST' && $action === 'account_save') {
    $d = body();
    $old = trim((string)($d['old_name'] ?? ''));
    if ($old !== '') account_rename($old, (string)($d['name'] ?? ''), (string)($d['kind'] ?? 'card')); else account_add((string)($d['name'] ?? ''), (string)($d['kind'] ?? 'card'));
    json_out(['ok' => true, 'accounts' => accounts_list()]);
  }
  if ($method === 'POST' && $action === 'account_delete') { account_delete((string)(body()['name'] ?? '')); json_out(['ok' => true, 'accounts' => accounts_list()]); }
  if ($method === 'POST' && $action === 'account_reconcile') {
    $d = body();
    $target = filter_var(str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)($d['balance'] ?? '')), FILTER_VALIDATE_FLOAT);
    if ($target === false) json_out(['error' => 'Введите фактический остаток'], 422);
    $r = reconcile_account((string)($d['name'] ?? ''), (int)$member['id'], date('Y-m-d'), (float)$target, (int)$member['id']);
    json_out(['ok' => true, 'diff' => $r['diff'], 'accounts' => accounts_list()]);
  }
  if ($method === 'GET' && $action === 'categories') { json_out(['items' => categories_overview()]); }
  if ($method === 'POST' && $action === 'category_update') {
    $d = body();
    category_update((string)($d['name'] ?? ''), (string)($d['new_name'] ?? ''), (string)($d['group'] ?? 'variable'), $member);
    json_out(['ok' => true, 'items' => categories_overview(), 'category_groups' => category_groups_all()]);
  }
  if ($method === 'POST' && $action === 'category_delete') {
    $d = body();
    category_delete((string)($d['name'] ?? ''), (string)($d['move_to'] ?? ''));
    json_out(['ok' => true, 'items' => categories_overview(), 'category_groups' => category_groups_all()]);
  }
  if ($method === 'POST' && $action === 'settings') {
    $d = body();
    if (isset($d['big_expense_threshold'])) {
      $v = (int)preg_replace('/\D/', '', (string)$d['big_expense_threshold']);
      if ($v > 100000000) json_out(['error' => 'Слишком большая сумма'], 422);
      set_setting('big_expense_threshold', (string)$v);
    }
    json_out(['ok' => true, 'settings' => ['big_expense_threshold' => big_expense_threshold()]]);
  }
  // Inside Telegram a download is unreliable: the bot sends the file to the member's chat instead
  if ($method === 'POST' && $action === 'export_send') {
    $d = body();
    [$from, $until] = normalize_period($d['from'] ?? null, $d['to'] ?? null, 'month');
    $fmt = in_array($d['format'] ?? '', ['xlsx', 'pdf'], true) ? $d['format'] : 'xlsx';
    $file = $fmt === 'xlsx' ? export_xlsx($from, $until) : export_pdf($from, $until);
    $name = 'family-budget-' . $from . '_' . (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d') . '.' . $fmt;
    try {
      $r = telegram_multipart('sendDocument', ['chat_id' => (string)$member['id'], 'caption' => '📎 Отчёт за ' . (new DateTimeImmutable($from))->format('d.m.Y') . ' — ' . (new DateTimeImmutable($until))->modify('-1 day')->format('d.m.Y'),
        'document' => new CURLFile($file, $fmt === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf', $name)]);
    } finally { @unlink($file); }
    if (!($r['ok'] ?? false)) json_out(['error' => 'Не удалось отправить файл в Telegram'], 502);
    json_out(['ok' => true]);
  }
  if ($method === 'GET' && ($action === 'export_xlsx' || $action === 'export_pdf')) {
    [$from, $until] = normalize_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['preset'] ?? 'month');
    $file = $action === 'export_xlsx' ? export_xlsx($from, $until) : export_pdf($from, $until);
    $name = 'family-budget-' . $from . '_' . (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d') . ($action === 'export_xlsx' ? '.xlsx' : '.pdf');
    header('Content-Type: ' . ($action === 'export_xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    @unlink($file);
    exit;
  }

  if ($method === 'GET' && $action === 'debt') {
    $id = (int)($_GET['id'] ?? 0);
    json_out(['moves' => debt_moves($id)]);
  }
  if ($method === 'POST' && $action === 'debt_save') { $id = debt_save(body(), $member); json_out(['ok' => true, 'id' => $id, 'debts' => debts_list()]); }
  if ($method === 'POST' && $action === 'debt_move') { $d = body(); debt_move((int)($d['id'] ?? 0), $d['amount'] ?? null, (string)($d['note'] ?? ''), $member); json_out(['ok' => true, 'debts' => debts_list()]); }
  if ($method === 'POST' && $action === 'debt_delete') { debt_delete((int)(body()['id'] ?? 0)); json_out(['ok' => true, 'debts' => debts_list()]); }
  if ($method === 'POST' && $action === 'deposit_save') { $id = deposit_save(body(), $member); json_out(['ok' => true, 'id' => $id, 'deposits' => deposits_list()]); }
  if ($method === 'GET' && $action === 'deposit') { json_out(['moves' => deposit_journal((int)($_GET['id'] ?? 0))]); }
  if ($method === 'POST' && $action === 'deposit_move') {
    $d = body();
    deposit_move((int)($d['id'] ?? 0), (string)($d['kind'] ?? ''), $d['amount'] ?? null, (string)($d['note'] ?? ''), (string)($d['date'] ?? ''), $member);
    json_out(['ok' => true, 'deposits' => deposits_list()]);
  }
  if ($method === 'POST' && $action === 'deposit_move_delete') { deposit_move_delete((int)(body()['id'] ?? 0)); json_out(['ok' => true, 'deposits' => deposits_list()]); }
  if ($method === 'POST' && $action === 'deposit_delete') { deposit_delete((int)(body()['id'] ?? 0)); json_out(['ok' => true, 'deposits' => deposits_list()]); }
  if ($method === 'POST' && $action === 'reset_request') { json_out(['ok' => true, 'reset' => reset_request($member)]); }

  if ($method === 'POST' && $action === 'category_add') {
    $d = body();
    add_custom_category((string)($d['name'] ?? ''), (string)($d['group'] ?? 'variable'), $member);
    json_out(['ok' => true, 'category_groups' => category_groups_all(), 'categories' => known_categories()]);
  }

  if ($method === 'GET' && $action === 'limits') {
    $rows = array_map(fn($r) => ['scope' => (int)$r['scope_id'] === 0 ? 'family' : 'me', 'category' => (string)$r['category'], 'period' => $r['period'], 'amount' => (float)$r['amount']], limit_rows((int)$member['id']));
    json_out(['status' => limits_status((int)$member['id']), 'limits' => $rows, 'categories' => known_categories(), 'warn_options' => WARN_PERCENTS]);
  }

  if ($method === 'POST' && $action === 'limits') {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!is_array($d['limits'] ?? null) || !array_is_list($d['limits'])) json_out(['error' => 'Нет данных'], 422);
    $warn = isset($d['warn_percent']) ? (int)$d['warn_percent'] : null;
    $rollover = isset($d['rollover']) ? (bool)$d['rollover'] : null;
    $r = save_limits($d['limits'], $warn, $rollover, $member);
    json_out(['ok' => true, 'changed' => $r['changed'], 'status' => limits_status((int)$member['id'])]);
  }

  if ($method === 'GET' && $action === 'report') {
    [$from, $until, $preset] = normalize_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['preset'] ?? 'month');
    $summary = range_summary($from, $until);
    [$pFrom, $pUntil] = previous_period($from, $until);
    $prev = range_summary($pFrom, $pUntil);
    $balances = balances_until($until);
    $daily = daily_series($from, $until);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $q = db()->prepare("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,t.import_account account,t.orig_amount,t.orig_currency,t.split,t.photo IS NOT NULL has_photo,t.trip_id,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id WHERE t.occurred_on>=? AND t.occurred_on<? ORDER BY t.occurred_on DESC,t.id DESC LIMIT $limit OFFSET $offset");
    $q->execute([$from, $until]);
    $rows = $q->fetchAll();
    foreach ($rows as &$r) $r['group'] = category_group_of($r['category'], $r['cg'] ?: null);
    unset($r);
    $countQ = db()->prepare('SELECT COUNT(*) FROM transactions WHERE occurred_on>=? AND occurred_on<?');
    $countQ->execute([$from, $until]);
    $total = (int)$countQ->fetchColumn();
    json_out([
      'preset' => $preset,
      'from' => $from,
      'to' => $summary['to'],
      'summary' => $summary,
      'previous' => ['from'=>$pFrom,'to'=>(new DateTimeImmutable($pUntil))->modify('-1 day')->format('Y-m-d'),'expenses'=>$prev['expenses'],'topups'=>$prev['topups'],'fixed'=>$prev['fixed'],'variable'=>$prev['variable'],'count'=>$prev['count']],
      'balances' => $balances,
      'daily' => $daily,
      'months' => months_series($until),
      'compare' => report_compare($from, $until),
      'transactions' => $rows,
      'pagination' => ['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total / $limit)],
      'category_groups' => category_groups_all(),
    ]);
  }

  if ($method === 'GET' && $action === 'drilldown') {
    [$from, $until] = normalize_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['preset'] ?? 'month');
    $group = $_GET['group'] ?? 'all';
    if (!in_array($group, ['all','fixed','variable'], true)) $group = 'all';
    $category = trim((string)($_GET['category'] ?? ''));

    $where = 'occurred_on>=? AND occurred_on<? AND kind=\'expense\'';
    $params = [$from, $until];
    if ($category !== '') {
      $where .= ' AND category = ?';
      $params[] = $category;
    }
    // Personal limits drill into the current member's own spending
    if (($_GET['member'] ?? '') === 'me') {
      $where .= ' AND transactions.telegram_id = ?';
      $params[] = $member['id'];
    }

    $catQ = db()->prepare("SELECT category, COALESCE(category_group,'') cg, SUM(amount) total, COUNT(*) n FROM transactions WHERE $where GROUP BY category, category_group ORDER BY total DESC");
    $catQ->execute($params);
    $categories = [];
    foreach ($catQ as $r) {
      $g = category_group_of($r['category'], $r['cg'] ?: null);
      if ($group !== 'all' && $g !== $group) continue;
      $categories[] = ['category'=>$r['category'], 'group'=>$g, 'total'=>(float)$r['total'], 'count'=>(int)$r['n']];
    }

    $txParams = $params;
    $txWhere = $where;
    if ($category !== '') {
      // already filtered
    }
    $txQ = db()->prepare("SELECT transactions.id, transactions.amount, transactions.category, COALESCE(transactions.category_group,'') cg, transactions.note, transactions.occurred_on, m.display_name FROM transactions JOIN members m ON m.telegram_id=transactions.telegram_id WHERE $txWhere ORDER BY transactions.occurred_on DESC, transactions.id DESC LIMIT 500");
    $txQ->execute($txParams);
    $transactions = [];
    foreach ($txQ as $r) {
      $g = category_group_of($r['category'], $r['cg'] ?: null);
      if ($group !== 'all' && $g !== $group) continue;
      $transactions[] = [
        'id'=>(int)$r['id'], 'amount'=>(float)$r['amount'], 'category'=>$r['category'],
        'group'=>$g, 'note'=>$r['note'], 'date'=>$r['occurred_on'], 'who'=>$r['display_name'],
      ];
    }

    $totalExpenses = array_sum(array_column($transactions, 'amount'));
    json_out([
      'from' => $from,
      'to' => (new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d'),
      'group' => $group,
      'category' => $category,
      'total' => $totalExpenses,
      'count' => count($transactions),
      'categories' => $categories,
      'transactions' => $transactions,
    ]);
  }

  if ($method === 'GET' && $action === 'export') {
    [$from, $until] = normalize_period($_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['preset'] ?? 'all');
    $q = db()->prepare("SELECT t.occurred_on,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id WHERE t.occurred_on>=? AND t.occurred_on<? ORDER BY t.occurred_on DESC,t.id DESC LIMIT 20000");
    $q->execute([$from, $until]);
    $rows = $q->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="family-budget-' . $from . '_' . $until . '.csv"');
    echo "\xEF\xBB\xBF";
    $f = fopen('php://output','w');
    fputcsv($f, ['Дата','Тип','Сумма','Категория','Группа','Комментарий','Кто'], ';');
    // Neutralise spreadsheet formulas in user-supplied text
    $cell = fn($v) => preg_match('/^[=+\-@	
]/u', (string)$v) ? "'" . $v : $v;
    foreach ($rows as $r) {
      $group = category_group_of($r['category'], $r['cg'] ?: null);
      fputcsv($f, [
        $r['occurred_on'],
        $r['kind']==='expense'?'Расход':'Пополнение',
        $r['amount'],
        $cell($r['category']),
        $group==='fixed'?'Обязательные':'Переменные',
        $cell($r['note']),
        $cell($r['display_name']),
      ], ';');
    }
    exit;
  }

  if ($method === 'POST' && $action === 'delete') {
    $d = body();
    $id = filter_var($d['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_out(['error'=>'Invalid ID'], 422);
    // Any family member can delete any record
    $ok = ($d['type'] ?? '') === 'transfer' ? delete_transfer($id) : delete_transaction($id);
    if (!$ok) json_out(['error'=>'Запись не найдена'], 404);
    json_out(['ok'=>true]);
  }

  if ($method === 'POST' && $action === 'update') {
    $d = body();
    $id = filter_var($d['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) json_out(['error'=>'Invalid ID'], 422);
    $e = update_transaction($id, $d);
    $limits = $e['kind'] === 'expense' ? check_limit_alerts($e['category'], $e['date'], ['id' => $e['payer_id'], 'name' => user_label($e['payer_id'])], (float)$e['amount']) : [];
    json_out(['ok'=>true, 'limits'=>$limits]);
  }

  if ($method === 'POST' && $action === 'transfer') {
    $d = body();
    $id = add_transfer((int)($d['from_id'] ?? $member['id']), (int)($d['to_id'] ?? 0), $d['amount'] ?? null, (string)($d['note'] ?? ''), (string)($d['date'] ?? date('Y-m-d')), (int)$member['id']);
    json_out(['ok'=>true, 'id'=>$id]);
  }

  // Between our own accounts (cash withdrawal, card to card): balances stay, only the accounts change
  if ($method === 'POST' && $action === 'account_move') {
    $d = body();
    $id = add_account_move((int)$member['id'], (string)($d['from'] ?? ''), (string)($d['to'] ?? ''), $d['amount'] ?? null, (string)($d['note'] ?? ''), (string)($d['date'] ?? date('Y-m-d')), (int)$member['id']);
    json_out(['ok'=>true, 'id'=>$id]);
  }
  if ($method === 'POST' && $action === 'tx_to_move') { json_out(['ok'=>true, 'id'=>transaction_to_move((int)(body()['id'] ?? 0), (int)$member['id'])]); }

  if ($method === 'POST' && $action === 'goal_save') { json_out(['ok'=>true, 'id'=>goal_save(body(), $member), 'goals'=>goals_list()]); }
  if ($method === 'POST' && $action === 'goal_move') { $d = body(); goal_move((int)($d['id'] ?? 0), $d['amount'] ?? null, $member); json_out(['ok'=>true, 'goals'=>goals_list()]); }
  if ($method === 'POST' && $action === 'goal_close') { goal_close((int)(body()['id'] ?? 0)); json_out(['ok'=>true, 'goals'=>goals_list()]); }

  if ($method === 'GET' && $action === 'recurring') { json_out(['items'=>recurring_list(), 'categories'=>known_categories()]); }
  if ($method === 'POST' && $action === 'recurring_save') { recurring_save(body(), $member); json_out(['ok'=>true, 'items'=>recurring_list()]); }
  if ($method === 'POST' && $action === 'recurring_delete') { recurring_delete((int)(body()['id'] ?? 0)); json_out(['ok'=>true, 'items'=>recurring_list()]); }
  if ($method === 'POST' && $action === 'recurring_skip') { recurring_skip((int)(body()['id'] ?? 0)); json_out(['ok'=>true, 'items'=>recurring_list()]); }
  if ($method === 'POST' && $action === 'recurring_pay') {
    $d = body();
    $r = recurring_pay((int)($d['id'] ?? 0), $member, $d['amount'] ?? null);
    json_out(['ok'=>true, 'limits'=>$r['limits'], 'items'=>recurring_list()]);
  }

  if ($method === 'GET' && $action === 'search') {
    json_out(['items' => search_operations((string)($_GET['q'] ?? ''))]);
  }

  if ($method === 'POST' && $action === 'import_preview') {
    $d = body();
    $text = (string)($d['text'] ?? '');
    if (strlen($text) > 3000000) json_out(['error'=>'Слишком большой файл'], 413);
    $payer = (int)($d['payer_id'] ?? $member['id']);
    if (!is_member_id($payer)) json_out(['error'=>'Неверный участник'], 422);
    json_out(['rows' => parse_bank_statement($text, $payer), 'categories' => known_categories()]);
  }

  if ($method === 'POST' && $action === 'import') {
    $d = body();
    if (!is_array($d['rows'] ?? null)) json_out(['error'=>'Нет данных'], 422);
    $res = import_rows($d['rows'], (int)($d['payer_id'] ?? $member['id']));
    json_out(['ok'=>true, 'imported'=>count($res['ids']), 'linked'=>count($res['linked']) + count($res['linked_moves']), 'skipped'=>$res['skipped'], 'cash'=>count($res['moves'])]);
  }

  if ($method === 'POST' && $action === 'main') {
    $e = validate_entry(body());
    $id = insert_transaction((int)$member['id'], $e);
    trip_tag($id, $e);
    $roundup = apply_roundup($id, (int)$member['id'], $e);
    notify_big_expense($member, $e);
    // Limits never block a record; they only report where the family stands
    $limits = [];
    if ($e['kind'] === 'expense') {
      try { $limits = check_limit_alerts($e['category'], $e['date'], $member, (float)$e['amount']); } catch (Throwable $ex) { error_log((string)$ex); }
    }
    json_out(['ok'=>true, 'id'=>$id, 'category_group'=>$e['group'], 'limits'=>$limits, 'roundup'=>$roundup]);
  }

  json_out(['error'=>'Not found'], 404);
} catch (AuthException $e) {
  json_out(['error' => $e->getMessage()], 401);
} catch (PDOException $e) {
  error_log((string)$e);
  json_out(['error' => 'Ошибка базы данных'], 500);
} catch (RuntimeException $e) {
  json_out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
  error_log((string)$e);
  json_out(['error' => 'Внутренняя ошибка'], 500);
}
