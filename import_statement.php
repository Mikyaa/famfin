<?php
declare(strict_types=1);
// Manual statement import from the console.
//   php import_statement.php statement.pdf                 # show what would be imported
//   php import_statement.php statement.pdf --apply         # import new purchases
//   php import_statement.php statement.pdf --apply --all   # also transfers, top-ups, withdrawals
//   --payer=TELEGRAM_ID  whose statement it is (default: first family member)
//   --from=YYYY-MM-DD    only operations from this date (e.g. the start of budgeting)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$opts = getopt('', ['apply', 'all', 'payer:', 'from:'], $rest);
$file = $argv[$rest] ?? null;
if (!$file || !is_file($file)) { fwrite(STDERR, "Usage: php import_statement.php statement.pdf [--apply] [--all] [--payer=ID]\n"); exit(1); }
$payer = (int)($opts['payer'] ?? $config['allowed_users'][0]);
if (!is_member_id($payer)) { fwrite(STDERR, "Unknown member $payer\n"); exit(1); }

$text = file_get_contents($file, false, null, 0, 5) === '%PDF-' ? pdf_to_text($file) : (string)file_get_contents($file);
$rows = parse_bank_statement($text, $payer);
if (!$rows) { fwrite(STDERR, "No operations found. Is this a Kaspi Gold statement?\n"); exit(2); }

fwrite(STDOUT, statement_summary($rows, $payer, basename($file)) . "\n\n");
foreach (statement_list_messages($rows) as $chunk) fwrite(STDOUT, $chunk);

if (!isset($opts['apply'])) { fwrite(STDOUT, "\nDry run. Add --apply to import.\n"); exit(0); }
$entries = bot_statement_entries($rows, isset($opts['all']), (string)($opts['from'] ?? '0000-00-00'));
if (!$entries) { fwrite(STDOUT, "\nNothing new to import.\n"); exit(0); }
$ids = import_rows($entries, $payer);
fwrite(STDOUT, "\nImported " . count($ids) . " operations for " . user_label($payer) . " (ids " . min($ids) . "–" . max($ids) . ")\n");
