<?php
require __DIR__.'/lib.php';
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://telegram.org https://cdnjs.cloudflare.com; worker-src 'self' blob: https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org; base-uri 'self'; form-action 'self'");
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
<script>try{var t=localStorage.getItem('fb_theme')||'auto';var d=t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=d?'dark':'light';}catch(e){}</script>
<meta name="description" content="Семейный бюджет — учёт расходов и доходов для всей семьи">
<title>Семейный бюджет</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
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
        <div id="sharedSavedBox" hidden><span>В копилках</span><b id="sharedSaved">—</b></div>
      </div>
    </div>

    <div class="card capital" id="capitalCard" hidden></div>

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

    <div class="card forecast" id="forecast" hidden></div>

    <div class="section" id="paymentsSection" hidden>
      <div class="section-title"><h2>Платежи этого месяца</h2><button type="button" class="text-button" id="paymentsEdit">Все</button></div>
      <div id="paymentsList" class="card payments"></div>
    </div>

    <div class="section" id="goalsSection">
      <div class="section-title"><h2>Цели</h2><button type="button" class="text-button" id="goalAdd">+ Новая</button></div>
      <div id="goalsList" class="goals"></div>
    </div>

    <div class="section panel-tiles">
      <button type="button" class="panel-tile" id="debtsOpen" aria-label="В долг: открыть">
        <span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11l-3-3M17 17H6l3 3"/></svg></span>
        <span class="panel-text"><b>В долг</b><small id="debtTileSub">Добавить запись</small></span>
      </button>
      <button type="button" class="panel-tile" id="accountsOpen" aria-label="Счета: открыть">
        <span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a1 1 0 0 1-1-1V7zM4 7l11-3v3M16 13h2"/></svg></span>
        <span class="panel-text"><b>Счета</b><small id="accountTileSub">Карты и наличные</small></span>
      </button>
      <button type="button" class="panel-tile" id="depositsOpen" aria-label="Депозиты: открыть">
        <span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10 12 4l9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/></svg></span>
        <span class="panel-text"><b>Депозиты</b><small id="depositTileSub">Добавить депозит</small></span>
      </button>
    </div>

    <div class="section" id="limitsSection">
      <div class="section-title"><h2>Лимиты</h2><button type="button" class="text-button" id="limitsEdit">Настроить</button></div>
      <div id="limitsList" class="limits card"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2 id="recentTitle">Последние операции</h2><button type="button" class="icon-button" id="searchToggle" aria-label="Поиск по операциям"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></button></div>
      <div class="search-box" id="searchBox" hidden>
        <input id="searchInput" type="search" placeholder="Поиск: кафе, магнум, 5000, Томирис" autocomplete="off" enterkeyhint="search" aria-label="Поиск по всем операциям">
        <button type="button" id="searchClose" aria-label="Закрыть поиск">×</button>
      </div>
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
      <div class="section-title"><h2>Структура расходов</h2></div>
      <div id="shareChart" class="card share-chart"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>По месяцам</h2><span class="muted">расходы</span></div>
      <div id="monthsChart" class="card months-chart"></div>
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
        <button type="button" class="text-button" id="export" aria-expanded="false">Экспорт</button>
      </div>
      <div class="export-menu" id="exportMenu" hidden>
        <button type="button" class="pill-button ghost" data-export="pdf">PDF</button>
        <button type="button" class="pill-button ghost" data-export="xlsx">Excel</button>
        <button type="button" class="pill-button ghost" data-export="csv">CSV</button>
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
        <button data-kind="transfer" id="kindTransfer"><i>⇄</i> Перевод</button>
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

      <div class="entry-fields" id="entryFields">
        <div class="field-label">Категория</div>
        <div class="cat-grid" id="catGrid"></div>
        <button type="button" class="custom-cat-btn" id="customCatBtn">+ Своя категория</button>
        <div class="limit-hint" id="limitHint" aria-live="polite" hidden></div>
      </div>

      <div class="entry-fields" id="transferFields" hidden>
        <div class="field-label">Кто кому передал</div>
        <div class="group-toggle stacked" id="transferDir"></div>
        <p class="field-help">Общий остаток не меняется — деньги переходят из личного остатка одного в личный остаток другого.</p>
      </div>

      <div class="entry-fields" id="payerRow" hidden>
        <div class="field-label">Кто платил</div>
        <div class="group-toggle" id="payerToggle"></div>
      </div>

      <div class="field-label">Дата</div>
      <div class="date-row" id="dateRow">
        <button data-date="today" class="selected">Сегодня</button>
        <button data-date="yesterday">Вчера</button>
        <button data-date="custom" id="customDateBtn"><i class="cal-icon">▦</i> <span id="customDateLabel">Выбрать</span></button>
      </div>

      <div class="entry-fields" id="accountField">
        <div class="field-label">Счёт <span class="optional">необязательно</span></div>
        <div class="date-row account-row" id="accountRow"></div>
      </div>

      <div class="field-label">Комментарий <span class="optional">необязательно</span></div>
      <input id="noteInput" class="note-input" maxlength="500" placeholder="Например, продукты на неделю" autocomplete="off">

      <button class="sheet-submit" id="submitBtn" type="button">Сохранить <span>↗</span></button>
      <button class="sheet-delete" id="sheetDelete" type="button" hidden>Удалить запись</button>
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

      <button type="button" class="panel-tile wide" id="categoriesOpen">
        <span class="panel-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12V4h8l9 9-8 8-9-9zM7.5 7.5h.01"/></svg></span>
        <span class="panel-text"><b>Категории</b><small>Переименовать, удалить, сменить группу</small></span>
      </button>

      <div>
        <h3 class="profile-section-title">Уведомления</h3>
        <label class="switch-row threshold-row"><span><b>Крупная трата</b><small>Если кто-то запишет трату от этой суммы, второму придёт сообщение. 0 — не уведомлять.</small></span>
          <span class="limit-input"><input id="bigExpenseInput" inputmode="numeric" autocomplete="off" aria-label="Порог крупной траты"><b>₸</b></span></label>
      </div>

      <div>
        <h3 class="profile-section-title">Оформление</h3>
        <div class="group-toggle three" id="themeToggle">
          <button type="button" data-theme-choice="auto">Авто</button>
          <button type="button" data-theme-choice="light">Светлая</button>
          <button type="button" data-theme-choice="dark">Тёмная</button>
        </div>
      </div>

      <div id="recurringBlock">
        <h3 class="profile-section-title">Регулярные платежи</h3>
        <p class="limits-help">Кредиты, коммуналка, подписки. В нужный день бот напомнит, а записать платёж можно одной кнопкой.</p>
        <div id="recurringList" class="recurring-list"></div>
        <button type="button" class="secondary-button" id="recurringAdd">+ Добавить платёж</button>
      </div>

      <div>
        <h3 class="profile-section-title">Импорт выписки Kaspi</h3>
        <p class="limits-help">Загрузите PDF-выписку Kaspi Gold — покупки попадут в бюджет с категориями. Файл разбирается прямо в браузере.</p>
        <button type="button" class="secondary-button" id="importOpen">Загрузить выписку</button>
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
      <button type="button" class="custom-cat-btn" id="limitsAddCat">+ Новая категория</button>

      <h3 class="profile-section-title">Настройки лимитов</h3>
      <label class="switch-row">
        <span><b>Переносить остаток</b><small>Неистраченное прибавится к лимиту следующего периода, перерасход — вычтется из него.</small></span>
        <input type="checkbox" id="rolloverToggle" role="switch">
        <i class="switch" aria-hidden="true"></i>
      </label>
      <div class="field-label">Предупреждать, когда осталось меньше</div>
      <div class="group-toggle" id="warnToggle"></div>

      <button class="sheet-submit" id="limitsSave" type="button" disabled>Сохранить лимиты</button>
      <div class="danger-zone">
        <h3 class="profile-section-title">Сброс данных</h3>
        <p class="limits-help">Удалит операции, лимиты, цели, платежи, долги и депозиты. Сработает только после подтверждения обоими в Telegram; перед сбросом бот сделает резервную копию.</p>
        <div class="reset-status" id="resetStatus" hidden></div>
        <button type="button" class="danger-button" id="resetRequest">Сбросить все данные</button>
      </div>
      <?php if (!$localDev): ?><a href="logout.php" class="profile-logout" id="profileLogout">Выйти из аккаунта</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="cal-modal" id="goalModal" hidden>
  <div class="cal-backdrop" data-goal-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="goalModalTitle">
    <div class="cal-header"><div id="goalModalTitle">Новая цель</div><button class="sheet-close" data-goal-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <label>Название<input id="goalTitle" maxlength="60" placeholder="Например, Отпуск" autocomplete="off"></label>
      <label>Сколько нужно, ₸<input id="goalTarget" inputmode="numeric" placeholder="600 000" autocomplete="off"></label>
      <label>К какой дате <span class="optional">необязательно</span><input id="goalDeadline" type="date"></label>
      <button id="goalSave" class="sheet-submit" type="button">Сохранить</button>
      <button id="goalCloseBtn" class="sheet-delete" type="button" hidden>Закрыть цель и вернуть деньги в бюджет</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="moveModal" hidden>
  <div class="cal-backdrop" data-move-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="moveTitle">
    <div class="cal-header"><div id="moveTitle">Копилка</div><button class="sheet-close" data-move-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <div class="group-toggle" id="moveDir">
        <button type="button" data-dir="1" class="selected">Отложить</button>
        <button type="button" data-dir="-1">Забрать</button>
      </div>
      <label>Сумма, ₸<input id="moveAmount" inputmode="numeric" placeholder="50 000" autocomplete="off"></label>
      <p class="field-help" id="moveHelp"></p>
      <button id="moveSave" class="sheet-submit" type="button">Готово</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="recModal" hidden>
  <div class="cal-backdrop" data-rec-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="recTitle">
    <div class="cal-header"><div id="recTitle">Регулярный платёж</div><button class="sheet-close" data-rec-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <label>Категория<select id="recCategory"></select></label>
      <label>Сумма, ₸<input id="recAmount" inputmode="numeric" placeholder="45 000" autocomplete="off"></label>
      <label>Комментарий <span class="optional">необязательно</span><input id="recNote" maxlength="200" placeholder="Например, Kaspi Red" autocomplete="off"></label>
      <label>День месяца<input id="recDay" type="number" min="1" max="31" inputmode="numeric" placeholder="10"></label>
      <div><div class="field-label">Кто платит</div><div class="group-toggle three" id="recPayer"></div></div>
      <label class="switch-row"><span><b>Активен</b><small>Выключите, чтобы приостановить напоминания</small></span><input type="checkbox" id="recActive" role="switch" checked><i class="switch" aria-hidden="true"></i></label>
      <button id="recSave" class="sheet-submit" type="button">Сохранить</button>
      <button id="recDelete" class="sheet-delete" type="button" hidden>Удалить платёж</button>
    </div>
  </div>
</div>

<div class="sheet" id="importSheet" hidden>
  <div class="sheet-backdrop" data-import-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="importTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <h2 id="importTitle">Импорт выписки</h2>
      <button class="sheet-close" data-import-close aria-label="Закрыть">×</button>
    </div>
    <div class="sheet-body">
      <div id="importStep1" class="import-step">
        <div class="field-label">Чья это выписка</div>
        <div class="group-toggle" id="importPayer"></div>
        <label class="file-drop" id="fileDrop">
          <input type="file" id="importFile" accept="application/pdf,.pdf,.txt">
          <b>Выбрать PDF-выписку</b>
          <span id="importFileName">Kaspi → Kaspi Gold → Выписка → Скачать PDF</span>
        </label>
        <details class="paste-box"><summary>или вставить текст выписки</summary><textarea id="importText" rows="6" placeholder="01.10.26  - 3 450,00 ₸  Покупка  MAGNUM"></textarea></details>
        <button class="sheet-submit" id="importParse" type="button">Разобрать</button>
      </div>
      <div id="importStep2" class="import-step" hidden>
        <div class="import-summary" id="importSummary"></div>
        <div class="import-rows" id="importRows"></div>
        <button class="sheet-submit" id="importConfirm" type="button">Импортировать</button>
        <button class="secondary-button" id="importBack" type="button">← Выбрать другой файл</button>
      </div>
    </div>
  </div>
</div>

<div class="sheet" id="debtsSheet" hidden>
  <div class="sheet-backdrop" data-debts-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="debtsTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h2 id="debtsTitle">В долг</h2><button class="sheet-close" data-debts-close aria-label="Закрыть">×</button></div>
    <div class="sheet-body">
      <div class="debt-totals" id="debtTotals"></div>
      <div id="debtsList" class="debts-list"></div>
      <button type="button" class="sheet-submit" id="debtAdd2">+ Добавить долг</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="debtModal" hidden>
  <div class="cal-backdrop" data-debt-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="debtTitle">
    <div class="cal-header"><div id="debtTitle">Новый долг</div><button class="sheet-close" data-debt-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <div class="debt-current" id="debtCurrent" hidden></div>
      <div class="debt-repay" id="debtRepay" hidden>
        <label>Сумма, ₸<input id="debtMoveAmount" inputmode="numeric" placeholder="10 000" autocomplete="off"></label>
        <div class="debt-repay-buttons">
          <button type="button" class="pill-button" id="debtRepayBtn">Вернули часть</button>
          <button type="button" class="pill-button ghost" id="debtMoreBtn">Ещё в долг</button>
        </div>
        <div class="debt-moves" id="debtMoves"></div>
      </div>
      <div class="group-toggle" id="debtDirection">
        <button type="button" data-dir="owed_to_me" class="selected">Мне должны</button>
        <button type="button" data-dir="i_owe">Я должен</button>
      </div>
      <label>Кто<input id="debtPerson" maxlength="80" placeholder="Имя" autocomplete="off"></label>
      <label id="debtAmountLabel">Сумма, ₸<input id="debtAmount" inputmode="numeric" placeholder="50 000" autocomplete="off"></label>
      <label>Комментарий <span class="optional">необязательно</span><input id="debtNote" maxlength="300" placeholder="За что" autocomplete="off"></label>
      <label>Вернуть до <span class="optional">необязательно</span><input id="debtDue" type="date"></label>
      <button id="debtSave" class="sheet-submit" type="button">Сохранить</button>
      <button id="debtDelete" class="sheet-delete" type="button" hidden>Удалить запись</button>
    </div>
  </div>
</div>

<div class="sheet" id="accountsSheet" hidden>
  <div class="sheet-backdrop" data-accounts-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="accountsTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h2 id="accountsTitle">Счета</h2><button class="sheet-close" data-accounts-close aria-label="Закрыть">×</button></div>
    <div class="sheet-body">
      <p class="limits-help">Карты и наличные. Остаток счёта считается по его операциям; выписки и чеки привязываются сами, а вручную счёт выбирается в форме записи.</p>
      <div class="deposit-total" id="accountsTotal"></div>
      <div id="accountsList" class="debts-list"></div>
      <button type="button" class="sheet-submit" id="accountAdd">+ Новый счёт</button>
    </div>
  </div>
</div>

<div class="sheet" id="accountSheet" hidden>
  <div class="sheet-backdrop" data-account-sheet-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="accountSheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h2 id="accountSheetTitle">Счёт</h2><button class="sheet-close" data-account-sheet-close aria-label="Закрыть">×</button></div>
    <div class="sheet-body">
      <div class="debt-current" id="accountBalance"></div>
      <div class="deposit-form">
        <div class="field-label">Сверить с банком или кошельком</div>
        <input id="accountRealBalance" class="note-input" inputmode="decimal" placeholder="Сколько на самом деле, ₸" autocomplete="off" aria-label="Фактический остаток">
        <button type="button" class="secondary-button" id="accountReconcile">Сверить остаток</button>
      </div>
      <div class="limit-group-title">Операции</div>
      <div id="accountOps" class="history"></div>
      <button type="button" class="secondary-button" id="accountEdit">Переименовать или удалить</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="accountModal" hidden>
  <div class="cal-backdrop" data-account-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="accountModalTitle">
    <div class="cal-header"><div id="accountModalTitle">Новый счёт</div><button class="sheet-close" data-account-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <label>Название<input id="accountName" maxlength="80" placeholder="Например, Kaspi Gold или Наличные" autocomplete="off"></label>
      <div class="group-toggle three" id="accountKind">
        <button type="button" data-kind="card" class="selected">Карта</button>
        <button type="button" data-kind="cash">Наличные</button>
        <button type="button" data-kind="other">Другое</button>
      </div>
      <button id="accountSave" class="sheet-submit" type="button">Сохранить</button>
      <button id="accountDelete" class="sheet-delete" type="button" hidden>Убрать счёт (операции останутся)</button>
    </div>
  </div>
</div>

<div class="sheet" id="catsSheet" hidden>
  <div class="sheet-backdrop" data-cats-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="catsTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h2 id="catsTitle">Категории</h2><button class="sheet-close" data-cats-close aria-label="Закрыть">×</button></div>
    <div class="sheet-body">
      <div id="catsList" class="debts-list"></div>
      <button type="button" class="sheet-submit" id="catsAdd">+ Новая категория</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="catEditModal" hidden>
  <div class="cal-backdrop" data-catedit-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="catEditTitle">
    <div class="cal-header"><div id="catEditTitle">Категория</div><button class="sheet-close" data-catedit-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <label>Название<input id="catEditName" maxlength="80" autocomplete="off"></label>
      <div class="group-toggle" id="catEditGroup">
        <button type="button" data-group="fixed">Обязательные</button>
        <button type="button" data-group="variable">Переменные</button>
      </div>
      <p class="field-help" id="catEditHelp"></p>
      <button id="catEditSave" class="sheet-submit" type="button">Сохранить</button>
      <div class="danger-zone">
        <label>Удалить и перенести операции в<select id="catMoveTo"></select></label>
        <button id="catDelete" class="danger-button" type="button">Удалить категорию</button>
      </div>
    </div>
  </div>
</div>

<div class="sheet" id="depositsSheet" hidden>
  <div class="sheet-backdrop" data-deposits-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="depositsTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h2 id="depositsTitle">Депозиты</h2><button class="sheet-close" data-deposits-close aria-label="Закрыть">×</button></div>
    <div class="sheet-body">
      <div class="deposit-total" id="depositsTotal"></div>
      <div id="depositsList" class="debts-list"></div>
      <button type="button" class="sheet-submit" id="depositAdd">+ Новый депозит</button>
    </div>
  </div>
</div>

<div class="sheet" id="depositSheet" hidden>
  <div class="sheet-backdrop" data-deposit-sheet-close></div>
  <div class="sheet-content" role="dialog" aria-labelledby="depositSheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header"><h2 id="depositSheetTitle">Депозит</h2><button class="sheet-close" data-deposit-sheet-close aria-label="Закрыть">×</button></div>
    <div class="sheet-body">
      <div class="debt-current" id="depositBalance"></div>
      <p class="field-help" id="depositForecast" hidden></p>
      <div class="group-toggle three" id="depositKind">
        <button type="button" data-kind="in" class="selected">Пополнить</button>
        <button type="button" data-kind="out">Снять</button>
        <button type="button" data-kind="interest">Проценты</button>
      </div>
      <div class="deposit-form">
        <input id="depositMoveAmount" class="note-input" inputmode="numeric" placeholder="Сумма, ₸" autocomplete="off" aria-label="Сумма операции">
        <input id="depositMoveNote" class="note-input" maxlength="300" placeholder="Комментарий (необязательно)" autocomplete="off" aria-label="Комментарий">
        <button type="button" class="sheet-submit" id="depositMoveSave">Записать</button>
      </div>
      <div class="limit-group-title">Журнал операций</div>
      <div id="depositJournal" class="journal"></div>
      <button type="button" class="secondary-button" id="depositEdit">Изменить название, банк, ставку</button>
    </div>
  </div>
</div>

<div class="cal-modal" id="depositModal" hidden>
  <div class="cal-backdrop" data-deposit-close></div>
  <div class="cal-content small" role="dialog" aria-labelledby="depositTitle">
    <div class="cal-header"><div id="depositTitle">Депозит</div><button class="sheet-close" data-deposit-close aria-label="Закрыть">×</button></div>
    <div class="cat-form">
      <label>Название<input id="depositName" maxlength="80" placeholder="Например, На квартиру" autocomplete="off"></label>
      <label>Банк <span class="optional">необязательно</span><input id="depositBank" maxlength="80" placeholder="Kaspi, Halyk, Freedom…" autocomplete="off"></label>
      <label id="depositAmountLabel">Сумма при открытии, ₸ <span class="optional">необязательно</span><input id="depositAmount" inputmode="numeric" placeholder="1 000 000" autocomplete="off"></label>
      <label>Ставка, % годовых <span class="optional">необязательно</span><input id="depositRate" inputmode="decimal" placeholder="14,5" autocomplete="off"></label>
      <label>Комментарий <span class="optional">необязательно</span><input id="depositNote" maxlength="300" autocomplete="off"></label>
      <button id="depositSave" class="sheet-submit" type="button">Сохранить</button>
      <button id="depositDelete" class="sheet-delete" type="button" hidden>Удалить депозит</button>
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
<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
</body>
</html>
