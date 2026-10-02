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
<meta name="theme-color" content="#f4f5f8">
<meta name="description" content="Семейный бюджет — учёт расходов и доходов для всей семьи">
<title>Семейный бюджет</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="app.css">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>

<div class="login" id="login" hidden>
  <div class="login-card">
    <div class="login-emoji">₸</div>
    <h1>Семейный бюджет</h1>
    <p>Отправьте команду <b>/login</b> боту <b><?php if ($botUser): ?><a href="https://t.me/<?= htmlspecialchars($botUser, ENT_QUOTES) ?>" target="_blank">@<?= htmlspecialchars($botUser, ENT_QUOTES) ?></a><?php else: ?>в Telegram<?php endif; ?></b> и введите полученный код.</p>
    <div class="code-form" id="codeForm">
      <input id="codeInput" type="text" inputmode="numeric" maxlength="6" placeholder="Код из бота" autocomplete="one-time-code" autofocus>
      <div id="codeError" class="code-error" hidden></div>
      <button id="codeSubmit" type="button" class="code-btn">Войти</button>
    </div>
    <?php if ($localDev): ?>
      <div class="login-local">
        <div>Локальный тестовый режим:</div>
        <div class="login-local-buttons">
          <a href="#" data-local="854102139">Войти как Миржан</a>
          <a href="#" data-local="995540516">Войти как Томирис</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<main class="shell" id="shell" hidden>
  <header class="topbar">
    <div>
      <div class="eyebrow">ВАШ ДОМ · ОБЩИЕ ФИНАНСЫ</div>
      <h1>Бюджет</h1>
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
      <div class="section-title"><h2>Лимиты на месяц</h2><button type="button" class="text-button" id="limitsEdit">Настроить</button></div>
      <div id="limitsList" class="limits card"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Последние операции</h2></div>
      <div id="recent" class="history"><div class="loading-placeholder">Загрузка…</div></div>
    </div>
  </section>

  <section class="tab-panel" id="tab-reports" hidden>
    <div class="card period-picker">
      <div class="period-presets" id="presets">
        <button data-preset="today">Сегодня</button>
        <button data-preset="week">Неделя</button>
        <button data-preset="month" class="selected">Месяц</button>
        <button data-preset="year">Год</button>
        <button data-preset="all">Всё время</button>
      </div>
      <div class="period-custom">
        <button type="button" class="date-chip" id="dateFromBtn"><span>С</span><b id="dateFromLabel">—</b></button>
        <button type="button" class="date-chip" id="dateToBtn"><span>По</span><b id="dateToLabel">—</b></button>
        <button type="button" class="apply-btn" id="applyRange">Применить</button>
      </div>
    </div>

    <div class="hero card">
      <div class="hero-top"><span>Расходы за период</span><span class="period" id="reportPeriod">—</span></div>
      <div class="total"><strong id="reportExpenses">—</strong><span id="reportCurrency">KZT</span></div>
      <div class="hero-bottom">
        <div><span>Пополнения</span><b id="reportTopups">—</b></div>
        <div><span>Изменение</span><b id="reportChange">—</b></div>
        <div><span>Операций</span><b id="reportCount">—</b></div>
      </div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Сравнение с прошлым периодом</h2></div>
      <div id="comparison" class="card comparison"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Обязательные и переменные</h2></div>
      <div id="groups" class="card groups"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Остатки на конец периода</h2></div>
      <div id="reportBalances" class="balances-personal"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Участники</h2></div>
      <div id="reportMembers" class="members"></div>
    </div>

    <div class="section">
      <div class="section-title">
        <h2>Категории</h2>
        <div class="filter-chips" id="categoryFilter">
          <button data-filter="all" class="selected">Все</button>
          <button data-filter="fixed">Обязательные</button>
          <button data-filter="variable">Переменные</button>
        </div>
      </div>
      <div id="reportCategories" class="categories card"></div>
    </div>

    <div class="section">
      <div class="section-title"><h2>Динамика по дням</h2></div>
      <div id="chart" class="chart card"></div>
    </div>

    <div class="section">
      <div class="section-title">
        <h2>История периода</h2>
        <button class="text-button" id="export">Экспорт CSV</button>
      </div>
      <div id="reportHistory" class="history"></div>
      <div class="pagination" id="pagination"></div>
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
        <h3 class="profile-section-title">Лимиты на месяц</h3>
        <p class="limits-help">Общие для всей семьи и считаются по календарному месяцу. Трату можно записать и сверх лимита — он просто уйдёт в минус, а бот предупредит обоих.</p>
      </div>

      <div class="field-label">Предупреждать, когда осталось меньше</div>
      <div class="group-toggle" id="warnToggle"></div>

      <div id="limitsForm" class="limit-form"><div class="loading-placeholder"><div class="spinner"></div></div></div>

      <button class="sheet-submit" id="limitsSave" type="button" disabled>Сохранить лимиты</button>
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
