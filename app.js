(()=>{
const tg = window.Telegram?.WebApp;
const localDev = window.LOCAL_DEV === true;
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const symbols = {KZT:'₸',RUB:'₽',USD:'$',EUR:'€'};
let testUserId = localStorage.getItem('fb_test_user') || '854102139';
let categoryGroups = {fixed:[],variable:[]};
let currency = 'KZT';
let reportState = {preset:'month', from:null, to:null, page:1, filter:'all'};
let mainCache = null;
let reportCache = null;
let mainAbort = null;
let reportAbort = null;
let drillAbort = null;

const safe = s => String(s??'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = n => new Intl.NumberFormat('ru-RU',{maximumFractionDigits:0}).format(Number(n||0));
const moneyDec = n => { const v = Number(n||0); return v % 1 === 0 ? money(v) : new Intl.NumberFormat('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2}).format(v); };
const cur = c => symbols[c] || c;
const fmtDate = d => d ? d.split('-').reverse().join('.') : '—';
const todayISO = () => new Date(Date.now() - new Date().getTimezoneOffset()*60000).toISOString().slice(0,10);
const ymd = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const MONTHS = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const MONTHS_SHORT = ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'];

/* ========== NOTICE ========== */
let noticeTimer = null;
// ok: true = success, 'warn' = attention (e.g. a limit is running out)
function notice(s, ok=false){
  const n=$('#notice');
  n.innerHTML = safe(s) + '<button class="notice-close" aria-label="Закрыть">×</button>';
  n.className='notice'+(ok==='warn'?' warn':ok?' success':'');
  n.querySelector('.notice-close').onclick = () => n.classList.add('hidden');
  clearTimeout(noticeTimer);
  noticeTimer = setTimeout(()=>n.classList.add('hidden'), 5000);
}

/* ========== LOADING ========== */
function setLoading(el, on){
  if (on) el.innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
}

/* ========== HEADERS & REQUEST ========== */
function headers(){
  const h = {'Content-Type':'application/json'};
  if (tg?.initData) h['X-Telegram-Init-Data'] = tg.initData;
  if (localDev) h['X-Budget-Test-User'] = testUserId;
  return h;
}

async function request(url, opts={}){
  const r = await fetch(url, {...opts, headers:{...headers(), ...(opts.headers||{})}, credentials:'same-origin'});
  if (!r.ok) {
    const d = await r.json().catch(()=>({}));
    throw Error(d.error || `Ошибка ${r.status}`);
  }
  return r.json();
}

/* ========== SHELL ========== */
function showShell(){ $('#login').hidden=true; $('#shell').hidden=false; $('#fab').hidden=false; document.body.classList.add('authorized'); }
function showLogin(){ $('#login').hidden=false; $('#shell').hidden=true; $('#fab').hidden=true; document.body.classList.remove('authorized'); bindCodeAuth(); }

/* ========== CODE AUTH ========== */
function bindCodeAuth(){
  const input = $('#codeInput');
  const btn = $('#codeSubmit');
  const err = $('#codeError');
  if (!input || !btn) return;

  // Auto-format: only digits, max 6
  input.oninput = () => {
    input.value = input.value.replace(/\D/g, '').slice(0, 6);
    err.hidden = true;
  };
  input.onkeydown = (e) => { if (e.key === 'Enter') submitCode(); };
  btn.onclick = submitCode;

  async function submitCode(){
    const code = input.value.trim();
    if (code.length !== 6) { showCodeError('Введите 6-значный код'); return; }
    btn.disabled = true;
    btn.textContent = 'Проверяю…';
    err.hidden = true;
    try {
      const r = await fetch('auth.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({code}),
        credentials: 'same-origin',
      });
      const d = await r.json();
      if (r.ok && d.ok) {
        // Success — reload to enter the app
        location.reload();
      } else {
        showCodeError(d.error || 'Неверный код');
        input.value = '';
        input.focus();
      }
    } catch(e) {
      showCodeError('Ошибка сети. Попробуйте позже.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Войти';
    }
  }

  function showCodeError(msg){
    err.textContent = msg;
    err.hidden = false;
    input.classList.add('shake');
    setTimeout(() => input.classList.remove('shake'), 400);
  }
}

function switchTab(name){
  $$('.main-tabs button').forEach(b => b.classList.toggle('selected', b.dataset.tab === name));
  $('#tab-main').hidden = name !== 'main';
  $('#tab-reports').hidden = name !== 'reports';
  if (name === 'main') loadMain().catch(()=>{}); else loadReport().catch(()=>{});
}

/* ========== iOS KEYBOARD DISMISS ========== */
document.addEventListener('touchstart', e => {
  const tag = e.target.tagName;
  if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
    const active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) {
      active.blur();
    }
  }
}, {passive: true});

/* ========== CONFIRM MODAL ========== */
function showConfirm(text){
  return new Promise(resolve => {
    const modal = $('#confirmModal');
    $('#confirmText').textContent = text;
    modal.hidden = false;
    const ok = $('#confirmOk');
    const cancel = $('#confirmCancel');
    const cleanup = (val) => { modal.hidden = true; ok.onclick = null; cancel.onclick = null; resolve(val); };
    ok.onclick = () => cleanup(true);
    cancel.onclick = () => cleanup(false);
    modal.querySelector('.confirm-backdrop').onclick = () => cleanup(false);
  });
}

/* ========== CALENDAR ========== */
function buildCalendar(daysEl, titleEl, opts){
  let view = opts.initial ? new Date(opts.initial + 'T00:00:00') : new Date();
  view.setDate(1);
  let selected = opts.selected ? new Date(opts.selected + 'T00:00:00') : null;
  let rangeFrom = opts.rangeFrom ? new Date(opts.rangeFrom + 'T00:00:00') : null;
  let rangeTo = opts.rangeTo ? new Date(opts.rangeTo + 'T00:00:00') : null;
  const today = new Date(); today.setHours(0,0,0,0);

  function render(){
    titleEl.textContent = `${MONTHS[view.getMonth()]} ${view.getFullYear()}`;
    const year = view.getFullYear(), month = view.getMonth();
    const first = new Date(year, month, 1);
    const startDow = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(year, month+1, 0).getDate();
    const prevMonthDays = new Date(year, month, 0).getDate();
    const cells = [];
    for (let i = startDow - 1; i >= 0; i--) cells.push({d: prevMonthDays - i, other: true, date: new Date(year, month-1, prevMonthDays - i)});
    for (let i = 1; i <= daysInMonth; i++) cells.push({d: i, other: false, date: new Date(year, month, i)});
    const tail = 42 - cells.length;
    for (let i = 1; i <= tail; i++) cells.push({d: i, other: true, date: new Date(year, month+1, i)});

    daysEl.innerHTML = cells.map(c => {
      const iso = ymd(c.date);
      const cls = ['cal-day'];
      if (c.other) cls.push('other');
      if (c.date.getTime() === today.getTime()) cls.push('today');
      if (selected && c.date.getTime() === selected.getTime()) cls.push('selected');
      if (rangeFrom && c.date.getTime() === rangeFrom.getTime()) cls.push('selected');
      if (rangeTo && c.date.getTime() === rangeTo.getTime()) cls.push('selected');
      if (rangeFrom && rangeTo && c.date > rangeFrom && c.date < rangeTo) cls.push('in-range');
      const disabled = opts.maxDate && c.date > new Date(opts.maxDate + 'T00:00:00');
      return `<button type="button" class="${cls.join(' ')}" data-date="${iso}" ${disabled?'disabled':''} aria-label="${c.d} ${MONTHS[c.date.getMonth()]}">${c.d}</button>`;
    }).join('');

    daysEl.querySelectorAll('.cal-day').forEach(b => b.onclick = () => {
      if (b.disabled) return;
      const iso = b.dataset.date;
      if (opts.mode === 'range') {
        if (!rangeFrom || (rangeFrom && rangeTo)) { rangeFrom = new Date(iso + 'T00:00:00'); rangeTo = null; }
        else if (new Date(iso + 'T00:00:00') < rangeFrom) { rangeFrom = new Date(iso + 'T00:00:00'); }
        else { rangeTo = new Date(iso + 'T00:00:00'); }
        opts.onRangeChange?.(rangeFrom ? ymd(rangeFrom) : null, rangeTo ? ymd(rangeTo) : null);
      } else {
        selected = new Date(iso + 'T00:00:00');
        opts.onSelect?.(iso);
      }
      render();
    });
  }

  opts.prevBtn.onclick = () => { view.setMonth(view.getMonth() - 1); render(); };
  opts.nextBtn.onclick = () => { view.setMonth(view.getMonth() + 1); render(); };
  opts.todayBtn && (opts.todayBtn.onclick = () => { view = new Date(); view.setDate(1); selected = new Date(today); opts.onSelect?.(ymd(today)); render(); });
  render();
  return {
    setRange(f, t){ rangeFrom = f ? new Date(f + 'T00:00:00') : null; rangeTo = t ? new Date(t + 'T00:00:00') : null; render(); },
    getRange(){ return [rangeFrom ? ymd(rangeFrom) : null, rangeTo ? ymd(rangeTo) : null]; }
  };
}

/* ========== SHEET ANIMATIONS ========== */
function animateClose(el, animClass = 'closing'){
  return new Promise(resolve => {
    el.classList.add(animClass);
    el.addEventListener('animationend', function handler(){
      el.removeEventListener('animationend', handler);
      el.classList.remove(animClass);
      el.hidden = true;
      resolve();
    }, {once: true});
    // Fallback if animation doesn't fire
    setTimeout(() => { el.classList.remove(animClass); el.hidden = true; resolve(); }, 350);
  });
}

/* ========== SHEET: NEW ENTRY ========== */
const sheetState = {kind:'expense', amount:'', category:null, group:'variable', date:todayISO(), dateMode:'today', note:''};
let sheetCal = null;

function sheetHasData(){
  return sheetState.amount !== '' || sheetState.category !== null || ($('#noteInput')?.value?.trim() || '') !== '';
}

function renderCatGrid(){
  const fixed = (categoryGroups.fixed || []).filter(c => c !== 'Пополнение');
  const variable = (categoryGroups.variable || []).filter(c => c !== 'Пополнение');
  const chip = (name, group) => `<button type="button" class="cat-chip ${group} ${sheetState.category===name?'selected':''}" data-cat="${safe(name)}" data-group="${group}"><i class="dot-mark"></i>${safe(name)}</button>`;
  const custom = sheetState.category && !fixed.includes(sheetState.category) && !variable.includes(sheetState.category)
    ? chip(sheetState.category, sheetState.group) : '';
  $('#catGrid').innerHTML = fixed.map(c => chip(c, 'fixed')).join('') + variable.map(c => chip(c, 'variable')).join('') + custom;
  $$('#catGrid .cat-chip').forEach(b => b.onclick = () => {
    sheetState.category = b.dataset.cat;
    sheetState.group = b.dataset.group;
    renderCatGrid();
  });
  updateLimitHint();
}

function openSheet(){
  sheetState.kind = 'expense';
  sheetState.amount = '';
  sheetState.category = null;
  sheetState.group = 'variable';
  sheetState.date = todayISO();
  sheetState.dateMode = 'today';
  sheetState.note = '';
  $('#amountInput').value = '';
  $('#noteInput').value = '';
  $('#sheetCurrency').textContent = cur(currency);
  $$('#kindToggle button').forEach(b => b.classList.toggle('selected', b.dataset.kind === 'expense'));
  $$('#dateRow button').forEach(b => b.classList.toggle('selected', b.dataset.date === 'today'));
  $('#customDateLabel').textContent = 'Выбрать';
  renderCatGrid();
  const sheet = $('#sheet');
  sheet.hidden = false;
  sheet.classList.remove('closing');
  document.body.style.overflow = 'hidden';
  setTimeout(() => $('#amountInput').focus(), 200);
}

async function closeSheet(force = false){
  if (!force && sheetHasData()) {
    const confirmed = await showConfirm('Данные не сохранены. Выйти без сохранения?');
    if (!confirmed) return;
  }
  document.body.style.overflow = '';
  await animateClose($('#sheet'));
}

function updateAmountDisplay(){
  const v = sheetState.amount.replace(/\s/g, '');
  if (!v) { $('#amountInput').value = ''; return; }
  const num = Number(v);
  if (v.includes('.')) {
    const parts = v.split('.');
    const intPart = new Intl.NumberFormat('ru-RU').format(Number(parts[0] || 0));
    $('#amountInput').value = intPart + ',' + (parts[1] || '');
  } else {
    $('#amountInput').value = new Intl.NumberFormat('ru-RU').format(num);
  }
}

function bindSheet(){
  $('#fab').onclick = openSheet;
  $$('#sheet [data-close]').forEach(el => el.onclick = () => closeSheet());

  $$('#kindToggle button').forEach(b => b.onclick = () => {
    sheetState.kind = b.dataset.kind;
    $$('#kindToggle button').forEach(x => x.classList.toggle('selected', x === b));
    updateLimitHint();
  });

  const amt = $('#amountInput');
  amt.oninput = () => {
    let v = amt.value.replace(/[^\d.,]/g, '').replace(',', '.');
    const parts = v.split('.');
    if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
    if (parts[1] && parts[1].length > 2) v = parts[0] + '.' + parts[1].slice(0,2);
    sheetState.amount = v;
    updateAmountDisplay();
    updateLimitHint();
    amt.setSelectionRange(amt.value.length, amt.value.length);
  };
  amt.onfocus = () => { setTimeout(() => amt.setSelectionRange(amt.value.length, amt.value.length), 0); };

  $$('#quickAmounts button').forEach(b => b.onclick = () => {
    if (b.dataset.clear !== undefined || b.classList.contains('clear')) { sheetState.amount = ''; }
    else {
      const add = Number(b.dataset.add);
      const current = Number(sheetState.amount || 0);
      sheetState.amount = String(current + add);
    }
    updateAmountDisplay();
    updateLimitHint();
    amt.focus();
  });

  $$('#dateRow button').forEach(b => b.onclick = () => {
    if (b.id === 'customDateBtn') { openSheetCalendar(); return; }
    $$('#dateRow button').forEach(x => x.classList.toggle('selected', x === b));
    sheetState.dateMode = b.dataset.date;
    if (b.dataset.date === 'today') sheetState.date = todayISO();
    if (b.dataset.date === 'yesterday') { const d = new Date(); d.setDate(d.getDate()-1); sheetState.date = ymd(d); }
    $('#customDateLabel').textContent = 'Выбрать';
    updateLimitHint();
  });

  $('#customCatBtn').onclick = openCustomCatModal;
  $('#submitBtn').onclick = submitEntry;
}

function openSheetCalendar(){
  const modal = $('#calModal');
  modal.hidden = false;
  sheetCal = buildCalendar($('#calDays'), $('#calTitle'), {
    initial: sheetState.date,
    selected: sheetState.date,
    maxDate: todayISO(),
    prevBtn: $('#calPrev'),
    nextBtn: $('#calNext'),
    todayBtn: $('#calToday'),
    onSelect(iso){ sheetState.date = iso; sheetState.dateMode = 'custom'; $('#customDateLabel').textContent = fmtDate(iso); $$('#dateRow button').forEach(x => x.classList.toggle('selected', x.id === 'customDateBtn')); closeSheetCalendar(); updateLimitHint(); }
  });
}
function closeSheetCalendar(){ $('#calModal').hidden = true; sheetCal = null; }
$$('#calModal [data-cal-close]').forEach(el => el.onclick = closeSheetCalendar);
$('#calCancel').onclick = closeSheetCalendar;

/* ========== CUSTOM CATEGORY ========== */
let customGroup = 'variable';
function openCustomCatModal(){
  $('#customCatName').value = '';
  customGroup = 'variable';
  $$('#customCatGroup button').forEach(b => b.classList.toggle('selected', b.dataset.group === 'variable'));
  $('#customCatModal').hidden = false;
  setTimeout(() => $('#customCatName').focus(), 100);
}
function closeCustomCatModal(){ $('#customCatModal').hidden = true; }
$$('#customCatModal [data-cat-close]').forEach(el => el.onclick = closeCustomCatModal);
$$('#customCatGroup button').forEach(b => b.onclick = () => {
  customGroup = b.dataset.group;
  $$('#customCatGroup button').forEach(x => x.classList.toggle('selected', x === b));
});
$('#customCatSave').onclick = () => {
  const name = $('#customCatName').value.trim();
  if (!name) { notice('Введите название'); return; }
  if (name.length > 80) { notice('Слишком длинное название'); return; }
  const group = customGroup;
  if (!categoryGroups[group]) categoryGroups[group] = [];
  if (!categoryGroups[group].includes(name)) categoryGroups[group].push(name);
  sheetState.category = name;
  sheetState.group = group;
  renderCatGrid();
  closeCustomCatModal();
};

/* ========== SUBMIT ========== */
async function submitEntry(){
  const btn = $('#submitBtn');
  const amount = Number(sheetState.amount);
  if (!amount || amount <= 0) { notice('Введите сумму'); $('#amountInput').focus(); return; }
  if (!sheetState.category) { notice('Выберите категорию'); return; }
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner small"></div> Сохранение…';
  try {
    const saved = await request('api.php', {method:'POST', body: JSON.stringify({
      kind: sheetState.kind,
      amount,
      category: sheetState.category,
      category_group: sheetState.group,
      note: sheetState.note || $('#noteInput').value,
      date: sheetState.date,
    })});
    const limitMsg = limitNoticeAfterSave(saved.limits);
    if (limitMsg) notice('Запись добавлена. ' + limitMsg, 'warn'); else notice('Запись добавлена ✓', true);
    await closeSheet(true);
    mainCache = null; reportCache = null;
    await loadMain();
  } catch(e) { notice(e.message); } finally { btn.disabled = false; btn.innerHTML = 'Сохранить <span>↗</span>'; }
}

/* ========== DELETE TRANSACTION ========== */
async function deleteTransaction(id, el){
  const confirmed = await showConfirm('Удалить эту операцию?');
  if (!confirmed) return;
  el.classList.add('deleting');
  try {
    await request('api.php?action=delete', {method:'POST', body: JSON.stringify({id})});
    el.style.height = el.offsetHeight + 'px';
    el.offsetHeight; // force reflow
    el.style.height = '0';
    el.style.opacity = '0';
    el.style.marginBottom = '0';
    el.style.padding = '0';
    el.style.overflow = 'hidden';
    el.style.transition = 'all .3s ease';
    setTimeout(() => { el.remove(); notice('Запись удалена', true); }, 300);
    mainCache = null; reportCache = null;
  } catch(e) { notice(e.message); el.classList.remove('deleting'); }
}

/* ========== DATE FORMATTING ========== */
function fmtDateFriendly(d){
  if (!d) return '—';
  const t = todayISO();
  const y = new Date(); y.setDate(y.getDate()-1);
  const yiso = ymd(y);
  if (d === t) return 'Сегодня';
  if (d === yiso) return 'Вчера';
  const parts = d.split('-');
  return `${parseInt(parts[2])} ${MONTHS_SHORT[parseInt(parts[1])-1]}`;
}

/* ========== MAIN TAB ========== */
function renderBalancesHero(b){
  const c = cur(b.currency);
  $('#balancesDate').textContent = 'на ' + fmtDate(b.until);
  $('#sharedCurrency').textContent = b.currency;
  $('#sharedBalance').textContent = money(b.shared.balance);
  $('#sharedBalance').style.color = b.shared.balance < 0 ? '#ffbec5' : '';
  $('#sharedTopups').textContent = money(b.shared.topups) + ' ' + c;
  $('#sharedExpenses').textContent = money(b.shared.expenses) + ' ' + c;
}

function renderPersonalBalances(container, b){
  const c = cur(b.currency);
  const totalAbs = Math.abs(b.shared.topups) + Math.abs(b.shared.expenses);
  const maxAbs = Math.max(1, ...b.members.map(m => Math.abs(m.balance)));
  container.innerHTML = b.members.map(m => {
    const positive = m.balance >= 0;
    const contribPct = totalAbs > 0 ? (m.topups / (b.shared.topups || 1) * 100) : 0;
    return `<article class="balance-card ${positive ? 'pos' : 'neg'}">
      <div class="balance-head">
        <i class="balance-dot">${safe((m.name||'?')[0].toUpperCase())}</i>
        <div>
          <div class="balance-name">${safe(m.name)}</div>
          <div class="balance-sub">${m.count} операций всего</div>
        </div>
      </div>
      <div class="balance-total">${positive?'':'−'}${money(Math.abs(m.balance))} <small>${c}</small></div>
      <div class="balance-meta">
        <span>Внесено: ${money(m.topups)}</span>
        <span>Потрачено: ${money(m.expenses)}</span>
      </div>
      <div class="balance-share">Вклад: ${contribPct.toFixed(0)}% от пополнений</div>
      <div class="bar"><i style="width:${Math.abs(m.balance)/maxAbs*100}%"></i></div>
    </article>`;
  }).join('');
}

function renderMonthMini(m, w, currency){
  const c = cur(currency);
  $('#monthPeriod').textContent = fmtDate(m.from) + ' — ' + fmtDate(m.to);
  $('#monthExpenses').textContent = money(m.expenses) + ' ' + c;
  $('#monthFixed').textContent = money(m.fixed) + ' ' + c;
  $('#monthVariable').textContent = money(m.variable) + ' ' + c;
  const total = m.expenses || 1;
  $('#monthFixedShare').textContent = (m.fixed/total*100).toFixed(0) + '% от расходов';
  $('#monthVariableShare').textContent = (m.variable/total*100).toFixed(0) + '% от расходов';
  if (w) {
    $('#monthWeekHint').textContent = `за неделю: ${money(w.expenses)} ${c} · ${w.count} оп.`;
  }
}

function renderRecent(rows, currency){
  const c = cur(currency);
  if (!rows.length) {
    $('#recent').innerHTML = '<div class="empty card">Пока нет операций</div>';
    return;
  }
  // Group by date
  const byDate = {};
  rows.forEach(t => { (byDate[t.occurred_on] = byDate[t.occurred_on] || []).push(t); });
  const dates = Object.keys(byDate).sort().reverse();
  $('#recent').innerHTML = dates.map(date => `
    <div class="date-group">
      <div class="date-group-header">${fmtDateFriendly(date)}</div>
      ${byDate[date].map(t => `<article class="transaction" data-id="${t.id}">
        <i class="tx-icon ${t.kind}">${t.kind==='topup'?'↗':'↘'}</i>
        <div class="tx-main">
          <div class="tx-title">${safe(t.category)}${t.note?' · '+safe(t.note):''}</div>
          <div class="tx-meta">${safe(t.display_name)} · ${t.group==='fixed'?'обязательные':'переменные'}</div>
        </div>
        <b class="tx-amount ${t.kind}">${t.kind==='expense'?'−':'+'}${money(t.amount)}</b>
        <button class="tx-delete" aria-label="Удалить" data-delete="${t.id}">×</button>
      </article>`).join('')}
    </div>
  `).join('');
  // Bind delete buttons
  $$('#recent .tx-delete').forEach(b => b.onclick = (e) => {
    e.stopPropagation();
    deleteTransaction(Number(b.dataset.delete), b.closest('.transaction'));
  });
}

async function loadMain(){
  if (mainAbort) mainAbort.abort();
  mainAbort = new AbortController();
  setLoading($('#recent'), true);
  try {
    const d = await request('api.php?action=main', {signal: mainAbort.signal});
    mainCache = d;
    categoryGroups = d.category_groups || categoryGroups;
    currency = d.balances.currency;
    if (d.me?.name) $('#avatar').textContent = d.me.name.slice(0,1).toUpperCase();
    renderBalancesHero(d.balances);
    renderPersonalBalances($('#balancesPersonal'), d.balances);
    renderMonthMini(d.month, d.week, currency);
    renderRecent(d.recent, currency);
    renderLimits(d.limits);
    showShell();
  } catch(e) {
    if (e.name === 'AbortError') return;
    if (/Откройте сайт|Telegram|доступ/i.test(e.message)) showLogin();
    else notice(e.message);
  }
}

/* ========== REPORTS ========== */
let rangeCal = null;
let rangePicking = 'from';

function openRangeCalendar(target){
  rangePicking = target;
  const modal = $('#rangeCalModal');
  modal.hidden = false;
  $('#rangeCalHint').textContent = target === 'from' ? 'Выберите дату начала' : 'Выберите дату конца';
  rangeCal = buildCalendar($('#rangeCalDays'), $('#rangeCalTitle'), {
    mode: 'range',
    initial: (target === 'from' ? reportState.from : reportState.to) || todayISO(),
    rangeFrom: reportState.from,
    rangeTo: reportState.to,
    prevBtn: $('#rangeCalPrev'),
    nextBtn: $('#rangeCalNext'),
    onRangeChange(f, t){
      if (f) reportState.from = f;
      if (t) reportState.to = t;
      $('#rangeCalHint').textContent = f && t ? `${fmtDate(f)} — ${fmtDate(t)}` : (f ? 'Теперь выберите дату конца' : 'Выберите дату начала');
      $('#rangeCalApply').disabled = !(f && t);
    }
  });
}
function closeRangeCalendar(){ $('#rangeCalModal').hidden = true; rangeCal = null; }
$$('#rangeCalModal [data-range-close]').forEach(el => el.onclick = closeRangeCalendar);
$('#rangeCalCancel').onclick = closeRangeCalendar;
$('#rangeCalApply').onclick = () => {
  const [f, t] = rangeCal.getRange();
  if (f && t) {
    reportState.from = f; reportState.to = t; reportState.page = 1;
    $$('#presets button').forEach(x => x.classList.remove('selected'));
    updateRangeLabels();
    closeRangeCalendar();
    loadReport().catch(()=>{});
  }
};

function updateRangeLabels(){
  $('#dateFromLabel').textContent = reportState.from ? fmtDate(reportState.from) : '—';
  $('#dateToLabel').textContent = reportState.to ? fmtDate(reportState.to) : '—';
}

$('#dateFromBtn').onclick = () => openRangeCalendar('from');
$('#dateToBtn').onclick = () => openRangeCalendar('to');
$('#applyRange').onclick = () => {
  if (!reportState.from || !reportState.to) { notice('Выберите обе даты'); return; }
  $$('#presets button').forEach(x => x.classList.remove('selected'));
  reportState.page = 1;
  loadReport().catch(()=>{});
};

function renderReport(d){
  reportCache = d;
  categoryGroups = d.category_groups || categoryGroups;
  currency = d.summary.currency;
  const c = cur(currency);
  $('#reportCurrency').textContent = currency;
  $('#reportPeriod').textContent = fmtDate(d.summary.from) + ' — ' + fmtDate(d.summary.to);
  $('#reportExpenses').textContent = money(d.summary.expenses);
  $('#reportTopups').textContent = money(d.summary.topups) + ' ' + c;
  const sign = d.summary.balance_change > 0 ? '+' : '';
  $('#reportChange').textContent = sign + money(d.summary.balance_change) + ' ' + c;
  $('#reportChange').style.color = d.summary.balance_change < 0 ? '#ffbec5' : '#c6f3df';
  $('#reportCount').textContent = d.summary.count;
  reportState.from = d.from; reportState.to = d.to;
  updateRangeLabels();

  const p = d.previous;
  const delta = (cur, prev) => prev > 0 ? ((cur - prev) / prev * 100) : (cur > 0 ? 100 : 0);
  const arrow = v => v > 0 ? '↑' : v < 0 ? '↓' : '→';
  const cls = v => v > 0 ? 'up' : v < 0 ? 'down' : 'flat';
  const dExp = delta(d.summary.expenses, p.expenses);
  const dFix = delta(d.summary.fixed, p.fixed);
  const dVar = delta(d.summary.variable, p.variable);
  $('#comparison').innerHTML = `
    <button type="button" class="cmp-row clickable" data-drill="prev-all" data-from="${p.from}" data-to="${p.to}" data-group="all">
      <span>Прошлый период</span><b>${fmtDate(p.from)} — ${fmtDate(p.to)} <i class="drill-arrow">›</i></b>
    </button>
    <button type="button" class="cmp-row clickable" data-drill="prev-expenses" data-from="${p.from}" data-to="${p.to}" data-group="all">
      <span>Расходы тогда</span><b>${money(p.expenses)} ${c} <i class="drill-arrow">›</i></b>
    </button>
    <div class="cmp-row"><span>Изменение расходов</span><b class="${cls(dExp)}">${arrow(dExp)} ${Math.abs(dExp).toFixed(0)}%</b></div>
    <button type="button" class="cmp-row clickable" data-drill="prev-fixed" data-from="${p.from}" data-to="${p.to}" data-group="fixed">
      <span>Обязательные тогда</span><b class="${cls(dFix)}">${arrow(dFix)} ${Math.abs(dFix).toFixed(0)}% · ${money(p.fixed)} ${c} <i class="drill-arrow">›</i></b>
    </button>
    <button type="button" class="cmp-row clickable" data-drill="prev-variable" data-from="${p.from}" data-to="${p.to}" data-group="variable">
      <span>Переменные тогда</span><b class="${cls(dVar)}">${arrow(dVar)} ${Math.abs(dVar).toFixed(0)}% · ${money(p.variable)} ${c} <i class="drill-arrow">›</i></b>
    </button>
    <button type="button" class="cmp-row clickable" data-drill="cur-fixed" data-from="${d.from}" data-to="${d.to}" data-group="fixed">
      <span>Обязательные сейчас</span><b>${money(d.summary.fixed)} ${c} <i class="drill-arrow">›</i></b>
    </button>
    <button type="button" class="cmp-row clickable" data-drill="cur-variable" data-from="${d.from}" data-to="${d.to}" data-group="variable">
      <span>Переменные сейчас</span><b>${money(d.summary.variable)} ${c} <i class="drill-arrow">›</i></b>
    </button>
  `;
  $$('#comparison .clickable').forEach(b => b.onclick = () => {
    const titles = {
      'prev-all': 'Прошлый период · все расходы',
      'prev-expenses': 'Прошлый период · расходы',
      'prev-fixed': 'Прошлый период · обязательные',
      'prev-variable': 'Прошлый период · переменные',
      'cur-fixed': 'Текущий период · обязательные',
      'cur-variable': 'Текущий период · переменные',
    };
    openDrill({from: b.dataset.from, to: b.dataset.to, group: b.dataset.group, title: titles[b.dataset.drill] || 'Детализация'});
  });

  const total = d.summary.expenses || 1;
  $('#groups').innerHTML = `
    <div class="group-row fixed">
      <div class="group-info"><span>Обязательные</span><strong>${money(d.summary.fixed)} ${c}</strong></div>
      <div class="cat-track"><i style="width:${d.summary.fixed/total*100}%"></i></div>
      <div class="group-sub">${(d.summary.fixed/total*100).toFixed(0)}% от расходов периода</div>
    </div>
    <div class="group-row variable">
      <div class="group-info"><span>Переменные</span><strong>${money(d.summary.variable)} ${c}</strong></div>
      <div class="cat-track"><i style="width:${d.summary.variable/total*100}%"></i></div>
      <div class="group-sub">${(d.summary.variable/total*100).toFixed(0)}% от расходов периода</div>
    </div>
  `;

  renderPersonalBalances($('#reportBalances'), d.balances);

  const maxMember = Math.max(1, ...d.summary.members.map(m=>Number(m.expenses)));
  $('#reportMembers').innerHTML = d.summary.members.map(m => {
    const topCats = Object.entries(m.categories || {}).slice(0,3);
    return `<article class="person">
      <div class="person-head">
        <i class="person-dot">${safe((m.name||'?')[0].toUpperCase())}</i>
        <span class="person-name">${safe(m.name)}</span>
      </div>
      <div class="person-val">${money(m.expenses)} <small>${c}</small></div>
      <div class="person-meta">${m.count} записей · пополнения ${money(m.topups)}</div>
      <div class="person-meta">обяз. ${money(m.fixed)} · перем. ${money(m.variable)}</div>
      ${topCats.length ? `<div class="person-cats">${topCats.map(([n,v])=>`<span class="chip">${safe(n)} <b>${money(v)}</b></span>`).join('')}</div>` : ''}
      <div class="bar"><i style="width:${m.expenses/maxMember*100}%"></i></div>
    </article>`;
  }).join('');

  const entries = Object.entries(d.summary.categories || {}).filter(([_,v]) => reportState.filter === 'all' || v.group === reportState.filter);
  const maxCat = Math.max(1, ...entries.map(x=>Number(x[1].total)));
  $('#reportCategories').innerHTML = entries.length ? entries.map(([n,v]) => `<div class="category-row">
    <div class="category-info">
      <span>${safe(n)} <i class="cat-badge ${v.group}">${v.group==='fixed'?'обяз':'перем'}</i></span>
      <strong>${money(v.total)} ${c}</strong>
    </div>
    <div class="cat-track"><i style="width:${v.total/maxCat*100}%"></i></div>
    <div class="cat-sub">${v.count} операций · ${(v.total/(d.summary.expenses||1)*100).toFixed(0)}% расходов</div>
  </div>`).join('') : '<div class="empty">Нет категорий за период</div>';

  renderChart(d.daily, c);
  renderReportHistory(d.transactions, c);
  renderPagination(d.pagination);
}

function renderChart(daily, c){
  if (!daily || !daily.length) { $('#chart').innerHTML = '<div class="empty">Нет данных</div>'; return; }
  const max = Math.max(1, ...daily.map(d => Math.max(d.expense, d.topup)));
  const maxLabels = Math.max(5, Math.floor(($('#chart').clientWidth || 320) / 34));
  const step = Math.max(1, Math.ceil(daily.length / maxLabels));
  $('#chart').innerHTML = `<div class="chart-bars">${daily.map((d,i) => {
    const h = d.expense / max * 100;
    const show = i % step === 0 || i === daily.length - 1;
    return `<div class="chart-col" title="${d.date}: расход ${money(d.expense)}, пополнение ${money(d.topup)}">
      <i style="height:${Math.max(2,h)}%"></i><span>${show ? d.date.slice(8) : ''}</span>
    </div>`;
  }).join('')}</div>
  <div class="chart-legend"><span><i class="dot expense"></i>Расходы по дням</span><span>Максимум: ${money(max)} ${c}</span></div>`;
}

function renderReportHistory(rows, c){
  if (!rows.length) {
    $('#reportHistory').innerHTML = '<div class="empty card">За период нет операций</div>';
    return;
  }
  const byDate = {};
  rows.forEach(t => { (byDate[t.occurred_on] = byDate[t.occurred_on] || []).push(t); });
  const dates = Object.keys(byDate).sort().reverse();
  $('#reportHistory').innerHTML = dates.map(date => `
    <div class="date-group">
      <div class="date-group-header">${fmtDateFriendly(date)}</div>
      ${byDate[date].map(t => `<article class="transaction" data-id="${t.id}">
        <i class="tx-icon ${t.kind}">${t.kind==='topup'?'↗':'↘'}</i>
        <div class="tx-main">
          <div class="tx-title">${safe(t.category)}${t.note?' · '+safe(t.note):''}</div>
          <div class="tx-meta">${safe(t.display_name)} · ${t.group==='fixed'?'обязательные':'переменные'}</div>
        </div>
        <b class="tx-amount ${t.kind}">${t.kind==='expense'?'−':'+'}${money(t.amount)}</b>
        <button class="tx-delete" aria-label="Удалить" data-delete="${t.id}">×</button>
      </article>`).join('')}
    </div>
  `).join('');
  $$('#reportHistory .tx-delete').forEach(b => b.onclick = (e) => {
    e.stopPropagation();
    deleteTransaction(Number(b.dataset.delete), b.closest('.transaction'));
  });
}

function renderPagination(p){
  const el = $('#pagination');
  if (p.pages <= 1) { el.innerHTML = ''; return; }
  let html = '';
  if (p.page > 1) html += `<button data-page="${p.page-1}">← Назад</button>`;
  html += `<span>Страница ${p.page} из ${p.pages} · всего ${p.total}</span>`;
  if (p.page < p.pages) html += `<button data-page="${p.page+1}">Вперёд →</button>`;
  el.innerHTML = html;
  $$('#pagination button').forEach(b => b.onclick = () => { reportState.page = Number(b.dataset.page); loadReport().catch(()=>{}); });
}

function buildReportUrl(){
  const u = new URL('api.php', location.href);
  u.searchParams.set('action','report');
  if (reportState.from && reportState.to) {
    u.searchParams.set('from', reportState.from);
    u.searchParams.set('to', reportState.to);
  } else {
    u.searchParams.set('preset', reportState.preset);
  }
  u.searchParams.set('page', reportState.page);
  u.searchParams.set('limit', 50);
  return u.toString();
}

async function loadReport(){
  if (reportAbort) reportAbort.abort();
  reportAbort = new AbortController();
  try {
    const d = await request(buildReportUrl(), {signal: reportAbort.signal});
    renderReport(d);
    showShell();
  } catch(e) {
    if (e.name === 'AbortError') return;
    if (/Откройте сайт|Telegram|доступ/i.test(e.message)) showLogin();
    else notice(e.message);
  }
}

/* ========== DRILL-DOWN ========== */
let drillState = {from:null, to:null, group:'all', rootGroup:'all', rootTitle:'Детализация', category:'', view:'categories', data:null};

function openDrill(opts){
  const group = opts.group || 'all';
  const title = opts.title || 'Детализация';
  const view = opts.view === 'transactions' ? 'transactions' : 'categories';
  drillState = {from: opts.from, to: opts.to, group, rootGroup:group, rootTitle:title, category: opts.category || '', view, data: null};
  $('#drillTitle').textContent = title;
  const sheet = $('#drillSheet');
  sheet.hidden = false;
  sheet.classList.remove('closing');
  document.body.style.overflow = 'hidden';
  $$('#drillTabs button').forEach(b => b.classList.toggle('selected', b.dataset.view === view));
  $('#drillCategories').hidden = view !== 'categories';
  $('#drillTransactions').hidden = view !== 'transactions';
  $('#drillSummary').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  $('#drillCategories').innerHTML = '';
  $('#drillTransactions').innerHTML = '';
  loadDrill().catch(()=>{});
}

async function closeDrill(){
  document.body.style.overflow = '';
  await animateClose($('#drillSheet'));
}

async function loadDrill(){
  if (drillAbort) drillAbort.abort();
  drillAbort = new AbortController();
  try {
    const u = new URL('api.php', location.href);
    u.searchParams.set('action','drilldown');
    u.searchParams.set('from', drillState.from);
    u.searchParams.set('to', drillState.to);
    if (drillState.group !== 'all') u.searchParams.set('group', drillState.group);
    if (drillState.category) u.searchParams.set('category', drillState.category);
    const d = await request(u.toString(), {signal: drillAbort.signal});
    drillState.data = d;
    renderDrill();
  } catch(e){
    if (e.name === 'AbortError') return;
    notice(e.message); closeDrill();
  }
}

function renderDrill(){
  const d = drillState.data;
  if (!d) return;
  const c = cur(currency);
  const groupLabel = d.group === 'fixed' ? 'обязательные' : d.group === 'variable' ? 'переменные' : 'все';
  $('#drillSummary').innerHTML = `
    <div class="drill-sum-row"><span>Период</span><b>${fmtDate(d.from)} — ${fmtDate(d.to)}</b></div>
    <div class="drill-sum-row"><span>Фильтр</span><b>${groupLabel}${d.category ? ' · ' + safe(d.category) : ''}</b></div>
    <div class="drill-sum-row"><span>Расходы</span><b class="total">${money(d.total)} ${c}</b></div>
    <div class="drill-sum-row"><span>Операций</span><b>${d.count}</b></div>
  `;
  $('#drillAllCats').hidden = !drillState.category;
  renderDrillCategories();
  renderDrillTransactions();
}

function renderDrillCategories(){
  const d = drillState.data;
  if (!d) return;
  const c = cur(currency);
  const max = Math.max(1, ...d.categories.map(x => x.total));
  $('#drillCategories').classList.toggle('filtered', Boolean(drillState.category));
  $('#drillCategories').innerHTML = d.categories.length ? d.categories.map(cat => `
    <button type="button" class="drill-cat" data-cat="${safe(cat.category)}" data-group="${cat.group}">
      <div class="drill-cat-head">
        <span class="drill-cat-name">${safe(cat.category)} <i class="cat-badge ${cat.group}">${cat.group==='fixed'?'обяз':'перем'}</i></span>
        <b class="drill-cat-total">${money(cat.total)} ${c}</b>
      </div>
      <div class="cat-track"><i style="width:${cat.total/max*100}%"></i></div>
      <div class="drill-cat-sub">${cat.count} операций · ${(cat.total/(d.total||1)*100).toFixed(0)}%</div>
    </button>
  `).join('') : '<div class="empty">Нет категорий за период</div>';
  $$('#drillCategories .drill-cat').forEach(b => b.onclick = () => {
    drillState.category = b.dataset.cat;
    drillState.group = b.dataset.group;
    drillState.view = 'transactions';
    $('#drillTitle').textContent = b.dataset.cat;
    $$('#drillTabs button').forEach(x => x.classList.toggle('selected', x.dataset.view === 'transactions'));
    $('#drillCategories').hidden = true;
    $('#drillTransactions').hidden = false;
    loadDrill().catch(()=>{});
  });
}

function renderDrillTransactions(){
  const d = drillState.data;
  if (!d) return;
  const c = cur(currency);
  const byDate = {};
  d.transactions.forEach(t => { (byDate[t.date] = byDate[t.date] || []).push(t); });
  const dates = Object.keys(byDate).sort().reverse();
  $('#drillTransactions').innerHTML = dates.length ? dates.map(date => `
    <div class="drill-date-group">
      <div class="drill-date-head">${fmtDate(date)} <span>${money(byDate[date].reduce((s,t)=>s+t.amount,0))} ${c}</span></div>
      ${byDate[date].map(t => `<div class="drill-tx-row">
        <div class="drill-tx-main">
          <div class="drill-tx-cat">${safe(t.category)}</div>
          <div class="drill-tx-note">${t.note ? safe(t.note) + ' · ' : ''}${safe(t.who)}</div>
        </div>
        <b class="drill-tx-amount">−${money(t.amount)}</b>
      </div>`).join('')}
    </div>
  `).join('') : '<div class="empty">Нет операций за период</div>';
}

$$('#drillSheet [data-drill-close]').forEach(el => el.onclick = () => closeDrill());
$('#drillAllCats').onclick = () => {
  drillState.category = '';
  drillState.group = drillState.rootGroup;
  drillState.view = 'categories';
  $('#drillTitle').textContent = drillState.rootTitle;
  $$('#drillTabs button').forEach(x => x.classList.toggle('selected', x.dataset.view === 'categories'));
  $('#drillCategories').hidden = false;
  $('#drillTransactions').hidden = true;
  loadDrill().catch(()=>{});
};
$$('#drillTabs button').forEach(b => b.onclick = () => {
  drillState.view = b.dataset.view;
  $$('#drillTabs button').forEach(x => x.classList.toggle('selected', x === b));
  $('#drillCategories').hidden = drillState.view !== 'categories';
  $('#drillTransactions').hidden = drillState.view !== 'transactions';
});

/* ========== LIMITS ========== */
// Monthly limits are shared by the family and never block an entry: they only inform.
const TOTAL_LIMIT = '*';
let limitsStatus = null;

function limitTail(i){
  if (i.remaining > 0) return `осталось ${money(i.remaining)} ${cur(currency)}`;
  if (i.remaining === 0) return 'лимит исчерпан';
  return `превышен на ${money(-i.remaining)} ${cur(currency)}`;
}

function renderLimits(status){
  limitsStatus = status;
  const el = $('#limitsList');
  if (!status || !status.items.length) {
    el.innerHTML = `<div class="limits-empty">
      <p>Задайте лимиты на месяц — например, на кафе или покупки. Бот предупредит обоих, когда останется мало.</p>
      <button type="button" class="limits-empty-btn" data-open-profile>Настроить лимиты</button>
    </div>`;
    el.querySelector('[data-open-profile]').onclick = openProfile;
    return;
  }
  const c = cur(currency);
  el.innerHTML = status.items.map(i => `
    <button type="button" class="limit-item ${i.state}" data-cat="${safe(i.category)}" aria-label="${safe(i.label)}: ${limitTail(i)}">
      <div class="limit-top">
        <span class="limit-name">${i.category === TOTAL_LIMIT ? '<i class="dot-mark total"></i>' : `<i class="dot-mark ${i.group}"></i>`}${safe(i.label)}</span>
        <span class="limit-amount">${money(i.spent)} <small>/ ${money(i.limit)} ${c}</small></span>
      </div>
      <div class="limit-track"><i style="width:${Math.min(100, i.percent)}%"></i></div>
      <div class="limit-sub"><span class="limit-left">${limitTail(i)}</span><span>${Math.round(i.percent)}%</span></div>
    </button>`).join('');
  $$('#limitsList .limit-item').forEach(b => b.onclick = () => {
    const cat = b.dataset.cat;
    const total = cat === TOTAL_LIMIT;
    openDrill({from: status.from, to: status.to, group: 'all', category: total ? '' : cat, view: total ? 'categories' : 'transactions',
      title: total ? 'Все расходы за месяц' : cat});
  });
}

function limitFor(cat){ return limitsStatus?.items.find(i => i.category === cat) || null; }

// Live hint in the new-entry sheet: what the limit looks like after this expense
function updateLimitHint(){
  const el = $('#limitHint');
  const thisMonth = sheetState.date.slice(0, 7) === todayISO().slice(0, 7);
  if (sheetState.kind !== 'expense' || !sheetState.category || !thisMonth) { el.hidden = true; return; }
  const amount = Number(sheetState.amount) || 0;
  const lines = [];
  let worst = 'ok';
  const rank = {ok:0, warn:1, over:2};
  for (const item of [limitFor(sheetState.category), limitFor(TOTAL_LIMIT)]) {
    if (!item) continue;
    const after = item.remaining - amount;
    const warnEdge = item.limit * (limitsStatus.warn_percent / 100);
    const state = after <= 0 ? 'over' : after < warnEdge ? 'warn' : 'ok';
    if (rank[state] > rank[worst]) worst = state;
    const name = item.category === TOTAL_LIMIT ? 'Общий лимит' : `Лимит «${safe(item.label)}»`;
    let text;
    if (!amount) text = item.remaining >= 0 ? `осталось ${money(item.remaining)} из ${money(item.limit)} ${cur(currency)}` : `уже превышен на ${money(-item.remaining)} ${cur(currency)}`;
    else if (after >= 0) text = `после траты останется ${money(after)} из ${money(item.limit)} ${cur(currency)}`;
    else text = `будет превышен на ${money(-after)} ${cur(currency)}`;
    lines.push(`<div><b>${name}:</b> ${text}</div>`);
  }
  if (!lines.length) { el.hidden = true; return; }
  if (worst === 'over') lines.push('<div class="limit-hint-note">Запись всё равно сохранится — лимит просто уйдёт в минус.</div>');
  el.className = 'limit-hint ' + worst;
  el.innerHTML = lines.join('');
  el.hidden = false;
}

function limitNoticeAfterSave(items){
  const bad = (items || []).filter(i => i.state !== 'ok').sort((a, b) => b.percent - a.percent)[0];
  if (!bad) return null;
  const name = bad.category === TOTAL_LIMIT ? 'Общий лимит' : `Лимит «${bad.label}»`;
  if (bad.remaining < 0) return `${name} превышен на ${money(-bad.remaining)} ${cur(currency)}`;
  if (bad.remaining === 0) return `${name} исчерпан`;
  return `${name}: осталось ${money(bad.remaining)} ${cur(currency)}`;
}

/* ========== PROFILE ========== */
let profileInitial = '';
let profileWarn = 20;

function digitsOnly(v){ return String(v || '').replace(/\D/g, '').slice(0, 9); }

function profileSnapshot(){
  const values = $$('#limitsForm input[data-cat]').map(i => [i.dataset.cat, digitsOnly(i.value)]);
  return JSON.stringify([profileWarn, values]);
}

function profileDirty(){ return profileInitial !== '' && profileSnapshot() !== profileInitial; }

function syncProfileSave(){ $('#limitsSave').disabled = !profileDirty(); }

async function openProfile(){
  const sheet = $('#profileSheet');
  sheet.hidden = false;
  sheet.classList.remove('closing');
  document.body.style.overflow = 'hidden';
  const me = mainCache?.me;
  $('#profileName').textContent = me?.name || '—';
  $('#profileDot').textContent = (me?.name || '₸').slice(0, 1).toUpperCase();
  $('#limitsForm').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  $('#warnToggle').innerHTML = '';
  $('#limitsSave').disabled = true;
  profileInitial = '';
  try {
    const d = await request('api.php?action=limits');
    renderProfileForm(d);
  } catch(e) { notice(e.message); closeProfile(true); }
}

function renderProfileForm(d){
  const c = cur(currency);
  const spentBy = {};
  d.status.items.forEach(i => { spentBy[i.category] = i; });
  profileWarn = d.status.warn_percent;
  $('#warnToggle').innerHTML = d.warn_options.map(p => `<button type="button" data-warn="${p}" class="${p === profileWarn ? 'selected' : ''}">${p}%</button>`).join('');
  $$('#warnToggle button').forEach(b => b.onclick = () => {
    profileWarn = Number(b.dataset.warn);
    $$('#warnToggle button').forEach(x => x.classList.toggle('selected', x === b));
    syncProfileSave();
  });

  const row = (cat, label, group) => {
    const item = spentBy[cat];
    const value = item ? money(item.limit) : '';
    const sub = item ? `потрачено ${money(item.spent)} ${c} · ${limitTail(item)}` : 'без лимита';
    return `<label class="limit-row ${item ? 'has-limit' : ''}">
      <span class="limit-row-name"><span><i class="dot-mark ${group}"></i>${safe(label)}</span><small>${sub}</small></span>
      <span class="limit-input"><input type="text" inputmode="numeric" autocomplete="off" placeholder="—" data-cat="${safe(cat)}" value="${value}" aria-label="Лимит: ${safe(label)}"><b>${c}</b></span>
    </label>`;
  };
  const groups = {fixed: [], variable: []};
  d.categories.forEach(x => (groups[x.group] || groups.variable).push(x.category));
  $('#limitsForm').innerHTML =
    row(TOTAL_LIMIT, 'Все расходы за месяц', 'total') +
    `<div class="limit-group-title">Обязательные</div>` + groups.fixed.map(n => row(n, n, 'fixed')).join('') +
    `<div class="limit-group-title">Переменные</div>` + groups.variable.map(n => row(n, n, 'variable')).join('');
  $$('#limitsForm input[data-cat]').forEach(inp => inp.oninput = () => {
    const v = digitsOnly(inp.value);
    inp.value = v ? money(Number(v)) : '';
    inp.closest('.limit-row').classList.toggle('has-limit', Boolean(v));
    syncProfileSave();
  });
  profileInitial = profileSnapshot();
  syncProfileSave();
}

async function saveProfile(){
  const btn = $('#limitsSave');
  const limits = {};
  $$('#limitsForm input[data-cat]').forEach(i => { const v = digitsOnly(i.value); if (v && Number(v) > 0) limits[i.dataset.cat] = Number(v); });
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner small"></div> Сохранение…';
  try {
    const r = await request('api.php?action=limits', {method: 'POST', body: JSON.stringify({limits, warn_percent: profileWarn})});
    renderLimits(r.status);
    notice(r.changed ? 'Лимиты сохранены — бот сообщит об изменениях' : 'Без изменений', true);
    await closeProfile(true);
  } catch(e) { notice(e.message); btn.disabled = false; }
  finally { btn.textContent = 'Сохранить лимиты'; }
}

async function closeProfile(force = false){
  if (!force && profileDirty()) {
    const ok = await showConfirm('Лимиты не сохранены. Закрыть без сохранения?');
    if (!ok) return;
  }
  profileInitial = '';
  document.body.style.overflow = '';
  await animateClose($('#profileSheet'));
}

$('#avatar').onclick = openProfile;
$('#limitsEdit').onclick = openProfile;
$('#limitsSave').onclick = saveProfile;
$$('#profileSheet [data-profile-close]').forEach(el => el.onclick = () => closeProfile());

/* ========== INIT ========== */
tg?.ready(); tg?.expand();

if (localDev) {
  $('#testUser').hidden = false;
  $$('#testUser button').forEach(b => {
    b.classList.toggle('selected', b.dataset.user === testUserId);
    b.onclick = () => {
      testUserId = b.dataset.user;
      localStorage.setItem('fb_test_user', testUserId);
      $$('#testUser button').forEach(x => x.classList.toggle('selected', x === b));
      mainCache = null; reportCache = null;
      ($('#tab-main').hidden ? loadReport() : loadMain()).catch(()=>{});
    };
  });
  $$('[data-local]').forEach(a => a.onclick = e => { e.preventDefault(); testUserId = a.dataset.local; localStorage.setItem('fb_test_user', testUserId); $$('#testUser button').forEach(x => x.classList.toggle('selected', x.dataset.user === testUserId)); mainCache = null; loadMain().catch(()=>{}); });
}

if (tg?.initDataUnsafe?.user) $('#avatar').textContent = (tg.initDataUnsafe.user.first_name || 'М').slice(0,1).toUpperCase();

$$('.main-tabs button').forEach(b => b.onclick = () => switchTab(b.dataset.tab));
$$('#presets button').forEach(b => b.onclick = () => {
  $$('#presets button').forEach(x => x.classList.toggle('selected', x === b));
  reportState.preset = b.dataset.preset;
  reportState.from = null;
  reportState.to = null;
  reportState.page = 1;
  loadReport().catch(()=>{});
});
$$('#categoryFilter button').forEach(b => b.onclick = () => {
  $$('#categoryFilter button').forEach(x => x.classList.toggle('selected', x === b));
  reportState.filter = b.dataset.filter;
  if (reportCache) renderReport(reportCache);
});

$('#export').onclick = async () => {
  try {
    const u = new URL('api.php', location.href);
    u.searchParams.set('action','export');
    if (reportCache) { u.searchParams.set('from', reportCache.from); u.searchParams.set('to', reportCache.to); }
    const r = await fetch(u.toString(), {headers: headers(), credentials: 'same-origin'});
    if (!r.ok) throw Error('Не удалось скачать CSV');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(await r.blob());
    a.download = `family-budget-${reportCache?.from||'all'}-${reportCache?.to||'all'}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch(e) { notice(e.message); }
};

bindSheet();
switchTab('main');
})();
