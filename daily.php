<?php
declare(strict_types=1);
// Daily morning job: recurring-payment reminders, and on the 1st the summary of the
// previous month to both members' private chats with the bot.
// Cron: php daily.php · manual month summary: php daily.php --month=YYYY-MM-DD [--force]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$opts = getopt('', ['month:', 'force']);
fwrite(STDOUT, date('c') . ' Recurring reminders sent: ' . send_recurring_reminders() . "\n");

if (date('j') === '1' || isset($opts['month'])) {
  $any = $opts['month'] ?? (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
  $key = (new DateTimeImmutable($any))->format('Y-m');
  if (!isset($opts['force']) && get_setting('monthly_summary_sent') === $key) exit(0);
  notify_family(monthly_report_text($any));
  set_setting('monthly_summary_sent', $key);
  fwrite(STDOUT, date('c') . " Monthly summary for $key sent\n");
}
