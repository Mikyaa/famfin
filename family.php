<?php
declare(strict_types=1);
// Fourth wave: shared expenses split between the two of us, receipt photos on operations,
// morning digest, merchant → category rules, year-over-year comparison and unusual spending,
// round-up piggy bank, monthly contributions to goals, category report PDF, home-screen
// widget feed and trips.

/* ========== SCHEMA ========== */
function family_schema(PDO $pdo): void {
  $sqlite = is_sqlite();
  $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
  $ts = $sqlite ? 'TEXT DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
  $cs = $sqlite ? '' : ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $cols = [
    ['transactions', 'split', 'VARCHAR(8) NULL'],
    ['transactions', 'photo', 'VARCHAR(64) NULL'],
    ['transactions', 'trip_id', 'BIGINT NULL'],
    ['transfers', 'settle', 'INTEGER NOT NULL DEFAULT 0'],
    ['goals', 'monthly', 'DECIMAL(14,2) NULL'],
    ['goal_moves', 'source_tx', 'BIGINT NULL'],
  ];
  foreach ($cols as [$t, $c, $def]) if (!has_column($pdo, $t, $c)) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def");
  $pdo->exec("CREATE TABLE IF NOT EXISTS category_rules(pattern VARCHAR(80) PRIMARY KEY,category VARCHAR(80) NOT NULL,created_by BIGINT NULL,created_at $ts)$cs");
  $pdo->exec("CREATE TABLE IF NOT EXISTS trips(id $id,title VARCHAR(80) NOT NULL,currency VARCHAR(3) NOT NULL DEFAULT 'KZT',budget DECIMAL(14,2) NULL,start_on VARCHAR(10) NOT NULL,end_on VARCHAR(10) NULL,active INTEGER NOT NULL DEFAULT 1,created_at $ts)$cs");
}

function member_pair(): array {
  global $config;
  return array_slice(array_map('intval', $config['allowed_users']), 0, 2);
}
function other_member(int $id): ?int {
  foreach (member_pair() as $m) if ($m !== $id) return $m;
  return null;
}

/* ========== SPLIT EXPENSES ========== */
// "half": the other owes half; "other": paid for the other, they owe all of it.
// Settling up is a transfer marked settle=1 from the debtor to the payer.
const SPLIT_KINDS = ['half' => 'пополам', 'other' => 'за другого'];

function settlement_status(): ?array {
  [$a, $b] = member_pair() + [null, null];
  if (!$a || !$b) return null;
  $owed = [$a => 0.0, $b => 0.0];
  foreach (db()->query("SELECT telegram_id,split,SUM(amount) s FROM transactions WHERE kind='expense' AND split IS NOT NULL GROUP BY telegram_id,split") as $r) {
    $who = (int)$r['telegram_id'];
    if (!isset($owed[$who])) continue;
    $owed[$who] += $r['split'] === 'half' ? (float)$r['s'] / 2 : (float)$r['s'];
  }
  // Positive: b owes a
  $net = $owed[$a] - $owed[$b];
  foreach (db()->query('SELECT from_id,to_id,SUM(amount) s FROM transfers WHERE settle=1 GROUP BY from_id,to_id') as $r) {
    if ((int)$r['from_id'] === $b && (int)$r['to_id'] === $a) $net -= (float)$r['s'];
    if ((int)$r['from_id'] === $a && (int)$r['to_id'] === $b) $net += (float)$r['s'];
  }
  $net = round($net, 2);
  if (abs($net) < 1) return ['amount' => 0.0];
  $names = allowed_members_map();
  [$from, $to] = $net > 0 ? [$b, $a] : [$a, $b];
  return ['amount' => abs($net), 'from' => $from, 'to' => $to, 'from_name' => $names[$from] ?? '?', 'to_name' => $names[$to] ?? '?'];
}

function settle_up(int $actorId): array {
  $s = settlement_status();
  if (!$s || $s['amount'] < 1) throw new RuntimeException('Вы в расчёте');
  $id = add_transfer($s['from'], $s['to'], $s['amount'], 'Расчёт за общие траты', date('Y-m-d'), $actorId);
  db()->prepare('UPDATE transfers SET settle=1 WHERE id=?')->execute([$id]);
  return $s;
}

/* ========== RECEIPT PHOTOS ========== */
function attachments_dir(): string {
  global $config;
  $dir = $config['data_dir'] ?? (isset($config['db']['path']) ? dirname($config['db']['path']) : __DIR__ . '/storage');
  $dir .= '/attachments';
  if (!is_dir($dir)) mkdir($dir, 0700, true);
  return $dir;
}

// Re-encoded through GD: strips EXIF (location) and caps the size
function photo_save(int $txId, string $dataUrl): string {
  $tx = get_transaction($txId);
  if (!$tx) throw new RuntimeException('Операция не найдена');
  if (!preg_match('~^data:image/(jpeg|png|webp);base64,(.+)$~s', $dataUrl, $m)) throw new RuntimeException('Нужна фотография (JPEG, PNG или WebP)');
  $bin = base64_decode($m[2], true);
  if ($bin === false || strlen($bin) > 8 * 1024 * 1024) throw new RuntimeException('Фото слишком большое');
  $src = @imagecreatefromstring($bin);
  if (!$src) throw new RuntimeException('Не удалось прочитать фото');
  $w = imagesx($src); $h = imagesy($src);
  $k = min(1, 1600 / max($w, $h));
  $dst = imagecreatetruecolor(max(1, (int)round($w * $k)), max(1, (int)round($h * $k)));
  imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
  imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
  $name = $txId . '-' . bin2hex(random_bytes(6)) . '.jpg';
  imagejpeg($dst, attachments_dir() . '/' . $name, 82);
  imagedestroy($src); imagedestroy($dst);
  if ($tx['photo']) @unlink(attachments_dir() . '/' . basename($tx['photo']));
  db()->prepare('UPDATE transactions SET photo=? WHERE id=?')->execute([$name, $txId]);
  audit('update', 'transaction', $txId, 'Фото чека: ' . tx_summary($tx));
  return $name;
}

function photo_remove(int $txId): void {
  $tx = get_transaction($txId);
  if (!$tx || !$tx['photo']) return;
  @unlink(attachments_dir() . '/' . basename($tx['photo']));
  db()->prepare('UPDATE transactions SET photo=NULL WHERE id=?')->execute([$txId]);
}

function photo_file(int $txId): ?string {
  $tx = get_transaction($txId);
  if (!$tx || !$tx['photo']) {
    // A deleted operation keeps its photo while it sits in the trash
    $q = db()->prepare("SELECT payload FROM trash WHERE entity='transaction'");
    $q->execute();
    foreach ($q as $r) { $p = json_decode($r['payload'], true); if ((int)($p['id'] ?? 0) === $txId && !empty($p['photo'])) $tx = $p; }
  }
  if (!$tx || empty($tx['photo'])) return null;
  $f = attachments_dir() . '/' . basename((string)$tx['photo']);
  return is_file($f) ? $f : null;
}

/* ========== MORNING DIGEST ========== */
function digest_enabled(int $id): bool { return get_setting('digest:' . $id, '0') === '1'; }

function digest_text(int $userId): string {
  $days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
  $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  $now = new DateTimeImmutable('today');
  $lines = ['☀️ Доброе утро! Сегодня ' . $days[(int)$now->format('w')] . ', ' . $now->format('j') . ' ' . $months[(int)$now->format('n') - 1]];
  $y = $now->modify('-1 day')->format('Y-m-d');
  $s = range_summary($y, $now->format('Y-m-d'));
  $lines[] = $s['count'] ? 'Вчера потратили ' . fmt_money($s['expenses']) . " ({$s['count']} оп.)" : 'Вчера трат не было';
  $todo = [];
  $period = $now->format('Y-m');
  foreach (db()->query('SELECT * FROM recurring WHERE active=1') as $r) {
    if ($r['done_period'] === $period || $r['skipped_period'] === $period) continue;
    if (recurring_date_in($period, (int)$r['day']) === $now->format('Y-m-d')) $todo[] = '💳 ' . $r['category'] . ($r['note'] !== '' ? ' · ' . $r['note'] : '') . ' — ' . fmt_money((float)$r['amount']);
  }
  foreach (debts_list() as $d) if ($d['due_on'] === $now->format('Y-m-d')) $todo[] = '🤝 ' . ($d['direction'] === 'owed_to_me' ? 'Вернуть должны: ' : 'Вернуть: ') . $d['person'] . ' — ' . fmt_money($d['amount']);
  if ($todo) { $lines[] = ''; $lines[] = 'Сегодня:'; array_push($lines, ...$todo); }
  $plan = plan_brief();
  if ($plan['has']) $lines[] = "\n📋 План месяца: потрачено " . fmt_money($plan['spent']) . ' из ' . fmt_money($plan['planned']) . ($plan['over'] ? " · сверх плана: {$plan['over']}" : '');
  $warn = [];
  foreach (limits_status($userId)['items'] as $l) if ($l['state'] !== 'ok') $warn[] = ($l['state'] === 'over' ? '🔴 ' : '🟡 ') . $l['category'] . ': ' . ($l['remaining'] >= 0 ? 'осталось ' . fmt_money($l['remaining']) : 'превышен на ' . fmt_money(-$l['remaining']));
  if ($warn) { $lines[] = ''; $lines[] = 'Лимиты:'; array_push($lines, ...array_slice($warn, 0, 5)); }
  if ($t = trip_active()) { $ts = trip_summary($t); $lines[] = "\n✈️ {$t['title']}: потрачено " . fmt_cur($ts['spent_cur'], $t['currency']) . ($t['budget'] ? ' из ' . fmt_cur((float)$t['budget'], $t['currency']) : ''); }
  return implode("\n", $lines);
}

function send_morning_digests(): int {
  $n = 0;
  foreach (member_pair() as $id) {
    if (!digest_enabled($id) || get_setting('digest_sent:' . $id) === date('Y-m-d')) continue;
    send_member_message($id, digest_text($id));
    set_setting('digest_sent:' . $id, date('Y-m-d'));
    $n++;
  }
  return $n;
}

/* ========== MERCHANT RULES ========== */
// "MAGNUM CASH&CARRY 123" → "magnum cash carry"
function rule_key(string $note): string {
  $k = mb_strtolower($note);
  $k = preg_replace('/^(покупка|оплата|перевод)\s+(в|на)?\s*/u', '', $k);
  $k = preg_replace('/[^\p{L}\s]+/u', ' ', $k);
  return mb_substr(trim(preg_replace('/\s+/u', ' ', $k)), 0, 60);
}

function rules_list(): array {
  return array_map(fn($r) => ['pattern' => (string)$r['pattern'], 'category' => (string)$r['category']], db()->query('SELECT pattern,category FROM category_rules ORDER BY pattern')->fetchAll());
}

function rule_category(string $text): ?string {
  static $rules = null;
  if ($rules === null || !empty($GLOBALS['FAMFIN_RULES_DIRTY'])) { $rules = rules_list(); $GLOBALS['FAMFIN_RULES_DIRTY'] = false; }
  $k = ' ' . rule_key($text) . ' ';
  foreach ($rules as $r) if (mb_strlen($r['pattern']) >= 3 && str_contains($k, ' ' . $r['pattern'] . ' ')) return $r['category'];
  return null;
}

// Saves "always put <merchant> into <category>" and fixes past operations of that merchant
function rule_save(string $note, string $category, int $userId): array {
  $pattern = rule_key($note);
  $category = trim($category);
  if (mb_strlen($pattern) < 3) throw new RuntimeException('В комментарии нет названия магазина');
  if ($category === '' || mb_strlen($category) > 80) throw new RuntimeException('Неверная категория');
  $sql = is_sqlite() ? 'INSERT INTO category_rules(pattern,category,created_by) VALUES(?,?,?) ON CONFLICT(pattern) DO UPDATE SET category=excluded.category'
    : 'INSERT INTO category_rules(pattern,category,created_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE category=VALUES(category)';
  db()->prepare($sql)->execute([$pattern, $category, $userId]);
  $GLOBALS['FAMFIN_RULES_DIRTY'] = true;
  $group = category_group_of($category, stored_category_group($category));
  $fixed = 0;
  $upd = db()->prepare('UPDATE transactions SET category=?,category_group=? WHERE id=?');
  foreach (db()->query("SELECT id,note,category FROM transactions WHERE kind='expense' AND note<>''") as $r) {
    if ($r['category'] === $category) continue;
    if (str_contains(' ' . rule_key((string)$r['note']) . ' ', ' ' . $pattern . ' ')) { $upd->execute([$category, $group, $r['id']]); $fixed++; }
  }
  audit('settings', 'rule', null, "Правило: «{$pattern}» → {$category}" . ($fixed ? ", исправлено операций: $fixed" : ''));
  if ($fixed) mark_balance_changed();
  return ['pattern' => $pattern, 'fixed' => $fixed];
}

function rule_delete(string $pattern): void {
  db()->prepare('DELETE FROM category_rules WHERE pattern=?')->execute([$pattern]);
  $GLOBALS['FAMFIN_RULES_DIRTY'] = true;
}

/* ========== YEAR OVER YEAR AND UNUSUAL SPENDING ========== */
function report_compare(string $from, string $until): array {
  $f = new DateTimeImmutable($from);
  $u = new DateTimeImmutable($until);
  $isMonth = $f->format('d') === '01' && $u == $f->modify('first day of next month');
  $out = ['yoy' => null, 'unusual' => []];
  // Same period a year earlier, by category
  $cur = range_summary($from, $until);
  $prev = range_summary($f->modify('-1 year')->format('Y-m-d'), $u->modify('-1 year')->format('Y-m-d'));
  if ($prev['count'] > 0) {
    $rows = [];
    foreach (array_unique(array_merge(array_keys($cur['categories']), array_keys($prev['categories']))) as $c) {
      $a = $cur['categories'][$c]['total'] ?? 0.0; $b = $prev['categories'][$c]['total'] ?? 0.0;
      $rows[] = ['category' => (string)$c, 'now' => $a, 'before' => $b, 'diff' => round($a - $b, 2)];
    }
    usort($rows, fn($x, $y) => abs($y['diff']) <=> abs($x['diff']));
    $out['yoy'] = ['label' => $isMonth ? mb_strtolower(['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'][(int)$f->format('n') - 1]) . ' ' . $f->modify('-1 year')->format('Y') : 'тот же период год назад',
      'now' => $cur['expenses'], 'before' => $prev['expenses'], 'rows' => array_slice($rows, 0, 8)];
  }
  // A month far above its usual level (average of the three months before)
  if ($isMonth) {
    $avg = [];
    for ($i = 1; $i <= 3; $i++) {
      $m = range_summary($f->modify("-$i month")->format('Y-m-d'), $f->modify('-' . ($i - 1) . ' month')->format('Y-m-d'));
      foreach ($m['categories'] as $c => $v) $avg[$c] = ($avg[$c] ?? 0) + $v['total'] / 3;
    }
    $elapsed = $f->format('Y-m') === date('Y-m') ? max(1, (int)date('j')) / (int)$f->format('t') : 1;
    foreach ($cur['categories'] as $c => $v) {
      $base = $avg[$c] ?? 0;
      if ($base < 1000) continue;
      if ($v['total'] > $base * 1.5 && $v['total'] - $base >= 5000) {
        $out['unusual'][] = ['category' => (string)$c, 'now' => $v['total'], 'usual' => round($base), 'times' => round($v['total'] / $base, 1), 'early' => $elapsed < 0.9];
      }
    }
    usort($out['unusual'], fn($x, $y) => $y['now'] - $y['usual'] <=> $x['now'] - $x['usual']);
  }
  return $out;
}

/* ========== ROUND-UP PIGGY BANK ========== */
function roundup_settings(): array {
  $goal = (int)get_setting('roundup_goal', '0');
  $step = (int)get_setting('roundup_step', '1000');
  if ($goal && !array_filter(goals_list(), fn($g) => $g['id'] === $goal)) $goal = 0;
  return ['goal' => $goal, 'step' => in_array($step, [100, 500, 1000, 5000], true) ? $step : 1000];
}

// 4 300 ₸ with step 1 000 → 700 ₸ goes into the goal, linked to the operation
function apply_roundup(int $txId, int $memberId, array $e): float {
  if (($e['kind'] ?? '') !== 'expense') return 0.0;
  $s = roundup_settings();
  if (!$s['goal']) return 0.0;
  $amount = (float)$e['amount'];
  $diff = round(ceil($amount / $s['step']) * $s['step'] - $amount, 2);
  if ($diff < 1) return 0.0;
  db()->prepare('INSERT INTO goal_moves(goal_id,telegram_id,amount,occurred_on,source_tx) VALUES(?,?,?,?,?)')->execute([$s['goal'], $memberId, $diff, $e['date'], $txId]);
  mark_balance_changed();
  return $diff;
}

/* ========== GOAL CONTRIBUTIONS ========== */
function goal_month_contrib(): array {
  $q = db()->prepare('SELECT goal_id,SUM(amount) s FROM goal_moves WHERE occurred_on>=? GROUP BY goal_id');
  $q->execute([date('Y-m-01')]);
  return array_map('floatval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
}

// On the 1st and the 20th: remind about the planned monthly contribution if it is not in yet
function send_goal_reminders(): int {
  $day = (int)date('j');
  if ($day !== 1 && $day !== 20) return 0;
  $n = 0;
  foreach (goals_list() as $g) {
    if (!$g['monthly_plan'] || $g['left'] <= 0) continue;
    $missing = round(min($g['left'], $g['monthly_plan'] - $g['this_month']));
    if ($missing < 1 || get_setting("goal_remind:{$g['id']}:" . date('Y-m-d')) !== null) continue;
    foreach (member_pair() as $uid) {
      $markup = bot_keyboard($uid, ['type' => 'goal_pay', 'id' => $g['id'], 'amount' => $missing, 'opts' => ['pay', 'skip']], ['💰 Отложить ' . fmt_money($missing), 'Позже']);
      send_member_message($uid, "🎯 Цель «{$g['title']}»: плановый взнос " . fmt_money($g['monthly_plan']) . ' в месяц, в этом месяце отложено ' . fmt_money($g['this_month']) . '.', $markup);
    }
    set_setting("goal_remind:{$g['id']}:" . date('Y-m-d'), '1');
    $n++;
  }
  return $n;
}

/* ========== CATEGORY REPORT (PDF for taxes, warranties, big purchases) ========== */
function category_report_rows(string $from, string $until, array $cats): array {
  $cats = array_values(array_filter(array_map('strval', $cats), fn($c) => $c !== ''));
  if (!$cats) throw new RuntimeException('Выберите хотя бы одну категорию');
  $in = implode(',', array_fill(0, count($cats), '?'));
  $q = db()->prepare("SELECT t.occurred_on,t.amount,t.category,t.note,t.orig_amount,t.orig_currency,t.photo,m.display_name FROM transactions t JOIN members m ON m.telegram_id=t.telegram_id
    WHERE t.kind='expense' AND t.occurred_on>=? AND t.occurred_on<? AND t.category IN ($in) ORDER BY t.category,t.occurred_on,t.id LIMIT 5000");
  $q->execute(array_merge([$from, $until], $cats));
  $by = [];
  foreach ($q as $r) $by[$r['category']][] = $r;
  return $by;
}

function export_category_pdf(string $from, string $until, array $cats): string {
  require_once __DIR__ . '/report_image.php';
  $by = category_report_rows($from, $until, $cats);
  $W = 1240; $H = 1754; $P = 80; $rowH = 46;
  $fmt = fn($iso) => (new DateTimeImmutable($iso))->format('d.m.Y');
  $period = $fmt($from) . ' — ' . $fmt((new DateTimeImmutable($until))->modify('-1 day')->format('Y-m-d'));
  $total = 0.0; foreach ($by as $rows) $total += array_sum(array_column($rows, 'amount'));
  // Lay out lines first, then cut them into pages
  $lines = [];
  foreach ($by as $cat => $rows) {
    $lines[] = ['head', $cat, array_sum(array_column($rows, 'amount')), count($rows)];
    foreach ($rows as $r) $lines[] = ['row', $r];
  }
  if (!$lines) $lines[] = ['empty'];
  $pages = []; $page = []; $y = 330;
  foreach ($lines as $l) {
    $h = $l[0] === 'head' ? 90 : $rowH;
    if ($y + $h > $H - 120) { $pages[] = $page; $page = []; $y = 160; }
    $page[] = [$l, $y]; $y += $h;
  }
  $pages[] = $page;
  $pdf = new Imagick();
  foreach ($pages as $pi => $items) {
    $c = new WrCanvas($W, $H, 1, __DIR__ . '/fonts');
    $c->rrect(0, 0, $W, $H, 0, '#FFFFFF');
    if ($pi === 0) {
      $c->text('Семейный бюджет — расходы по категориям', $P, 110, 38, '#141B29', 'SemiBold');
      $c->text($period . ' · ' . implode(', ', array_keys($by) ?: $cats), $P, 160, 22, '#6E7584');
      $c->text('Итого: ' . wr_money($total), $P, 240, 34, '#141B29', 'SemiBold');
    } else $c->text('Семейный бюджет · ' . $period, $P, 90, 22, '#6E7584');
    foreach ($items as [$l, $y]) {
      if ($l[0] === 'empty') { $c->text('За период таких расходов нет', $P, $y + 30, 24, '#6E7584'); continue; }
      if ($l[0] === 'head') {
        $c->rrect($P, $y + 20, $W - 2 * $P, 56, 10, '#F1F3F7');
        $c->text($l[1] . ' · ' . $l[3] . ' оп.', $P + 20, $y + 58, 24, '#141B29', 'SemiBold');
        $c->text(wr_money($l[2]), $W - $P - 20, $y + 58, 24, '#141B29', 'SemiBold', 'right');
        continue;
      }
      $r = $l[1];
      $c->text($fmt($r['occurred_on']), $P + 20, $y + 30, 20, '#4F5666');
      $note = trim((string)$r['note']) !== '' ? $r['note'] : '—';
      if ($r['orig_currency']) $note .= ' (' . fmt_cur((float)$r['orig_amount'], $r['orig_currency']) . ')';
      if ($r['photo']) $note .= ' · есть фото чека';
      $c->text($c->fit($note, 640, 20), $P + 190, $y + 30, 20, '#141B29');
      $c->text($c->fit((string)$r['display_name'], 150, 20), $P + 850, $y + 30, 20, '#6E7584');
      $c->text(wr_money((float)$r['amount']), $W - $P - 20, $y + 30, 20, '#141B29', 'SemiBold', 'right');
      $c->rrect($P + 20, $y + $rowH - 4, $W - 2 * $P - 40, 1, 0, '#E6E8EE');
    }
    $c->text('Стр. ' . ($pi + 1) . ' из ' . count($pages) . ' · сформировано ' . date('d.m.Y'), $W / 2, $H - 60, 18, '#A2A8B4', 'Regular', 'center');
    $png = tempnam(sys_get_temp_dir(), 'cat') . '.png';
    $c->png($png);
    $pdf->readImage($png);
    @unlink($png);
  }
  foreach ($pdf as $img) { $img->setImageFormat('pdf'); $img->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH); $img->setImageResolution(150, 150); }
  $file = tempnam(sys_get_temp_dir(), 'pdf');
  $pdf->writeImages($file, true);
  $pdf->clear();
  return $file;
}

/* ========== HOME-SCREEN WIDGET FEED ========== */
function widget_feed(): array {
  $b = balances_until((new DateTimeImmutable('tomorrow'))->format('Y-m-d'));
  [$f, $u] = normalize_period(null, null, 'month');
  $m = range_summary($f, $u);
  $plan = plan_brief();
  $months = ['январь','февраль','март','апрель','май','июнь','июль','август','сентябрь','октябрь','ноябрь','декабрь'];
  return ['balance' => round($b['shared']['balance']), 'spent_month' => round($m['expenses']), 'month' => $months[(int)date('n') - 1],
    'plan_left' => $plan['has'] ? round($plan['planned'] - $plan['spent']) : null, 'updated' => date('H:i')];
}

function widget_text(): string {
  $w = widget_feed();
  return 'Остаток: ' . fmt_money($w['balance']) . "\nЗа {$w['month']}: " . fmt_money($w['spent_month']) . ($w['plan_left'] !== null ? "\nПо плану осталось: " . fmt_money($w['plan_left']) : '');
}

/* ========== TRIPS ========== */
function trip_active(): ?array {
  $r = db()->query('SELECT * FROM trips WHERE active=1 ORDER BY id DESC LIMIT 1')->fetch();
  return $r ?: null;
}

function trips_list(): array {
  $out = [];
  foreach (db()->query('SELECT * FROM trips ORDER BY active DESC,id DESC LIMIT 30') as $t) $out[] = $t + trip_summary($t);
  return $out;
}

function trip_summary(array $t): array {
  $q = db()->prepare("SELECT category,amount,orig_amount,orig_currency,occurred_on FROM transactions WHERE trip_id=? AND kind='expense'");
  $q->execute([$t['id']]);
  $kzt = 0.0; $curSum = 0.0; $cats = []; $n = 0;
  $rate = $t['currency'] === 'KZT' ? 1.0 : (fx_rates()[$t['currency']] ?? 0.0);
  foreach ($q as $r) {
    $n++;
    $kzt += (float)$r['amount'];
    $curSum += $r['orig_currency'] === $t['currency'] ? (float)$r['orig_amount'] : ($rate ? (float)$r['amount'] / $rate : 0);
    $cats[$r['category']] = ($cats[$r['category']] ?? 0) + (float)$r['amount'];
  }
  arsort($cats);
  $end = $t['end_on'] ?: date('Y-m-d');
  $days = max(1, (int)(new DateTimeImmutable($t['start_on']))->diff(new DateTimeImmutable($end))->days + 1);
  return ['spent_kzt' => round($kzt), 'spent_cur' => round($curSum, 2), 'count' => $n, 'days' => $days, 'per_day_kzt' => round($kzt / $days),
    'categories' => array_map(fn($c, $v) => ['category' => (string)$c, 'total' => round($v)], array_keys($cats), $cats)];
}

function trip_save(array $d): int {
  $title = trim((string)($d['title'] ?? ''));
  $cur = strtoupper((string)($d['currency'] ?? 'KZT'));
  $budget = trim((string)($d['budget'] ?? '')) === '' ? null : parse_amount($d['budget']);
  $start = (string)($d['start_on'] ?? date('Y-m-d'));
  if ($title === '' || mb_strlen($title) > 80) throw new RuntimeException('Назовите поездку');
  if ($cur !== 'KZT' && !isset(FX_CODES[$cur])) throw new RuntimeException('Неизвестная валюта');
  if (!valid_date($start, true)) throw new RuntimeException('Неверная дата начала');
  $id = (int)($d['id'] ?? 0);
  if ($id) { db()->prepare('UPDATE trips SET title=?,currency=?,budget=?,start_on=? WHERE id=?')->execute([$title, $cur, $budget, $start, $id]); return $id; }
  // One trip at a time
  db()->prepare('UPDATE trips SET active=0,end_on=COALESCE(end_on,?) WHERE active=1')->execute([date('Y-m-d')]);
  db()->prepare('INSERT INTO trips(title,currency,budget,start_on) VALUES(?,?,?,?)')->execute([$title, $cur, $budget, $start]);
  $id = (int)db()->lastInsertId();
  // A trip started on an earlier day picks up what was recorded since then
  if ($start < date('Y-m-d')) db()->prepare("UPDATE transactions SET trip_id=? WHERE kind='expense' AND occurred_on>=? AND trip_id IS NULL AND recurring_id IS NULL AND import_key IS NULL")->execute([$id, $start]);
  audit('create', 'trip', $id, 'Поездка «' . $title . '»');
  return $id;
}

function trip_end(int $id): void {
  db()->prepare('UPDATE trips SET active=0,end_on=? WHERE id=? AND active=1')->execute([date('Y-m-d'), $id]);
}

function trip_delete(int $id): void {
  db()->prepare('UPDATE transactions SET trip_id=NULL WHERE trip_id=?')->execute([$id]);
  db()->prepare('DELETE FROM trips WHERE id=?')->execute([$id]);
}

// Tag a new expense with the running trip
function trip_tag(int $txId, array $e): void {
  if (($e['kind'] ?? '') !== 'expense') return;
  $t = trip_active();
  if ($t && $e['date'] >= $t['start_on']) db()->prepare('UPDATE transactions SET trip_id=? WHERE id=?')->execute([$t['id'], $txId]);
}
