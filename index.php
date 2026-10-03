<?php
require __DIR__.'/lib.php';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://telegram.org; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
// Set bot_username in config.php to skip the getMe round-trip on every page load
$botUser = $config['bot_username'] ?? null;
if (!$botUser && !empty($config['bot_token'])) {
  try { $r = telegram('getMe', [], 5); if ($r['ok'] ?? false) $botUser = $r['result']['username'] ?? null; } catch (Throwable) {}
}
$localDev = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) && ($config['local_test_mode'] ?? false);
$authUrl = rtrim($config['app_url'] ?? '', '/') . '/auth.php';
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#F5F6F8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Бюджет">
<link rel="icon" href="favicon.ico" sizes="48x48">
<link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32.png">
<link rel="apple-touch-icon" href="img/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="description" content="Семейный бюджет — учёт расходов и доходов для всей семьи">
<title>Семейный бюджет</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="app.css">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>

<div class="login" id="login" hidden>
  <section class="login-card" id="otpCard">
    <img class="login-logo" src="img/logo-mark.png" alt="" width="64" height="64">
    <div class="otp-eyebrow"><span class="pulse"></span> ВХОД ПО КОДУ</div>
    <h1>Семейный бюджет</h1>
    <p class="subtitle" id="otpSubtitle">Отправьте <b>/login</b> боту <?php if ($botUser): ?><a href="https://t.me/<?= htmlspecialchars($botUser, ENT_QUOTES) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($botUser, ENT_QUOTES) ?></a><?php else: ?>в Telegram<?php endif; ?> и введите 6‑значный код</p>

    <div class="otp-stage" id="otpStage">
      <input id="codeInput" class="otp-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" aria-label="Код из бота, 6 цифр" autofocus>
      <div class="otp-slots" aria-hidden="true">
        <div class="otp-slot"><span></span></div><div class="otp-slot"><span></span></div><div class="otp-slot"><span></span></div>
        <div class="otp-slot"><span></span></div><div class="otp-slot"><span></span></div><div class="otp-slot"><span></span></div>
      </div>
      <div class="otp-stack" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
      <div class="otp-result" aria-hidden="true"><svg viewBox="0 0 48 48"><path d="m12 25 8 8 17-19"/></svg></div>
    </div>

    <div class="status-line" aria-live="polite"><span class="status-dot"></span><span id="otpStatus">Ожидаю код</span></div>
    <button id="codeSubmit" type="button" class="code-btn" disabled>Войти</button>
    <p class="security-note" id="otpNote">Никому не сообщайте код из бота.</p>
    <?php if ($localDev): ?>
      <div class="login-local">
        <div>Локальный тестовый режим:</div>
        <div class="login-local-buttons">
          <a href="#" data-local="854102139">Войти как Миржан</a>
          <a href="#" data-local="995540516">Войти как Томирис</a>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<main class="shell" id="shell" hidden>
  <header class="topbar">
    <div class="brand">
      <img class="brand-logo" src="img/logo-mark.png" alt="" width="40" height="40">
      <div>
        <div class="eyebrow">Семейные финансы</div>
        <h1>Бюджет</h1>
      </div>
    </div>
    <div class="top-actions">
      <div class="user-switch" id="testUser" hidden>
        <button data-user="854102139" class="selected">Миржан</button>
        <button data-user="995540516">Томирис</button>
      </div>
      <button type="button" class="avatar" id="avatar" aria-label="Профиль и лимиты">₸</button>
    </div>
  </header>

  <div id="notice" class="notice hidden"></div>

  <nav class="main-tabs">
    <button data-tab="main" class="selected">Основное</button>
    <button data-tab="reports">Отчёты</button>
  </nav>

  <section class="tab-panel" id="tab-main">
    <div class="balances-hero card">
      <div class="balances-hero-top">
        <span>Общий бюджет</span>
        <span class="period" id="balancesDate">—</span>
      </div>
      <div class="balances-hero-total"><strong id="sharedBalance">—</strong><span id="sharedCurrency">KZT</span></div>
      <div class="balances-hero-meta">
        <div><span>Внесено всего</span><b id="sharedTopups">—</b></div>
        <div><span>Потрачено всего</span><b id="sharedExpenses">—</b></div>
      </div>
    </div>

    <div class="balances-personal" id="balancesPersonal"></div>

    <div class="section">
      <div class="section-title"><h2>Текущий месяц</h2><span class="muted" id="monthPeriod">—</span></div>
      <div class="month-grid">
        <div class="card mini-card">
          <span class="mini-label">Расходы</span>
          <b id="monthExpenses">—</b>
          <small class="mini-sub" id="monthWeekHint"></small>
        </div>
        <div class="card mini-card">
          <span class="mini-label">Обязательные</span>
          <b id="monthFixed">—</b>
          <small class="mini-sub" id="monthFixedShare"></small>
        </div>
        <div class="card mini-card">
          <span class="mini-label">Переменные</span>
          <b id="monthVariable">—</b>
          <small class="mini-sub" id="monthVariableShare"></small>
        </div>
      </div>
    </div>

    <div class="section" id="limitsSection">
      <div class="section-title"><h2>Лимиты</h2><button type="button" class="text-button" id="limitsEdit">Настроить</button></div>
      <div id="limitsList" class="limits card"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Последние операции</h2></div>
      <div id="recent" class="history"><div class="loading-placeholder">Загрузка…</div></div>
    </div>
  </section>

  <section class="tab-panel" id="tab-reports" hidden>
    <div class="card report-nav">
      <div class="period-stepper">
        <button type="button" id="periodPrev" aria-label="Предыдущий период">‹</button>
        <div class="period-title" aria-live="polite"><b id="periodTitle">—</b><span id="periodRange">—</span></div>
        <button type="button" id="periodNext" aria-label="Следующий период">›</button>
      </div>
      <div class="period-presets" id="presets">
        <button type="button" data-preset="month" class="selected">Месяц</button>
        <button type="button" data-preset="week">Неделя</button>
        <button type="button" data-preset="year">Год</button>
        <button type="button" data-preset="all">Всё время</button>
        <button type="button" data-preset="custom">Свой период</button>
      </div>
    </div>

    <div class="hero card">
      <div class="hero-top"><span id="reportHeroLabel">Потрачено</span><span class="period" id="reportPeriod">—</span></div>
      <div class="total"><strong id="reportExpenses">—</strong><span id="reportCurrency">KZT</span></div>
      <div class="hero-bottom">
        <div><span>Пополнения</span><b id="reportTopups">—</b></div>
        <div><span>Разница</span><b id="reportChange">—</b></div>
        <div><span>Операций</span><b id="reportCount">—</b></div>
      </div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Обязательные и переменные</h2></div>
      <div id="groups" class="card groups"></div>
    </div>

    <div class="section">
      <div class="section-title">
        <h2>Категории</h2>
        <div class="filter-chips" id="categoryFilter">
          <button type="button" data-filter="all" class="selected">Все</button>
          <button type="button" data-filter="fixed">Обязательные</button>
          <button type="button" data-filter="variable">Переменные</button>
        </div>
      </div>
      <div id="reportCategories" class="categories card"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Кто сколько потратил</h2></div>
      <div id="reportMembers" class="members"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>По дням</h2></div>
      <div id="chart" class="chart card"></div>
    </div>

    <div class="section">
      <div class="section-title">
        <h2>Операции</h2>
        <button type="button" class="text-button" id="export">Экспорт CSV</button>
      </div>
      <div id="reportHistory" class="history"></div>
      <div class="pagination" id="pagination"></div>
    </div>

    <div class="section">
      <details class="card more" id="comparisonMore">
        <summary><span>Сравнить с прошлым периодом<small id="comparisonHint">Скрыто, чтобы не мешать</small></span></summary>
        <div id="comparison" class="comparison"></div>
      </details>
      <details class="card more" id="balancesMore">
        <summary><span>Остатки на конец периода<small>Общий и личные балансы на последний день</small></span></summary>
        <div id="reportBalances" class="balances-personal"></div>
      </details>
    </div>
  </section>

  <footer>
    Только для вашей семьи <span>·</span> доступ через Telegram
    <?php if (!$localDev): ?><span>·</span><a href="logout.php" class="logout">Выйти</a><?php endif; ?>
  </footer>
</main>

<button class="fab" id="fab" aria-label="Добавить запись" hidden>
  <span class="fab-icon">+</span>
</button>

<div class="sheet" id="sheet" hidden>
  <div class="sheet-backdrop" data-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="sheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <h2 id="sheetTitle">Новая запись</h2>
      <button class="sheet-close" data-close aria-label="Закрыть">×</button>
    </div>

    <div class="sheet-body">
      <div class="kind-toggle" id="kindToggle">
        <button data-kind="expense" class="selected"><i>↘</i> Расход</button>
        <button data-kind="topup"><i>↗</i> Пополнение</button>
      </div>

      <div class="amount-block">
        <input id="amountInput" type="text" inputmode="decimal" placeholder="0" autocomplete="off">
        <span class="amount-currency" id="sheetCurrency">₸</span>
      </div>

      <div class="quick-row" id="quickAmounts">
        <button data-add="500">+500</button>
        <button data-add="1000">+1 000</button>
        <button data-add="5000">+5 000</button>
        <button data-add="10000">+10 000</button>
        <button data-clear class="clear">C</button>
      </div>

      <div class="field-label">Категория</div>
      <div class="cat-grid" id="catGrid"></div>
      <button type="button" class="custom-cat-btn" id="customCatBtn">+ Своя категория</button>
      <div class="limit-hint" id="limitHint" aria-live="polite" hidden></div>

      <div class="field-label">Дата</div>
      <div class="date-row" id="dateRow">
        <button data-date="today" class="selected">Сегодня</button>
        <button data-date="yesterday">Вчера</button>
        <button data-date="custom" id="customDateBtn"><i class="cal-icon">▦</i> <span id="customDateLabel">Выбрать</span></button>
      </div>

      <div class="field-label">Комментарий <span class="optional">необязательно</span></div>
      <input id="noteInput" class="note-input" maxlength="500" placeholder="Например, продукты на неделю" autocomplete="off">

      <button class="sheet-submit" id="submitBtn" type="button">Сохранить <span>↗</span></button>
    </div>
  </div>
</div>

<div class="cal-modal" id="calModal" hidden>
  <div class="cal-backdrop" data-cal-close></div>
  <div class="cal-content">
    <div class="cal-header">
      <button id="calPrev" aria-label="Предыдущий месяц">←</button>
      <div id="calTitle">—</div>
      <button id="calNext" aria-label="Следующий месяц">→</button>
    </div>
    <div class="cal-weekdays"><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span></div>
    <div class="cal-days" id="calDays"></div>
    <div class="cal-actions">
      <button id="calCancel" class="ghost">Отмена</button>
      <button id="calToday">Сегодня</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="rangeCalModal" hidden>
  <div class="cal-backdrop" data-range-close></div>
  <div class="cal-content">
    <div class="cal-header">
      <button id="rangeCalPrev" aria-label="Предыдущий месяц">←</button>
      <div id="rangeCalTitle">—</div>
      <button id="rangeCalNext" aria-label="Следующий месяц">→</button>
    </div>
    <div class="cal-weekdays"><span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span></div>
    <div class="cal-days" id="rangeCalDays"></div>
    <div class="cal-hint" id="rangeCalHint">Выберите дату начала</div>
    <div class="cal-actions">
      <button id="rangeCalCancel" class="ghost">Отмена</button>
      <button id="rangeCalApply" disabled>Применить</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="customCatModal" hidden>
  <div class="cal-backdrop" data-cat-close></div>
  <div class="cal-content small">
    <div class="cal-header"><div>Своя категория</div><button class="sheet-close" data-cat-close>×</button></div>
    <div class="cat-form">
      <label>Название<input id="customCatName" maxlength="80" placeholder="Например, Ремонт" autocomplete="off"></label>
      <label>Группа
        <div class="group-toggle" id="customCatGroup">
          <button data-group="variable" class="selected">Переменные</button>
          <button data-group="fixed">Обязательные</button>
        </div>
      </label>
      <button id="customCatSave" class="sheet-submit" type="button">Добавить</button>
    </div>
  </div>
</div>

<div class="confirm-modal" id="confirmModal" hidden>
  <div class="confirm-backdrop"></div>
  <div class="confirm-content">
    <p id="confirmText">Вы уверены?</p>
    <div class="confirm-actions">
      <button id="confirmCancel" class="ghost">Остаться</button>
      <button id="confirmOk">Выйти</button>
    </div>
  </div>
</div>

<div class="sheet" id="profileSheet" hidden>
  <div class="sheet-backdrop" data-profile-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="profileTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <h2 id="profileTitle">Профиль</h2>
      <button class="sheet-close" data-profile-close aria-label="Закрыть">×</button>
    </div>
    <div class="sheet-body">
      <div class="profile-head">
        <i class="balance-dot" id="profileDot">₸</i>
        <div><div class="balance-name" id="profileName">—</div><div class="balance-sub">Семейный бюджет · доступ через Telegram</div></div>
      </div>

      <div>
        <h3 class="profile-section-title">Лимиты</h3>
        <p class="limits-help">Трату можно записать и сверх лимита — он просто уйдёт в минус, а бот предупредит. Неделя начинается в понедельник.</p>
      </div>

      <div class="limit-tabs">
        <div class="group-toggle" id="limitScope" role="tablist" aria-label="Чьи лимиты">
          <button type="button" data-scope="family" class="selected">Семья <em></em></button>
          <button type="button" data-scope="me">Только мои <em></em></button>
        </div>
        <div class="group-toggle" id="limitPeriod" role="tablist" aria-label="Период">
          <button type="button" data-period="month" class="selected">Месяц <em></em></button>
          <button type="button" data-period="week">Неделя <em></em></button>
        </div>
        <p class="limit-scope-hint" id="limitScopeHint"></p>
      </div>

      <div id="limitsForm" class="limit-form"><div class="loading-placeholder"><div class="spinner"></div></div></div>

      <h3 class="profile-section-title">Настройки лимитов</h3>
      <label class="switch-row">
        <span><b>Переносить остаток</b><small>Неистраченное прибавится к лимиту следующего периода, перерасход — вычтется из него.</small></span>
        <input type="checkbox" id="rolloverToggle" role="switch">
        <i class="switch" aria-hidden="true"></i>
      </label>
      <div class="field-label">Предупреждать, когда осталось меньше</div>
      <div class="group-toggle" id="warnToggle"></div>

      <button class="sheet-submit" id="limitsSave" type="button" disabled>Сохранить</button>
      <?php if (!$localDev): ?><a href="logout.php" class="profile-logout" id="profileLogout">Выйти из аккаунта</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="sheet drill-sheet" id="drillSheet" hidden>
  <div class="sheet-backdrop" data-drill-close></div>
  <div class="sheet-content">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <h2 id="drillTitle">Детализация</h2>
      <button class="sheet-close" data-drill-close aria-label="Закрыть">×</button>
    </div>
    <div class="sheet-body" id="drillBody">
      <div class="drill-summary" id="drillSummary"></div>
      <button type="button" id="drillAllCats" class="drill-back" hidden>← Все категории</button>
      <div class="drill-tabs" id="drillTabs">
        <button data-view="categories" class="selected">Категории</button>
        <button data-view="transactions">Операции</button>
      </div>
      <div id="drillCategories" class="drill-cats"></div>
      <div id="drillTransactions" class="drill-tx" hidden></div>
    </div>
  </div>
</div>

<script>window.BOT_USERNAME=<?= json_encode($botUser) ?>;window.LOCAL_DEV=<?= json_encode($localDev) ?>;</script>
<script src="app.js"></script>
</body>
</html>
