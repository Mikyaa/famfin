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
    $recent = db()->query("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id ORDER BY t.occurred_on DESC,t.id DESC LIMIT 10")->fetchAll();
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
      'forecast' => month_forecast(),
      'recurring' => recurring_list(),
      'members' => array_map(fn($id, $name) => ['id' => $id, 'name' => $name], array_keys(allowed_members_map()), allowed_members_map()),
      'limits' => limits_status((int)$member['id']),
      'category_groups' => category_groups_all(),
    ]);
  }

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
    $q = db()->prepare("SELECT t.id,t.kind,t.amount,t.category,COALESCE(t.category_group,'') cg,t.note,t.occurred_on,t.telegram_id,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id WHERE t.occurred_on>=? AND t.occurred_on<? ORDER BY t.occurred_on DESC,t.id DESC LIMIT $limit OFFSET $offset");
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
    json_out(['ok'=>true, 'imported'=>count($res['ids']), 'linked'=>count($res['linked']), 'skipped'=>$res['skipped']]);
  }

  if ($method === 'POST' && $action === 'main') {
    $e = validate_entry(body());
    $id = insert_transaction((int)$member['id'], $e);
    // Limits never block a record; they only report where the family stands
    $limits = [];
    if ($e['kind'] === 'expense') {
      try { $limits = check_limit_alerts($e['category'], $e['date'], $member, (float)$e['amount']); } catch (Throwable $ex) { error_log((string)$ex); }
    }
    json_out(['ok'=>true, 'id'=>$id, 'category_group'=>$e['group'], 'limits'=>$limits]);
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
