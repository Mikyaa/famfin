<?php
declare(strict_types=1);
// Weekly family report: data for one Monday–Sunday week, a PNG "analysis card" drawn
// with GD, and delivery to a Telegram chat/channel. Used by weekly_report.php (CLI).

const WR_DAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const WR_MONTHS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

function wr_money(float $v, bool $sign = false): string {
  $s = number_format(abs($v), 0, ',', ' ') . ' ₸';
  if ($v < 0) return '−' . $s;
  return ($sign && $v > 0 ? '+' : '') . $s;
}

function wr_day(string $iso, bool $year = false): string {
  $d = new DateTimeImmutable($iso);
  return (int)$d->format('j') . ' ' . WR_MONTHS[(int)$d->format('n') - 1] . ($year ? ' ' . $d->format('Y') : '');
}

// The last full week before $today (run on Monday → the week that just ended)
function weekly_report_data(?string $anyDayOfWeek = null): array {
  $ref = $anyDayOfWeek ? new DateTimeImmutable($anyDayOfWeek) : (new DateTimeImmutable('today'))->modify('-7 days');
  $from = $ref->modify('monday this week');
  $until = $from->modify('+7 days');
  $s = range_summary($from->format('Y-m-d'), $until->format('Y-m-d'));
  $daily = daily_series($from->format('Y-m-d'), $until->format('Y-m-d'));
  $balances = balances_until((new DateTimeImmutable('tomorrow'))->format('Y-m-d'));
  $limits = array_values(array_filter(limits_status(0)['items'], fn($i) => $i['scope'] === 'family'));
  $cats = [];
  foreach ($s['categories'] as $name => $c) $cats[] = ['name' => (string)$name, 'total' => $c['total'], 'group' => $c['group']];
  return [
    'from' => $from->format('Y-m-d'),
    'to' => $until->modify('-1 day')->format('Y-m-d'),
    'expenses' => $s['expenses'],
    'topups' => $s['topups'],
    'count' => $s['count'],
    'fixed' => $s['fixed'],
    'variable' => $s['variable'],
    'members' => $s['members'],
    'categories' => $cats,
    'daily' => $daily,
    'balances' => $balances,
    'limits' => $limits,
  ];
}

function weekly_report_caption(array $d): string {
  $lines = ['📊 Итоги недели ' . wr_day($d['from']) . ' — ' . wr_day($d['to'], true), ''];
  $lines[] = 'Потрачено: ' . wr_money($d['expenses']) . ($d['count'] ? " ({$d['count']} оп.)" : '');
  if ($d['topups'] > 0) $lines[] = 'Пополнения: ' . wr_money($d['topups']);
  $lines[] = 'Общий остаток: ' . wr_money($d['balances']['shared']['balance']);
  $per = [];
  foreach ($d['members'] as $m) $per[] = $m['name'] . ' ' . wr_money($m['expenses']);
  if ($d['expenses'] > 0) $lines[] = 'Кто сколько: ' . implode(' · ', $per);
  if ($d['categories']) {
    $top = array_slice($d['categories'], 0, 3);
    $lines[] = 'Больше всего: ' . implode(', ', array_map(fn($c) => $c['name'] . ' ' . wr_money($c['total']), $top));
  }
  $over = array_filter($d['limits'], fn($l) => $l['state'] !== 'ok' && $l['period'] === 'month');
  if ($over) {
    $lines[] = '';
    foreach ($over as $l) {
      $lines[] = ($l['state'] === 'over' ? '🔴 ' : '🟡 ') . 'Лимит «' . $l['label'] . '»: ' . ($l['remaining'] >= 0 ? 'осталось ' . wr_money($l['remaining']) : 'превышен на ' . wr_money(-$l['remaining']));
    }
  }
  return mb_substr(implode("\n", $lines), 0, 1000);
}

/* ---------- drawing ---------- */
final class WrCanvas {
  public $im;
  private array $colors = [];
  public function __construct(public int $w, public int $h, public int $k, private string $fontDir) {
    $this->im = imagecreatetruecolor($w * $k, $h * $k);
    imagealphablending($this->im, true);
    imagefill($this->im, 0, 0, $this->c('#F5F6F8'));
  }
  public function c(string $hex, int $alpha = 0): int {
    $key = "$hex/$alpha";
    if (!isset($this->colors[$key])) {
      [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
      $this->colors[$key] = imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
    }
    return $this->colors[$key];
  }
  private function font(string $weight): string { return $this->fontDir . '/Inter-' . $weight . '.ttf'; }
  public function rrect(float $x, float $y, float $w, float $h, float $r, string $hex, int $alpha = 0): void {
    $k = $this->k; [$x, $y, $w, $h, $r] = [$x * $k, $y * $k, $w * $k, $h * $k, min($r, $h / 2, $w / 2) * $k];
    $col = $this->c($hex, $alpha);
    if ($w <= 0 || $h <= 0) return;
    imagefilledrectangle($this->im, (int)($x + $r), (int)$y, (int)($x + $w - $r), (int)($y + $h), $col);
    imagefilledrectangle($this->im, (int)$x, (int)($y + $r), (int)($x + $w), (int)($y + $h - $r), $col);
    foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
      imagefilledellipse($this->im, (int)$cx, (int)$cy, (int)(2 * $r), (int)(2 * $r), $col);
    }
  }
  public function textWidth(string $s, float $size, string $weight = 'Regular'): float {
    $b = imagettfbbox($size * $this->k * 0.75, 0, $this->font($weight), $s);
    return ($b[2] - $b[0]) / $this->k;
  }
  // $y is the baseline; align: left|right|center
  public function text(string $s, float $x, float $y, float $size, string $hex, string $weight = 'Regular', string $align = 'left'): void {
    if ($align !== 'left') { $w = $this->textWidth($s, $size, $weight); $x -= $align === 'right' ? $w : $w / 2; }
    imagettftext($this->im, $size * $this->k * 0.75, 0, (int)($x * $this->k), (int)($y * $this->k), $this->c($hex), $this->font($weight), $s);
  }
  public function fit(string $s, float $maxW, float $size, string $weight = 'Regular'): string {
    if ($this->textWidth($s, $size, $weight) <= $maxW) return $s;
    while (mb_strlen($s) > 1 && $this->textWidth($s . '…', $size, $weight) > $maxW) $s = mb_substr($s, 0, -1);
    return rtrim($s) . '…';
  }
  // Slanted band filled with the logo gradient
  public function swoosh(float $x0, float $x1, float $yTop0, float $yTop1, float $thick, int $alpha = 0): void {
    $k = $this->k;
    for ($x = (int)($x0 * $k); $x <= (int)($x1 * $k); $x++) {
      $t = ($x / $k - $x0) / max(1, $x1 - $x0);
      $r = (int)(0x21 + (0x36 - 0x21) * $t); $g = (int)(0x34 + (0x88 - 0x34) * $t); $b = (int)(0xC0 + (0xFC - 0xC0) * $t);
      $col = imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
      $yt = ($yTop0 + ($yTop1 - $yTop0) * $t) * $k;
      imageline($this->im, $x, (int)$yt, $x, (int)($yt + $thick * $k), $col);
    }
  }
  public function image(string $path, float $x, float $y, float $size): void {
    if (!is_file($path)) return;
    $src = imagecreatefrompng($path);
    imagecopyresampled($this->im, $src, (int)($x * $this->k), (int)($y * $this->k), 0, 0, (int)($size * $this->k), (int)($size * $this->k), imagesx($src), imagesy($src));
    imagedestroy($src);
  }
  public function png(string $file): void {
    $out = imagecreatetruecolor($this->w, $this->h);
    imagecopyresampled($out, $this->im, 0, 0, 0, 0, $this->w, $this->h, $this->w * $this->k, $this->h * $this->k);
    imagepng($out, $file, 6);
    imagedestroy($out);
  }
}

function render_weekly_report_png(array $d, string $file): void {
  $W = 1080; $P = 48; $cardW = $W - 2 * $P;
  $cats = array_slice($d['categories'], 0, 5);
  $limits = array_slice($d['limits'], 0, 4);
  $members = $d['members'];
  // Layout heights
  $hHero = 470; $hBal = 230; $hDays = 400;
  $hCats = $cats ? 110 + count($cats) * 92 : 0;
  $hPeople = 110 + count($members) * 92;
  $hLim = $limits ? 110 + count($limits) * 92 : 0;
  $gap = 28;
  $H = $P + $hHero + $gap + $hBal + $gap + $hDays + $gap + ($hCats ? $hCats + $gap : 0) + $hPeople + $gap + ($hLim ? $hLim + $gap : 0) + 70;
  $c = new WrCanvas($W, $H, 2, __DIR__ . '/fonts');
  $ink = '#141B29'; $muted = '#6E7584'; $line = '#E1E4EA'; $track = '#EEF0F4';
  $y = $P;

  // Hero (logo plate)
  $c->rrect($P, $y, $cardW, $hHero, 40, $ink);
  $c->swoosh($P + 640, $P + $cardW - 2, $y + 236, $y + 150, 54, 85);
  $c->image(__DIR__ . '/img/logo-mark.png', $P + 44, $y + 44, 76);
  $c->text('Семейный бюджет', $P + 140, $y + 78, 30, '#FFFFFF', 'SemiBold');
  $c->text('Итоги недели · ' . wr_day($d['from']) . ' — ' . wr_day($d['to'], true), $P + 140, $y + 114, 24, '#A9B0BE');
  $c->text('Потрачено за неделю', $P + 44, $y + 210, 26, '#A9B0BE');
  $c->text(wr_money($d['expenses']), $P + 44, $y + 300, 84, '#FFFFFF', 'SemiBold');
  $days = max(1, count($d['daily']));
  $stats = [['Пополнения', wr_money($d['topups'], true)], ['В среднем в день', wr_money($d['expenses'] / $days)], ['Операций', (string)$d['count']]];
  $sx = $P + 44;
  foreach ($stats as [$label, $val]) {
    $c->text($label, $sx, $y + 380, 22, '#A9B0BE');
    $c->text($val, $sx, $y + 420, 30, '#FFFFFF', 'SemiBold');
    $sx += 300;
  }
  $y += $hHero + $gap;

  // Balance
  $b = $d['balances'];
  $c->rrect($P, $y, $cardW, $hBal, 36, '#FFFFFF');
  $c->text('Общий остаток на сегодня', $P + 40, $y + 62, 26, $muted);
  $bal = $b['shared']['balance'];
  $c->text(wr_money($bal), $P + 40, $y + 140, 56, $bal < 0 ? '#CF2F3F' : '#0A8F5A', 'SemiBold');
  $mx = $P + 40;
  $parts = [];
  foreach ($b['members'] as $m) $parts[] = $m['name'] . ': ' . wr_money($m['balance']);
  $c->text(implode('   ·   ', $parts), $mx, $y + 192, 24, $muted);
  $y += $hBal + $gap;

  // Daily bars
  $c->rrect($P, $y, $cardW, $hDays, 36, '#FFFFFF');
  $c->text('По дням', $P + 40, $y + 62, 30, $ink, 'SemiBold');
  $max = max(1, ...array_map(fn($x) => $x['expense'], $d['daily']));
  $chartTop = $y + 120; $chartH = 190; $colW = ($cardW - 80) / 7;
  foreach ($d['daily'] as $i => $day) {
    $cx = $P + 40 + $i * $colW;
    $bh = $day['expense'] > 0 ? max(8, $day['expense'] / $max * $chartH) : 6;
    $c->rrect($cx + 22, $chartTop, $colW - 44, $chartH, 14, $track);
    $isMax = $day['expense'] == $max && $day['expense'] > 0;
    $c->rrect($cx + 22, $chartTop + $chartH - $bh, $colW - 44, $bh, 14, $isMax ? '#2134C0' : '#2A62EC');
    if ($day['expense'] > 0) {
      $label = $day['expense'] >= 1000 ? round($day['expense'] / 1000, $day['expense'] >= 100000 ? 0 : 1) . 'k' : (string)round($day['expense']);
      $c->text(str_replace('.', ',', $label), $cx + $colW / 2, $chartTop + $chartH - $bh - 12, 20, $ink, 'SemiBold', 'center');
    }
    $c->text(WR_DAYS[$i], $cx + $colW / 2, $chartTop + $chartH + 46, 22, $muted, 'Regular', 'center');
  }
  $y += $hDays + $gap;

  // Categories
  $row = function(float $y, string $name, string $value, float $share, string $barHex, string $dotHex) use ($c, $P, $cardW, $ink, $track) {
    $c->rrect($P + 40, $y + 6, 14, 14, 7, $dotHex);
    $c->text($c->fit($name, $cardW - 420, 26, 'Regular'), $P + 66, $y + 22, 26, $ink);
    $c->text($value, $P + $cardW - 40, $y + 22, 26, $ink, 'SemiBold', 'right');
    $c->rrect($P + 40, $y + 42, $cardW - 80, 12, 6, $track);
    $c->rrect($P + 40, $y + 42, max(12, ($cardW - 80) * min(1, $share)), 12, 6, $barHex);
  };
  if ($cats) {
    $c->rrect($P, $y, $cardW, $hCats, 36, '#FFFFFF');
    $c->text('Больше всего потратили на', $P + 40, $y + 62, 30, $ink, 'SemiBold');
    $topMax = max(1, $cats[0]['total']);
    foreach ($cats as $i => $cat) {
      $pct = $d['expenses'] > 0 ? round($cat['total'] / $d['expenses'] * 100) : 0;
      $color = $cat['group'] === 'fixed' ? '#2A62EC' : '#E08B3A';
      $row($y + 104 + $i * 92, $cat['name'], wr_money($cat['total']) . "  ·  $pct%", $cat['total'] / $topMax, $color, $color);
    }
    $y += $hCats + $gap;
  }

  // People
  $c->rrect($P, $y, $cardW, $hPeople, 36, '#FFFFFF');
  $c->text('Кто сколько потратил', $P + 40, $y + 62, 30, $ink, 'SemiBold');
  $pMax = max(1, ...array_map(fn($m) => $m['expenses'], $members));
  $palette = ['#2A62EC', '#0A8F5A', '#E08B3A', '#7B61FF'];
  foreach ($members as $i => $m) {
    $row($y + 104 + $i * 92, $m['name'], wr_money($m['expenses']), $m['expenses'] / $pMax, $palette[$i % 4], $palette[$i % 4]);
  }
  $y += $hPeople + $gap;

  // Family limits
  if ($limits) {
    $c->rrect($P, $y, $cardW, $hLim, 36, '#FFFFFF');
    $c->text('Лимиты', $P + 40, $y + 62, 30, $ink, 'SemiBold');
    foreach ($limits as $i => $l) {
      $color = ['ok' => '#0A8F5A', 'warn' => '#E3A23B', 'over' => '#CF2F3F'][$l['state']];
      $tail = $l['remaining'] >= 0 ? 'осталось ' . wr_money($l['remaining']) : 'превышен на ' . wr_money(-$l['remaining']);
      $name = $l['label'] . ($l['period'] === 'week' ? ' (нед.)' : ' (мес.)');
      $row($y + 104 + $i * 92, $name, $tail, $l['limit'] > 0 ? $l['spent'] / $l['limit'] : 1, $color, $color);
    }
    $y += $hLim + $gap;
  }

  $c->text('Семейный бюджет · ' . wr_day(date('Y-m-d'), true), $W / 2, $H - 36, 22, '#A2A8B4', 'Regular', 'center');
  $c->png($file);
}

function telegram_send_photo(string $chatId, string $file, string $caption): array {
  global $config;
  if (empty($config['bot_token'])) throw new RuntimeException('Настройте токен бота в config.php');
  $ch = curl_init('https://api.telegram.org/bot' . $config['bot_token'] . '/sendPhoto');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'caption' => $caption, 'photo' => new CURLFile($file, 'image/png', 'weekly.png')],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException($err); }
  curl_close($ch);
  return json_decode($raw, true) ?: [];
}
