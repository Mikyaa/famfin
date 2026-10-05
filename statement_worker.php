<?php
declare(strict_types=1);
// Processes statement files sent to the bot (download, PDF → text, summary with buttons).
// Cron (every minute): php statement_worker.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$jobs = db()->query("SELECT * FROM statement_jobs WHERE status='new' ORDER BY id LIMIT 5")->fetchAll();
foreach ($jobs as $job) {
  // Claim the job first so an overlapping run cannot process it twice
  $claim = db()->prepare("UPDATE statement_jobs SET status='working' WHERE id=? AND status='new'");
  $claim->execute([$job['id']]);
  if ($claim->rowCount() === 0) continue;
  if (($job['kind'] ?? 'statement') === 'receipt') process_receipt_job($job); else process_statement_job($job);
  fwrite(STDOUT, date('c') . " Statement job {$job['id']} ({$job['file_name']}) processed\n");
}
