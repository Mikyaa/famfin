(()=>{
const tg = window.Telegram?.WebApp;
// Inside Telegram the Mini App gets native buttons and haptics; in a browser these are no-ops
const inTelegram = Boolean(tg?.initData);
function haptic(kind = 'light'){
  const h = inTelegram && tg.HapticFeedback;
  if (!h) return;
  try {
    if (['success', 'warning', 'error'].includes(kind)) h.notificationOccurred(kind);
    else if (kind === 'select') h.selectionChanged();
    else h.impactOccurred(kind);
  } catch {}
}
const tgMain = {
  handler: null,
  show(text, fn){
    if (!inTelegram || !tg.MainButton) return;
    this.hide();
    this.handler = fn;
    const color = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#2A62EC';
    tg.MainButton.setParams({text, color, text_color: '#ffffff', is_active: true, is_visible: true});
    tg.MainButton.onClick(fn);
    document.body.classList.add('tg-main');
  },
  hide(){
    if (!inTelegram || !tg.MainButton) return;
    if (this.handler) tg.MainButton.offClick(this.handler);
    this.handler = null;
    tg.MainButton.hide();
    document.body.classList.remove('tg-main');
  },
  progress(on){ if (inTelegram && tg.MainButton) on ? tg.MainButton.showProgress(false) : tg.MainButton.hideProgress(); },
};
const localDev = window.LOCAL_DEV === true;
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const symbols = {KZT:'₸',RUB:'₽',USD:'$',EUR:'€'};
let testUserId = localStorage.getItem('fb_test_user') || '854102139';
let categoryGroups = {fixed:[],variable:[]};
let currency = 'KZT';
// preset: month|week|year|all|custom; anchor: any date inside the shown period; from/to only for custom
let reportState = {preset:'month', anchor:null, from:null, to:null, page:1, filter:'all'};
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

/* ========== CODE AUTH (OTP) ========== */
// Six slots mirror one real input (so paste and iOS code autofill work). On submit the
// slots collapse into a stack, a checking badge pulses, then a check mark draws or the
// slots come back with a shake.
const wait = ms => new Promise(r => setTimeout(r, ms));
let otpBound = false;

function bindCodeAuth(){
  const input = $('#codeInput');
  const btn = $('#codeSubmit');
  if (!input || otpBound) return;
  otpBound = true;
  const card = $('#otpCard');
  const stage = $('#otpStage');
  const slots = $$('#otpStage .otp-slot');
  const status = $('#otpStatus');
  let busy = false;

  const setStatus = text => { status.textContent = text; };
  function paint(popIndex = -1){
    const v = input.value;
    slots.forEach((slot, i) => {
      const span = slot.querySelector('span');
      span.textContent = v[i] || '';
      span.classList.toggle('pop', i === popIndex);
      slot.classList.toggle('active', i === Math.min(v.length, 5) && document.activeElement === input);
    });
    btn.disabled = v.length !== 6 || busy;
  }

  input.addEventListener('input', () => {
    if (busy) return;
    const before = input.value;
    input.value = before.replace(/\D/g, '').slice(0, 6);
    card.classList.remove('is-error');
    setStatus(input.value.length ? `Введено ${input.value.length} из 6` : 'Ожидаю код');
    paint(input.value.length - 1);
    if (input.value.length === 6) submitCode();
  });
  input.addEventListener('focus', () => { stage.classList.add('is-focused'); paint(); });
  input.addEventListener('blur', () => { stage.classList.remove('is-focused'); paint(); });
  input.addEventListener('keydown', e => { if (e.key === 'Enter') submitCode(); });
  btn.onclick = submitCode;
  paint();

  async function submitCode(){
    const code = input.value;
    if (busy || code.length !== 6) return;
    busy = true;
    paint();
    input.blur();
    card.classList.remove('is-error', 'is-verified', 'is-checking');
    stage.classList.add('is-collapsing');
    setStatus('Код получен');
    const request = fetch('auth.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({code}), credentials: 'same-origin',
    }).then(async r => ({ok: r.ok, d: await r.json().catch(() => ({}))})).catch(() => ({ok: false, d: {error: 'Нет связи. Попробуйте ещё раз.'}}));
    await wait(480);
    card.classList.add('is-checking');
    setStatus('Проверяю код…');
    const [res] = await Promise.all([request, wait(500)]);
    card.classList.remove('is-checking');
    if (res.ok && res.d.ok) {
      card.classList.add('is-verified');
      setStatus(`Добро пожаловать${res.d.name ? ', ' + res.d.name : ''}`);
      $('#otpNote').textContent = 'Открываю бюджет…';
      await wait(900);
      location.reload();
      return;
    }
    stage.classList.remove('is-collapsing');
    card.classList.add('is-error');
    setStatus(res.d.error || 'Неверный код');
    input.value = '';
    busy = false;
    paint();
    input.focus();
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
// Button labels follow the question: «Удалить …?» → «Удалить» / «Отмена»
const CONFIRM_VERBS = {'Удалить': 'Удалить', 'Убрать': 'Убрать', 'Закрыть': 'Закрыть', 'Завершить': 'Завершить', 'Записать': 'Записать', 'Всегда': 'Запомнить', 'Сбросить': 'Сбросить'};
function showConfirm(text, okLabel = null){
  return new Promise(resolve => {
    const modal = $('#confirmModal');
    $('#confirmText').textContent = text;
    const unsaved = /^(Данные|Изменения) не сохранены/.test(text);
    $('#confirmOk').textContent = okLabel || (unsaved ? 'Выйти' : CONFIRM_VERBS[text.split(/[\s,]/)[0]] || 'Продолжить');
    $('#confirmCancel').textContent = unsaved ? 'Остаться' : 'Отмена';
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

/* ========== SHEET: ENTRY (add, edit, transfer) ========== */
const sheetState = {mode:'add', editId:null, kind:'expense', amount:'', category:null, group:'variable', date:todayISO(), dateMode:'today', note:'', payerId:null, from:null, to:null, account:'', currency:'KZT', split:'', photoData:null, hasPhoto:false, prevCategory:null};
// Foreign amounts are converted at the National Bank rate on the server; the sheet shows the estimate
const FX_ORDER = ['KZT', 'USD', 'EUR', 'RUB'];
const fxRate = c => c === 'KZT' ? 1 : Number(mainCache?.fx?.[c] || 0);
const sheetAmountKzt = () => (Number(sheetState.amount) || 0) * (fxRate(sheetState.currency) || 0);
const fmtCur = (v, c) => `${moneyDec(v)} ${cur(c || 'KZT')}`;
let afterSaveHook = null;
function updateFxHint(){
  const el = $('#fxHint');
  if (!el) return;
  const c = sheetState.currency;
  if (c === 'KZT') { el.hidden = true; return; }
  const r = fxRate(c);
  el.hidden = false;
  el.textContent = !r ? 'Нет курса — попробуйте позже' : (Number(sheetState.amount) ? `≈ ${money(sheetAmountKzt())} ₸ · ` : '') + `курс Нацбанка: 1 ${cur(c)} = ${String(r).replace('.', ',')} ₸`;
}
let sheetCal = null;
let sheetInitial = '';
const familyMembers = () => mainCache?.members || [];
const myId = () => Number(mainCache?.me?.id || 0);

function sheetSnapshot(){
  return JSON.stringify([sheetState.kind, sheetState.amount, sheetState.category, sheetState.date, ($('#noteInput')?.value || '').trim(), sheetState.payerId, sheetState.from]);
}
function sheetHasData(){ return sheetInitial !== '' && sheetSnapshot() !== sheetInitial; }

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
    haptic('select');
    renderCatGrid();
  });
  updateLimitHint();
}

function renderTransferDir(){
  const ms = familyMembers();
  const pairs = [];
  ms.forEach(a => ms.forEach(b => { if (a.id !== b.id) pairs.push([a, b]); }));
  $('#transferDir').innerHTML = pairs.map(([a, b]) => `<button type="button" data-from="${a.id}" data-to="${b.id}" class="${a.id === sheetState.from && b.id === sheetState.to ? 'selected' : ''}">${safe(a.name)} → ${safe(b.name)}</button>`).join('');
  $$('#transferDir button').forEach(b => b.onclick = () => {
    sheetState.from = Number(b.dataset.from); sheetState.to = Number(b.dataset.to);
    haptic('select');
    renderTransferDir();
  });
}

function renderPayerToggle(){
  $('#payerToggle').innerHTML = familyMembers().map(m => `<button type="button" data-id="${m.id}" class="${m.id === sheetState.payerId ? 'selected' : ''}">${safe(m.name)}</button>`).join('');
  $$('#payerToggle button').forEach(b => b.onclick = () => { sheetState.payerId = Number(b.dataset.id); haptic('select'); renderPayerToggle(); });
}

function applyKindUI(){
  const k = sheetState.kind;
  $$('#kindToggle button').forEach(b => b.classList.toggle('selected', b.dataset.kind === k));
  $('#kindTransfer').hidden = sheetState.mode === 'edit' || familyMembers().length < 2;
  $('#kindToggle').classList.toggle('three', !$('#kindTransfer').hidden);
  $('#entryFields').hidden = k === 'transfer';
  $('#transferFields').hidden = k !== 'transfer';
  $('#payerRow').hidden = sheetState.mode !== 'edit';
  if (k === 'transfer' && sheetState.currency !== 'KZT') { sheetState.currency = 'KZT'; $('#sheetCurrency').textContent = cur('KZT'); updateFxHint(); }
  $('#sheetCurrency').classList.toggle('locked', k === 'transfer');
  $('#splitField').hidden = k !== 'expense' || familyMembers().length < 2;
  $('#photoRow').hidden = k === 'transfer';
  $('#noteInput').placeholder = k === 'transfer' ? 'Например, на продукты' : 'Например, продукты на неделю';
  updateLimitHint();
}

function setDateButtons(){
  const y = new Date(); y.setDate(y.getDate() - 1);
  const mode = sheetState.date === todayISO() ? 'today' : sheetState.date === ymd(y) ? 'yesterday' : 'custom';
  sheetState.dateMode = mode;
  $$('#dateRow button').forEach(b => b.classList.toggle('selected', mode === 'custom' ? b.id === 'customDateBtn' : b.dataset.date === mode));
  $('#customDateLabel').textContent = mode === 'custom' ? fmtDate(sheetState.date) : 'Выбрать';
}

function openSheet(tx = null){
  afterSaveHook = null;
  const other = familyMembers().find(m => m.id !== myId());
  Object.assign(sheetState, {
    mode: tx ? 'edit' : 'add', editId: tx ? Number(tx.id) : null, kind: tx ? tx.kind : 'expense',
    amount: tx ? String(Number(tx.amount)) : '', category: tx ? tx.category : null, group: tx ? (tx.group || 'variable') : 'variable',
    date: tx ? tx.occurred_on : todayISO(), note: tx ? (tx.note || '') : '', payerId: tx ? Number(tx.telegram_id) : myId(),
    from: myId(), to: other ? other.id : null,
    account: tx ? (tx.account || '') : (() => { try { const last = localStorage.getItem('fb_last_account') || ''; return accountsCache.some(a => a.name === last) ? last : ''; } catch { return ''; } })(),
  });
  sheetState.currency = tx?.orig_currency || (!tx && mainCache?.trip ? mainCache.trip.currency : 'KZT');
  if (!fxRate(sheetState.currency)) sheetState.currency = 'KZT';
  sheetState.split = tx?.split || '';
  sheetState.photoData = null;
  sheetState.hasPhoto = Boolean(tx && Number(tx.has_photo));
  sheetState.prevCategory = tx ? tx.category : null;
  renderSplit();
  renderPhotoRow(tx);
  if (tx?.orig_currency) sheetState.amount = String(Number(tx.orig_amount));
  $('#sheetTitle').textContent = tx ? 'Изменить запись' : 'Новая запись';
  $('#noteInput').value = sheetState.note;
  delete $('#amountInput').dataset.touched;
  $('#sheetCurrency').textContent = cur(sheetState.currency);
  updateAmountDisplay();
  setDateButtons();
  renderCatGrid();
  renderTransferDir();
  renderPayerToggle();
  applyKindUI();
  renderAccountRow();
  $('#sheetDelete').hidden = !tx;
  $('#submitBtn').innerHTML = tx ? 'Сохранить изменения' : 'Сохранить <span>↗</span>';
  const sheet = $('#sheet');
  sheet.hidden = false;
  sheet.classList.remove('closing');
  document.body.style.overflow = 'hidden';
  sheetInitial = sheetSnapshot();
  tgMain.show(tx ? 'Сохранить изменения' : 'Сохранить', submitEntry);
  if (!tx) setTimeout(() => $('#amountInput').focus(), 200);
}

async function closeSheet(force = false){
  if (!force && sheetHasData()) {
    const confirmed = await showConfirm('Данные не сохранены. Выйти без сохранения?');
    if (!confirmed) return;
  }
  sheetInitial = '';
  tgMain.hide();
  document.body.style.overflow = '';
  await animateClose($('#sheet'));
}

function updateAmountDisplay(){
  updateFxHint();
  const v = sheetState.amount.replace(/\s/g, '');
  if (!v) { $('#amountInput').value = ''; return; }
  if (v.includes('.')) {
    const parts = v.split('.');
    $('#amountInput').value = new Intl.NumberFormat('ru-RU').format(Number(parts[0] || 0)) + ',' + (parts[1] || '');
  } else {
    $('#amountInput').value = new Intl.NumberFormat('ru-RU').format(Number(v));
  }
}

function bindSheet(){
  $('#fab').onclick = () => openSheet();
  $$('#sheet [data-close]').forEach(el => el.onclick = () => closeSheet());

  $$('#kindToggle button').forEach(b => b.onclick = () => {
    sheetState.kind = b.dataset.kind;
    haptic('select');
    applyKindUI();
    renderAccountRow();
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
  // When editing, the first tap selects the old amount so typing replaces it
  amt.onfocus = () => setTimeout(() => {
    if (sheetState.mode === 'edit' && !amt.dataset.touched) { amt.dataset.touched = '1'; amt.select(); }
    else amt.setSelectionRange(amt.value.length, amt.value.length);
  }, 0);

  $$('#quickAmounts button').forEach(b => b.onclick = () => {
    if (b.dataset.clear !== undefined || b.classList.contains('clear')) { sheetState.amount = ''; }
    else sheetState.amount = String(Number(sheetState.amount || 0) + Number(b.dataset.add));
    haptic('light');
    updateAmountDisplay();
    updateLimitHint();
    amt.focus();
  });

  $$('#dateRow button').forEach(b => b.onclick = () => {
    if (b.id === 'customDateBtn') { openSheetCalendar(); return; }
    if (b.dataset.date === 'today') sheetState.date = todayISO();
    if (b.dataset.date === 'yesterday') { const d = new Date(); d.setDate(d.getDate()-1); sheetState.date = ymd(d); }
    setDateButtons();
    updateLimitHint();
  });

  $('#customCatBtn').onclick = () => openCustomCatModal('sheet');
  $('#sheetCurrency').onclick = () => {
    if (sheetState.kind === 'transfer') return;
    const avail = FX_ORDER.filter(c => fxRate(c));
    sheetState.currency = avail[(avail.indexOf(sheetState.currency) + 1) % avail.length] || 'KZT';
    $('#sheetCurrency').textContent = cur(sheetState.currency);
    haptic('select');
    updateFxHint();
    updateLimitHint();
  };
  $('#submitBtn').onclick = submitEntry;
  $('#sheetDelete').onclick = async () => {
    if (!sheetState.editId) return;
    if (!(await showConfirm('Удалить эту операцию?'))) return;
    try {
      await request('api.php?action=delete', {method:'POST', body: JSON.stringify({id: sheetState.editId})});
      haptic('success');
      notice('Запись удалена', true);
      await closeSheet(true);
      await reloadAfterChange();
    } catch(e) { haptic('error'); notice(e.message); }
  };
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
    onSelect(iso){ sheetState.date = iso; setDateButtons(); closeSheetCalendar(); updateLimitHint(); }
  });
}
function closeSheetCalendar(){ $('#calModal').hidden = true; sheetCal = null; }
$$('#calModal [data-cal-close]').forEach(el => el.onclick = closeSheetCalendar);
$('#calCancel').onclick = closeSheetCalendar;

/* ========== CUSTOM CATEGORY ========== */
let customGroup = 'variable';
let customCatTarget = 'sheet'; // 'sheet' picks the new category for the entry, 'profile' adds a limit row
function openCustomCatModal(target = 'sheet'){
  customCatTarget = ['profile', 'manager'].includes(target) ? target : 'sheet';
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
// Saved on the server right away, so it shows up in limits, recurring payments and the bot
$('#customCatSave').onclick = async () => {
  const name = $('#customCatName').value.trim().replace(/\s+/g, ' ');
  if (!name) { notice('Введите название'); return; }
  if (name.length > 80) { notice('Слишком длинное название'); return; }
  const group = customGroup;
  const btn = $('#customCatSave');
  btn.disabled = true;
  try {
    const d = await request('api.php?action=category_add', {method: 'POST', body: JSON.stringify({name, group})});
    categoryGroups = d.category_groups || categoryGroups;
    recurringCategories = d.categories || recurringCategories;
    if (mainCache) mainCache.category_groups = categoryGroups;
    haptic('success');
    if (customCatTarget === 'manager') {
      notice(`Категория «${name}» добавлена`, true);
      request('api.php?action=categories').then(r => renderCats(r.items)).catch(() => {});
    } else if (customCatTarget === 'profile' && profile) {
      ['fixed', 'variable'].forEach(g => { profile.groups[g] = profile.groups[g].filter(n => n !== name); });
      profile.groups[group].push(name);
      renderLimitRows();
      setTimeout(() => $(`#limitsForm input[data-cat="${CSS.escape(name)}"]`)?.focus(), 50);
      notice(`Категория «${name}» добавлена — задайте лимит`, true);
    } else {
      sheetState.category = name;
      sheetState.group = group;
      renderCatGrid();
    }
    closeCustomCatModal();
  } catch(e) { haptic('error'); notice(e.message); }
  finally { btn.disabled = false; }
};

/* ========== SUBMIT ========== */
let submitting = false;
async function submitEntry(){
  if (submitting) return;
  const btn = $('#submitBtn');
  const amount = Number(sheetState.amount);
  if (!amount || amount <= 0) { haptic('error'); notice('Введите сумму'); $('#amountInput').focus(); return; }
  const note = $('#noteInput').value.trim();
  let url, body, okText;
  if (sheetState.kind === 'transfer') {
    if (!sheetState.from || !sheetState.to) { notice('Выберите, кто кому передал'); return; }
    url = 'api.php?action=transfer';
    body = {from_id: sheetState.from, to_id: sheetState.to, amount, note, date: sheetState.date};
    okText = 'Перевод записан ✓';
  } else {
    if (!sheetState.category) { haptic('error'); notice('Выберите категорию'); return; }
    body = {kind: sheetState.kind, amount, category: sheetState.category, category_group: sheetState.group, note, date: sheetState.date, account: sheetState.account || '', currency: sheetState.currency, split: sheetState.kind === 'expense' ? sheetState.split : ''};
    if (sheetState.mode === 'edit') { url = 'api.php?action=update'; body.id = sheetState.editId; body.payer_id = sheetState.payerId; okText = 'Изменения сохранены ✓'; }
    else { url = 'api.php'; okText = 'Запись добавлена ✓'; }
  }
  submitting = true;
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner small"></div> Сохранение…';
  tgMain.progress(true);
  try {
    const saved = await request(url, {method:'POST', body: JSON.stringify(body)});
    const txId = sheetState.mode === 'edit' ? sheetState.editId : saved.id;
    if (sheetState.photoData && txId) {
      try { await request('api.php?action=photo', {method: 'POST', body: JSON.stringify({id: txId, data: sheetState.photoData})}); }
      catch(e) { notice('Запись сохранена, но фото не загрузилось: ' + e.message); }
    }
    if (saved.roundup) okText = okText.replace(' ✓', '') + ` · 🐷 +${money(saved.roundup)} ${cur(currency)} в копилку ✓`;
    const ruleOffer = sheetState.mode === 'edit' && sheetState.kind === 'expense' && sheetState.prevCategory && sheetState.prevCategory !== sheetState.category && /\p{L}{3,}/u.test(note) ? {note, category: sheetState.category} : null;
    const limitMsg = limitNoticeAfterSave(saved.limits);
    if (limitMsg) { haptic('warning'); notice(okText.replace(' ✓', '') + '. ' + limitMsg, 'warn'); }
    else { haptic('success'); notice(okText, true); }
    await closeSheet(true);
    if (afterSaveHook) { const h = afterSaveHook; afterSaveHook = null; await h().catch(() => {}); }
    await reloadAfterChange();
    if (ruleOffer) offerRule(ruleOffer);
  } catch(e) { haptic('error'); notice(e.message); }
  finally {
    submitting = false;
    tgMain.progress(false);
    btn.disabled = false;
    btn.innerHTML = sheetState.mode === 'edit' ? 'Сохранить изменения' : 'Сохранить <span>↗</span>';
  }
}

async function reloadAfterChange(){
  mainCache = null; reportCache = null;
  await loadMain();
  if (!$('#tab-reports').hidden) await loadReport();
}

/* ========== DELETE ========== */
async function deleteRecord(id, el, type = 'tx'){
  const confirmed = await showConfirm(type === 'transfer' ? 'Удалить этот перевод?' : 'Удалить эту операцию?');
  if (!confirmed) return;
  el.classList.add('deleting');
  try {
    await request('api.php?action=delete', {method:'POST', body: JSON.stringify({id, type})});
    haptic('success');
    el.style.height = el.offsetHeight + 'px';
    el.offsetHeight; // force reflow
    Object.assign(el.style, {transition: 'all .3s ease', height: '0', opacity: '0', marginBottom: '0', padding: '0', overflow: 'hidden'});
    setTimeout(() => { el.remove(); notice(type === 'transfer' ? 'Перевод удалён' : 'Запись удалена', true); reloadAfterChange().catch(()=>{}); }, 300);
  } catch(e) { haptic('error'); notice(e.message); el.classList.remove('deleting'); }
}

/* ========== OPERATION LISTS ========== */
function txRowHtml(t){
  if (t.type === 'transfer') {
    return `<article class="transaction transfer" data-type="transfer" data-id="${t.id}">
      <i class="tx-icon transfer">⇄</i>
      <div class="tx-main">
        <div class="tx-title">${safe(t.from_name)} → ${safe(t.to_name)}</div>
        <div class="tx-meta">Перевод внутри семьи${t.note ? ' · ' + safe(t.note) : ''}</div>
      </div>
      <b class="tx-amount transfer">${money(t.amount)}</b>
      <button class="tx-delete" aria-label="Удалить перевод" data-delete="${t.id}">×</button>
    </article>`;
  }
  return `<article class="transaction" data-type="tx" data-id="${t.id}" tabindex="0" aria-label="${safe(t.category)} ${money(t.amount)} — нажмите, чтобы изменить">
    <i class="tx-icon ${t.kind}">${t.kind==='topup'?'↗':'↘'}</i>
    <div class="tx-main">
      <div class="tx-title">${safe(t.category)}${t.note?' · '+safe(t.note):''}</div>
      <div class="tx-meta">${safe(t.display_name)} · ${t.orig_currency ? fmtCur(t.orig_amount, t.orig_currency) : t.kind==='topup' ? 'пополнение' : t.group==='fixed'?'обязательные':'переменные'}${t.split ? ` · <span class="tx-tag">${t.split === 'half' ? '½ пополам' : 'за другого'}</span>` : ''}${Number(t.has_photo) ? ' · 📎' : ''}</div>
    </div>
    <b class="tx-amount ${t.kind}">${t.kind==='expense'?'−':'+'}${money(t.amount)}</b>
    <button class="tx-delete" aria-label="Удалить" data-delete="${t.id}">×</button>
  </article>`;
}

function renderTxList(container, rows, emptyText, withDates = true){
  if (!rows.length) { container.innerHTML = `<div class="empty card">${emptyText}</div>`; return; }
  const byId = {};
  rows.forEach(t => { byId[(t.type === 'transfer' ? 'transfer:' : 'tx:') + t.id] = t; });
  const byDate = {};
  rows.forEach(t => { (byDate[t.occurred_on] = byDate[t.occurred_on] || []).push(t); });
  const dates = Object.keys(byDate).sort().reverse();
  container.innerHTML = dates.map(date => `
    <div class="date-group">
      ${withDates ? `<div class="date-group-header">${fmtDateFriendly(date)}${date.slice(0,4) !== todayISO().slice(0,4) ? ' ' + date.slice(0,4) : ''}</div>` : ''}
      ${byDate[date].map(txRowHtml).join('')}
    </div>`).join('');
  container.querySelectorAll('.transaction').forEach(el => {
    const type = el.dataset.type;
    const row = byId[type + ':' + el.dataset.id];
    el.querySelector('.tx-delete').onclick = e => { e.stopPropagation(); deleteRecord(Number(el.dataset.id), el, type); };
    if (type === 'tx') {
      el.onclick = () => openSheet(row);
      el.onkeydown = e => { if (e.key === 'Enter') openSheet(row); };
    }
  });
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
  $('#sharedSavedBox').hidden = !(b.shared.saved > 0);
  $('#sharedSaved').textContent = money(b.shared.saved || 0) + ' ' + c;
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
        ${m.transfers ? `<span>Переводы: ${m.transfers > 0 ? '+' : '−'}${money(Math.abs(m.transfers))}</span>` : ''}
        ${m.saved > 0 ? `<span>В копилках: ${money(m.saved)}</span>` : ''}
        ${m.adjustment ? `<span>Сверка с банком: ${m.adjustment > 0 ? '+' : '−'}${money(Math.abs(m.adjustment))}</span>` : ''}
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

function renderRecent(rows, transfers = []){
  const merged = [...rows, ...(transfers || [])].sort((a, b) => b.occurred_on.localeCompare(a.occurred_on) || (b.type === 'transfer') - (a.type === 'transfer')).slice(0, 10);
  renderTxList($('#recent'), merged, 'Пока нет операций. Нажмите «+», чтобы добавить первую, или напишите боту «кафе 5000».');
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
    if ($('#searchBox').hidden) renderRecent(d.recent, d.transfers);
    renderForecast(d.forecast);
    renderPayments(d.recurring);
    renderGoals(d.goals);
    renderDebts(d.debts);
    renderDeposits(d.deposits);
    renderAccounts(d.accounts);
    renderCapital(d);
    renderShopTile(d.shopping_left || 0);
    renderSettlement(d.settlement);
    renderTripCard(d.trip);
    renderPrefs(d.prefs);
    renderPlanTile(d.plan || {});
    renderSettings(d.settings);
    renderResetStatus(d.reset);
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
    initial: periodRange().from === '1970-01-01' ? todayISO() : periodRange().from,
    rangeFrom: periodRange().from === '1970-01-01' ? null : periodRange().from,
    rangeTo: periodRange().from === '1970-01-01' ? null : periodRange().to,
    prevBtn: $('#rangeCalPrev'),
    nextBtn: $('#rangeCalNext'),
    onRangeChange(f, t){
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
    reportState.preset = 'custom'; reportState.from = f; reportState.to = t; reportState.page = 1;
    $$('#presets button').forEach(x => x.classList.toggle('selected', x.dataset.preset === 'custom'));
    closeRangeCalendar();
    loadReport().catch(()=>{});
  }
};

const MONTHS_GEN = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const dayMonth = iso => { const [, m, d] = iso.split('-'); return `${Number(d)} ${MONTHS_SHORT[Number(m) - 1]}`; };

// The period the report shows: one whole month/week/year that can be stepped through
function periodRange(state = reportState){
  const a = new Date((state.anchor || todayISO()) + 'T00:00:00');
  if (state.preset === 'week') {
    const from = new Date(a); from.setDate(a.getDate() - (a.getDay() + 6) % 7);
    const to = new Date(from); to.setDate(from.getDate() + 6);
    return {from: ymd(from), to: ymd(to), title: `${dayMonth(ymd(from))} — ${dayMonth(ymd(to))}`, sub: 'Неделя', step: true};
  }
  if (state.preset === 'year') {
    return {from: `${a.getFullYear()}-01-01`, to: `${a.getFullYear()}-12-31`, title: String(a.getFullYear()), sub: 'Год', step: true};
  }
  if (state.preset === 'all') return {from: '1970-01-01', to: todayISO(), title: 'Всё время', sub: 'Все операции', step: false};
  if (state.preset === 'custom' && state.from && state.to) {
    return {from: state.from, to: state.to, title: `${dayMonth(state.from)} — ${dayMonth(state.to)}`, sub: 'Свой период', step: false};
  }
  const from = new Date(a.getFullYear(), a.getMonth(), 1);
  const to = new Date(a.getFullYear(), a.getMonth() + 1, 0);
  return {from: ymd(from), to: ymd(to), title: `${MONTHS[a.getMonth()]} ${a.getFullYear()}`, sub: `1 — ${to.getDate()} ${MONTHS_GEN[a.getMonth()]}`, step: true};
}

function shiftPeriod(dir){
  const a = new Date((reportState.anchor || todayISO()) + 'T00:00:00');
  if (reportState.preset === 'week') a.setDate(a.getDate() + 7 * dir);
  else if (reportState.preset === 'year') a.setFullYear(a.getFullYear() + dir, 0, 1);
  else a.setMonth(a.getMonth() + dir, 1);
  reportState.anchor = ymd(a);
  reportState.page = 1;
  loadReport().catch(()=>{});
}

function renderPeriodNav(){
  const r = periodRange();
  $('#periodTitle').textContent = r.title;
  $('#periodRange').textContent = r.sub;
  $('#periodPrev').style.visibility = r.step ? '' : 'hidden';
  $('#periodNext').style.visibility = r.step ? '' : 'hidden';
  // Nothing to show after the current period
  $('#periodNext').disabled = !r.step || r.to >= todayISO();
  $('#reportHeroLabel').textContent = reportState.preset === 'month' ? `Потрачено за ${MONTHS[new Date(r.from + 'T00:00:00').getMonth()].toLowerCase()}` : 'Потрачено';
}

$('#periodPrev').onclick = () => shiftPeriod(-1);
$('#periodNext').onclick = () => shiftPeriod(1);

function renderReport(d){
  reportCache = d;
  $('#yearSend').hidden = reportState.preset !== 'year';
  renderCompare(d.compare);
  $('#yearSend').dataset.year = (d.from || todayISO()).slice(0, 4);
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
  renderPeriodNav();
  $('#comparisonHint').textContent = `Прошлый период: ${fmtDate(d.previous.from)} — ${fmtDate(d.previous.to)}`;

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
    <button type="button" class="group-row fixed drillable" data-group="fixed">
      <div class="group-info"><span>Обязательные</span><strong>${money(d.summary.fixed)} ${c} <i class="drill-arrow">›</i></strong></div>
      <div class="cat-track"><i style="width:${d.summary.fixed/total*100}%"></i></div>
      <div class="group-sub">${(d.summary.fixed/total*100).toFixed(0)}% от расходов периода</div>
    </button>
    <button type="button" class="group-row variable drillable" data-group="variable">
      <div class="group-info"><span>Переменные</span><strong>${money(d.summary.variable)} ${c} <i class="drill-arrow">›</i></strong></div>
      <div class="cat-track"><i style="width:${d.summary.variable/total*100}%"></i></div>
      <div class="group-sub">${(d.summary.variable/total*100).toFixed(0)}% от расходов периода</div>
    </button>
  `;
  // Drill into a group: its categories first, then operations by date
  $$('#groups .drillable').forEach(b => b.onclick = () => openDrill({from: d.from, to: d.to, group: b.dataset.group,
    title: (b.dataset.group === 'fixed' ? 'Обязательные' : 'Переменные') + ' · ' + periodRange().title}));

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
      ${topCats.length ? `<div class="person-cats">${topCats.map(([n,v])=>`<button type="button" class="chip" data-cat="${safe(n)}">${safe(n)} <b>${money(v)}</b></button>`).join('')}</div>` : ''}
      <div class="bar"><i style="width:${m.expenses/maxMember*100}%"></i></div>
    </article>`;
  }).join('');

  const entries = Object.entries(d.summary.categories || {}).filter(([_,v]) => reportState.filter === 'all' || v.group === reportState.filter);
  const maxCat = Math.max(1, ...entries.map(x=>Number(x[1].total)));
  $('#reportCategories').innerHTML = entries.length ? entries.map(([n,v]) => `<button type="button" class="category-row drillable" data-cat="${safe(n)}" aria-label="${safe(n)}: показать операции">
    <div class="category-info">
      <span>${safe(n)} <i class="cat-badge ${v.group}">${v.group==='fixed'?'обяз':'перем'}</i></span>
      <strong>${money(v.total)} ${c} <i class="drill-arrow">›</i></strong>
    </div>
    <div class="cat-track"><i style="width:${v.total/maxCat*100}%"></i></div>
    <div class="cat-sub">${v.count} операций · ${(v.total/(d.summary.expenses||1)*100).toFixed(0)}% расходов</div>
  </button>`).join('') : '<div class="empty">Нет категорий за период</div>';
  // A category opens straight on its operations, grouped by date
  const openCategory = name => openDrill({from: d.from, to: d.to, group: 'all', category: name, view: 'transactions', title: name + ' · ' + periodRange().title});
  $$('#reportCategories .drillable, #reportMembers .chip[data-cat]').forEach(b => b.onclick = () => openCategory(b.dataset.cat));

  renderChart(d.daily, c);
  renderShareChart(d.summary.categories, d.summary.expenses);
  renderMonthsChart(d.months, d.from);
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

function renderReportHistory(rows){
  renderTxList($('#reportHistory'), rows, 'За период нет операций');
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
  const r = periodRange();
  if (reportState.preset === 'all') u.searchParams.set('preset', 'all');
  else { u.searchParams.set('from', r.from); u.searchParams.set('to', r.to); }
  u.searchParams.set('page', reportState.page);
  u.searchParams.set('limit', 50);
  return u.toString();
}

async function loadReport(){
  if (reportAbort) reportAbort.abort();
  reportAbort = new AbortController();
  renderPeriodNav();
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
  drillState = {from: opts.from, to: opts.to, group, rootGroup:group, rootTitle:title, category: opts.category || '', member: Boolean(opts.member), view, data: null};
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
    if (drillState.member) u.searchParams.set('member', 'me');
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
// Limits are family-wide or personal, monthly or weekly, and never block an entry.
const TOTAL_LIMIT = '*';
const PERIOD_LABEL = {month: 'месяц', week: 'неделя'};
let limitsStatus = null;

function limitTail(i){
  if (i.remaining > 0) return `осталось ${money(i.remaining)} ${cur(currency)}`;
  if (i.remaining === 0) return 'лимит исчерпан';
  return `превышен на ${money(-i.remaining)} ${cur(currency)}`;
}

function carryText(i){
  if (!i.carry) return '';
  return `${i.carry > 0 ? '+' : '−'}${money(Math.abs(i.carry))} перенос`;
}

function limitName(i){
  const scope = i.scope === 'me' ? 'Личный лимит' : 'Лимит';
  const what = i.category === TOTAL_LIMIT ? 'на все расходы' : `«${i.label}»`;
  return `${scope} ${what} (${PERIOD_LABEL[i.period]})`;
}

function renderLimits(status){
  limitsStatus = status;
  const el = $('#limitsList');
  if (!status || !status.items.length) {
    el.innerHTML = `<div class="limits-empty">
      <p>Задайте лимиты на месяц или неделю — общие для семьи или личные. Бот предупредит, когда останется мало.</p>
      <button type="button" class="limits-empty-btn" data-open-profile>Настроить лимиты</button>
    </div>`;
    el.querySelector('[data-open-profile]').onclick = openProfile;
    return;
  }
  const c = cur(currency);
  let section = '';
  el.innerHTML = status.items.map(i => {
    const head = `${i.scope === 'family' ? 'Семейные' : 'Мои личные'} · ${PERIOD_LABEL[i.period]}`;
    const title = head !== section ? `<div class="limit-section">${head}<span>${fmtDate(i.from)} — ${fmtDate(i.to)}</span></div>` : '';
    section = head;
    const carry = carryText(i);
    return `${title}<button type="button" class="limit-item ${i.state}" data-id="${i.id}" aria-label="${safe(limitName(i))}: ${limitTail(i)}">
      <div class="limit-top">
        <span class="limit-name"><i class="dot-mark ${i.group}"></i>${safe(i.label)}</span>
        <span class="limit-amount">${money(i.spent)} <small>/ ${money(i.limit)} ${c}</small></span>
      </div>
      <div class="limit-track"><i style="width:${Math.min(100, i.percent)}%"></i></div>
      <div class="limit-sub"><span class="limit-left">${limitTail(i)}</span><span>${carry ? `<em class="limit-carry">${carry}</em> · ` : ''}${Math.round(i.percent)}%</span></div>
    </button>`;
  }).join('');
  $$('#limitsList .limit-item').forEach(b => b.onclick = () => {
    const i = status.items.find(x => String(x.id) === b.dataset.id);
    if (!i) return;
    const total = i.category === TOTAL_LIMIT;
    openDrill({from: i.from, to: i.to, group: 'all', category: total ? '' : i.category, member: i.scope === 'me',
      view: total ? 'categories' : 'transactions',
      title: `${total ? 'Все расходы' : i.category}${i.scope === 'me' ? ' · мои' : ''} · ${PERIOD_LABEL[i.period]}`});
  });
}

// Live hint in the new-entry sheet: what each affected limit looks like after this expense
function updateLimitHint(){
  const el = $('#limitHint');
  if (sheetState.kind !== 'expense' || !sheetState.category || !limitsStatus) { el.hidden = true; return; }
  const amount = sheetAmountKzt();
  const date = sheetState.date;
  const affected = limitsStatus.items.filter(i => (i.category === sheetState.category || i.category === TOTAL_LIMIT) && date >= i.from && date <= i.to);
  if (!affected.length) { el.hidden = true; return; }
  const rank = {ok:0, warn:1, over:2};
  let worst = 'ok';
  const lines = affected.map(i => {
    const after = i.remaining - amount;
    const state = after <= 0 ? 'over' : after < i.limit * limitsStatus.warn_percent / 100 ? 'warn' : 'ok';
    if (rank[state] > rank[worst]) worst = state;
    let text;
    if (!amount) text = i.remaining >= 0 ? `осталось ${money(i.remaining)} из ${money(i.limit)} ${cur(currency)}` : `уже превышен на ${money(-i.remaining)} ${cur(currency)}`;
    else if (after >= 0) text = `останется ${money(after)} из ${money(i.limit)} ${cur(currency)}`;
    else text = `будет превышен на ${money(-after)} ${cur(currency)}`;
    return `<div><b>${safe(limitName(i))}:</b> ${text}</div>`;
  });
  if (worst === 'over') lines.push('<div class="limit-hint-note">Запись всё равно сохранится — лимит просто уйдёт в минус.</div>');
  el.className = 'limit-hint ' + worst;
  el.innerHTML = lines.join('');
  el.hidden = false;
}

function limitNoticeAfterSave(items){
  const bad = (items || []).filter(i => i.state !== 'ok').sort((a, b) => b.percent - a.percent)[0];
  if (!bad) return null;
  if (bad.remaining < 0) return `${limitName(bad)} превышен на ${money(-bad.remaining)} ${cur(currency)}`;
  if (bad.remaining === 0) return `${limitName(bad)} исчерпан`;
  return `${limitName(bad)}: осталось ${money(bad.remaining)} ${cur(currency)}`;
}

/* ========== PROFILE ========== */
// Form state for all four limit sets, so switching tabs keeps unsaved input
let profile = null;

function digitsOnly(v){ return String(v || '').replace(/\D/g, '').slice(0, 9); }

function profileSnapshot(){
  if (!profile) return '';
  const sets = Object.keys(profile.values).sort().map(k => [k, Object.entries(profile.values[k]).filter(([, v]) => v).sort()]);
  return JSON.stringify([profile.warn, profile.rollover, sets]);
}

function profileDirty(){ return Boolean(profile) && profileSnapshot() !== profile.initial; }
function syncProfileSave(){ $('#limitsSave').disabled = !profileDirty(); }

async function openProfile(scrollTo = null){
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
  profile = null;
  applyTheme();
  loadRecurringList();
  loadSubscriptions();
  // Sections start folded; the one asked for opens
  $$('#profileSheet .pf-sec').forEach(d => { d.open = d.id === scrollTo; });
  $('#digestBox').hidden = true;
  updateProfileSums();
  if (typeof scrollTo === 'string') setTimeout(() => $('#' + scrollTo)?.scrollIntoView({behavior: 'smooth', block: 'start'}), 250);
  try {
    renderProfile(await request('api.php?action=limits'));
  } catch(e) { notice(e.message); closeProfile(true); }
}

function renderProfile(d){
  const fam = d.limits.filter(l => l.scope === 'family').length, mine = d.limits.length - fam;
  $('#limitsSum').textContent = d.limits.length ? [fam && `семейных ${fam}`, mine && `моих ${mine}`].filter(Boolean).join(' · ') : 'Не заданы';
  const values = {'family|month': {}, 'family|week': {}, 'me|month': {}, 'me|week': {}};
  d.limits.forEach(l => { values[`${l.scope}|${l.period}`][l.category] = String(Math.round(l.amount)); });
  const groups = {fixed: [], variable: []};
  d.categories.forEach(x => (groups[x.group] || groups.variable).push(x.category));
  profile = {values, groups, status: d.status, warn: d.status.warn_percent, rollover: d.status.rollover, scope: 'family', period: 'month', initial: ''};

  $('#warnToggle').innerHTML = d.warn_options.map(p => `<button type="button" data-warn="${p}">${p}%</button>`).join('');
  $$('#warnToggle button').forEach(b => b.onclick = () => { profile.warn = Number(b.dataset.warn); syncProfileControls(); });
  $('#rolloverToggle').checked = profile.rollover;
  $('#rolloverToggle').onchange = () => { profile.rollover = $('#rolloverToggle').checked; syncProfileSave(); };
  $$('#limitScope button').forEach(b => b.onclick = () => { profile.scope = b.dataset.scope; renderLimitRows(); });
  $$('#limitPeriod button').forEach(b => b.onclick = () => { profile.period = b.dataset.period; renderLimitRows(); });

  renderLimitRows();
  profile.initial = profileSnapshot();
  syncProfileControls();
}

function syncProfileControls(){
  $$('#warnToggle button').forEach(b => b.classList.toggle('selected', Number(b.dataset.warn) === profile.warn));
  $$('#limitScope button').forEach(b => b.classList.toggle('selected', b.dataset.scope === profile.scope));
  $$('#limitPeriod button').forEach(b => b.classList.toggle('selected', b.dataset.period === profile.period));
  // Show how many limits each tab holds
  const count = key => Object.values(profile.values[key]).filter(Boolean).length;
  $$('#limitScope button').forEach(b => { const n = count(`${b.dataset.scope}|month`) + count(`${b.dataset.scope}|week`); b.querySelector('em').textContent = n ? n : ''; });
  $$('#limitPeriod button').forEach(b => { const n = count(`${profile.scope}|${b.dataset.period}`); b.querySelector('em').textContent = n ? n : ''; });
  syncProfileSave();
}

function renderLimitRows(){
  const c = cur(currency);
  const key = `${profile.scope}|${profile.period}`;
  const set = profile.values[key];
  const per = profile.period === 'week' ? 'неделю' : 'месяц';
  $('#limitScopeHint').textContent = profile.scope === 'family'
    ? `Общие траты семьи за ${per}. Уведомления получают оба.`
    : `Только ваши траты за ${per}. Видите и получаете уведомления только вы.`;
  const statusOf = cat => profile.status.items.find(i => i.scope === profile.scope && i.period === profile.period && i.category === cat);
  const row = (cat, label, group) => {
    const item = statusOf(cat);
    const v = set[cat] || '';
    let sub = 'без лимита';
    if (item) sub = `потрачено ${money(item.spent)} ${c} · ${limitTail(item)}${item.carry ? ` · ${carryText(item)}` : ''}`;
    return `<label class="limit-row ${v ? 'has-limit' : ''}">
      <span class="limit-row-name"><span><i class="dot-mark ${group}"></i>${safe(label)}</span><small>${sub}</small></span>
      <span class="limit-input"><input type="text" inputmode="numeric" autocomplete="off" placeholder="—" data-cat="${safe(cat)}" value="${v ? money(Number(v)) : ''}" aria-label="Лимит: ${safe(label)}"><b>${c}</b></span>
    </label>`;
  };
  $('#limitsForm').innerHTML =
    row(TOTAL_LIMIT, `Все расходы за ${per}`, 'total') +
    `<div class="limit-group-title">Обязательные</div>` + profile.groups.fixed.map(n => row(n, n, 'fixed')).join('') +
    `<div class="limit-group-title">Переменные</div>` + profile.groups.variable.map(n => row(n, n, 'variable')).join('');
  $$('#limitsForm input[data-cat]').forEach(inp => inp.oninput = () => {
    const v = digitsOnly(inp.value);
    inp.value = v ? money(Number(v)) : '';
    set[inp.dataset.cat] = v;
    inp.closest('.limit-row').classList.toggle('has-limit', Boolean(v));
    syncProfileControls();
  });
  syncProfileControls();
}

async function saveProfile(){
  const btn = $('#limitsSave');
  const limits = [];
  Object.entries(profile.values).forEach(([key, set]) => {
    const [scope, period] = key.split('|');
    Object.entries(set).forEach(([category, v]) => { if (v && Number(v) > 0) limits.push({scope, period, category, amount: Number(v)}); });
  });
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner small"></div> Сохранение…';
  try {
    const r = await request('api.php?action=limits', {method: 'POST', body: JSON.stringify({limits, warn_percent: profile.warn, rollover: profile.rollover})});
    renderLimits(r.status);
    notice(r.changed ? 'Лимиты сохранены' : 'Без изменений', true);
    await closeProfile(true);
  } catch(e) { notice(e.message); btn.disabled = false; }
  finally { btn.textContent = 'Сохранить'; }
}

async function closeProfile(force = false){
  if (!force && profileDirty()) {
    const ok = await showConfirm('Изменения не сохранены. Закрыть без сохранения?');
    if (!ok) return;
  }
  profile = null;
  document.body.style.overflow = '';
  await animateClose($('#profileSheet'));
}

$('#avatar').onclick = () => openProfile();
$('#limitsEdit').onclick = () => openProfile('limitsSec');
$('#limitsSave').onclick = saveProfile;
$('#limitsAddCat').onclick = () => openCustomCatModal('profile');
$$('#profileSheet [data-profile-close]').forEach(el => el.onclick = () => closeProfile());

/* ========== FORECAST ========== */
function renderForecast(f){
  const el = $('#forecast');
  if (!f || (f.spent === 0 && f.unpaid_recurring === 0)) { el.hidden = true; return; }
  const c = cur(currency);
  let limitLine = '';
  if (f.limit !== null && f.limit !== undefined) {
    limitLine = f.vs_limit > 0
      ? `<div class="forecast-limit over">Выше общего лимита ${money(f.limit)} ${c} на ${money(f.vs_limit)} ${c}</div>`
      : `<div class="forecast-limit ok">Укладываетесь в лимит ${money(f.limit)} ${c}, запас ${money(-f.vs_limit)} ${c}</div>`;
  }
  // Too few days to extrapolate: show facts and what is already scheduled
  if (!f.reliable) {
    el.innerHTML = `
      <div class="forecast-top"><span>Начало месяца</span><span>ещё ${f.days_left} дн.</span></div>
      <div class="forecast-value">${money(f.spent + f.unpaid_recurring)} <small>${c}</small></div>
      <div class="forecast-sub">Потрачено ${money(f.spent)} ${c}${f.unpaid_recurring ? ` + впереди платежи на ${money(f.unpaid_recurring)} ${c}` : ''}</div>
      <div class="forecast-note">Прогноз до конца месяца появится после 5-го числа, когда будет видно ваш обычный темп.</div>`;
    el.hidden = false;
    return;
  }
  el.innerHTML = `
    <div class="forecast-top"><span>Сколько потратим к концу месяца</span><span>${f.days_left ? 'ещё ' + f.days_left + ' дн.' : 'последний день'}</span></div>
    <div class="forecast-value">≈ ${money(f.projected)} <small>${c}</small></div>
    <div class="forecast-sub">Уже потрачено ${money(f.spent)} ${c}${f.pace ? ` · в среднем ${money(f.pace)} ${c} в день` : ''}${f.unpaid_recurring ? ` · впереди платежи на ${money(f.unpaid_recurring)} ${c}` : ''}</div>
    ${limitLine}
    `;
  el.hidden = false;
}

/* ========== PAYMENTS OF THE MONTH ========== */
function paymentWhen(r){
  if (r.status === 'done') return 'оплачено';
  if (r.status === 'skipped') return 'пропущено';
  if (r.due_date === todayISO()) return 'сегодня';
  if (r.status === 'due') return 'просрочено с ' + fmtDateFriendly(r.due_date).toLowerCase();
  return 'до ' + fmtDateFriendly(r.due_date).toLowerCase();
}

function renderPayments(items){
  const list = (items || []).filter(r => r.active);
  $('#paymentsSection').hidden = !list.length;
  if (!list.length) return;
  const c = cur(currency);
  list.sort((a, b) => (a.status === 'done' || a.status === 'skipped') - (b.status === 'done' || b.status === 'skipped') || a.due_date.localeCompare(b.due_date));
  $('#paymentsList').innerHTML = list.map(r => `
    <div class="payment ${r.status}">
      <div class="payment-main">
        <div class="payment-title"><i class="dot-mark ${r.group}"></i>${safe(r.category)}${r.note ? ` <span>· ${safe(r.note)}</span>` : ''}</div>
        <div class="payment-meta">${money(r.amount)} ${c} · ${paymentWhen(r)}${r.payer_id ? ' · ' + safe(r.payer_name) : ''}</div>
      </div>
      ${r.status === 'due' || r.status === 'upcoming' ? `<button type="button" class="pill-button" data-pay="${r.id}">Оплачено</button>` : `<span class="payment-done">${r.status === 'done' ? '✓' : '—'}</span>`}
    </div>`).join('');
  $$('#paymentsList [data-pay]').forEach(b => b.onclick = async () => {
    b.disabled = true;
    try {
      const r = await request('api.php?action=recurring_pay', {method:'POST', body: JSON.stringify({id: Number(b.dataset.pay)})});
      const msg = limitNoticeAfterSave(r.limits);
      haptic(msg ? 'warning' : 'success');
      notice(msg ? 'Платёж записан. ' + msg : 'Платёж записан ✓', msg ? 'warn' : true);
      await reloadAfterChange();
    } catch(e) { haptic('error'); notice(e.message); b.disabled = false; }
  });
}
$('#paymentsEdit').onclick = () => openProfile('recurringBlock');

/* ========== GOALS ========== */
let goalsCache = [];
function renderGoals(goals){
  goalsCache = goals || [];
  const c = cur(currency);
  if (!goalsCache.length) {
    $('#goalsList').innerHTML = `<div class="card limits-empty goals-empty"><p>Копите на отпуск, ремонт или подушку? Создайте цель — отложенные деньги не будут путаться с остатком на расходы.</p><button type="button" class="limits-empty-btn" data-goal-new>Создать цель</button></div>`;
    $('#goalsList [data-goal-new]').onclick = () => openGoalModal();
    return;
  }
  $('#goalsList').innerHTML = goalsCache.map(g => {
    let sub = g.left > 0 ? `осталось ${money(g.left)} ${c}` : 'цель достигнута 🎉';
    if (g.left > 0 && g.monthly_needed) sub += ` · по ${money(g.monthly_needed)} ${c} в месяц`;
    if (g.deadline) sub += ` · до ${fmtDate(g.deadline)}`;
    const people = (g.by || []).filter(x => x.amount > 0).map(x => `${safe(x.name)} ${money(x.amount)}`).join(' · ');
    const plan = g.monthly_plan && g.left > 0 ? `<div class="goal-plan ${g.this_month >= g.monthly_plan ? 'done' : ''}">В этом месяце ${money(g.this_month)} из ${money(g.monthly_plan)} ${c}</div>` : '';
    return `<article class="goal card" data-goal="${g.id}">
      <button type="button" class="goal-head" data-goal-edit="${g.id}" aria-label="Изменить цель ${safe(g.title)}">
        <span class="goal-title">${safe(g.title)}</span>
        <span class="goal-amount">${money(g.saved)} <small>/ ${money(g.target)} ${c}</small></span>
      </button>
      <div class="limit-track"><i style="width:${g.percent}%"></i></div>
      <div class="goal-sub">${sub}</div>
      ${people && (g.by || []).length > 1 ? `<div class="goal-people">${people}</div>` : ''}${plan}
      <div class="goal-actions">
        <button type="button" class="pill-button" data-goal-move="${g.id}" data-dir="1">Отложить</button>
        <button type="button" class="pill-button ghost" data-goal-move="${g.id}" data-dir="-1" ${g.saved > 0 ? '' : 'disabled'}>Забрать</button>
      </div>
    </article>`;
  }).join('');
  $$('#goalsList [data-goal-edit]').forEach(b => b.onclick = () => openGoalModal(goalsCache.find(g => g.id === Number(b.dataset.goalEdit))));
  $$('#goalsList [data-goal-move]').forEach(b => b.onclick = () => openMoveModal(goalsCache.find(g => g.id === Number(b.dataset.goalMove)), Number(b.dataset.dir)));
}

let goalEditing = null;
function openGoalModal(goal = null){
  goalEditing = goal;
  $('#goalModalTitle').textContent = goal ? 'Цель' : 'Новая цель';
  $('#goalTitle').value = goal ? goal.title : '';
  $('#goalTarget').value = goal ? money(goal.target) : '';
  $('#goalDeadline').value = goal?.deadline || '';
  $('#goalMonthly').value = goal?.monthly_plan ? money(goal.monthly_plan) : '';
  $('#goalCloseBtn').hidden = !goal;
  $('#goalModal').hidden = false;
  if (!goal) setTimeout(() => $('#goalTitle').focus(), 100);
}
const closeGoalModal = () => { $('#goalModal').hidden = true; };
$$('#goalModal [data-goal-close]').forEach(el => el.onclick = closeGoalModal);
$('#goalTarget').oninput = $('#goalMonthly').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };
$('#goalSave').onclick = async () => {
  try {
    await request('api.php?action=goal_save', {method:'POST', body: JSON.stringify({id: goalEditing?.id, title: $('#goalTitle').value, target: digitsOnly($('#goalTarget').value), deadline: $('#goalDeadline').value, monthly: digitsOnly($('#goalMonthly').value)})});
    haptic('success'); notice('Цель сохранена ✓', true); closeGoalModal(); await reloadAfterChange();
  } catch(e) { haptic('error'); notice(e.message); }
};
$('#goalCloseBtn').onclick = async () => {
  if (!goalEditing) return;
  if (!(await showConfirm(`Закрыть цель «${goalEditing.title}»? ${goalEditing.saved > 0 ? money(goalEditing.saved) + ' ' + cur(currency) + ' вернутся в бюджет.' : ''}`))) return;
  try { await request('api.php?action=goal_close', {method:'POST', body: JSON.stringify({id: goalEditing.id})}); haptic('success'); notice('Цель закрыта', true); closeGoalModal(); await reloadAfterChange(); }
  catch(e) { notice(e.message); }
};

let moveGoal = null, moveDir = 1;
function openMoveModal(goal, dir){
  moveGoal = goal; moveDir = dir;
  $('#moveTitle').textContent = goal.title;
  $$('#moveDir button').forEach(b => b.classList.toggle('selected', Number(b.dataset.dir) === dir));
  $('#moveAmount').value = '';
  syncMoveHelp();
  $('#moveModal').hidden = false;
  setTimeout(() => $('#moveAmount').focus(), 100);
}
function syncMoveHelp(){
  const c = cur(currency);
  $('#moveHelp').textContent = moveDir > 0
    ? `Сумма уйдёт из вашего остатка в копилку. Сейчас в копилке ${money(moveGoal.saved)} ${c}.`
    : `Деньги вернутся в ваш остаток. В копилке ${money(moveGoal.saved)} ${c}.`;
}
const closeMoveModal = () => { $('#moveModal').hidden = true; };
$$('#moveModal [data-move-close]').forEach(el => el.onclick = closeMoveModal);
$$('#moveDir button').forEach(b => b.onclick = () => { moveDir = Number(b.dataset.dir); $$('#moveDir button').forEach(x => x.classList.toggle('selected', x === b)); syncMoveHelp(); });
$('#moveAmount').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };
$('#moveSave').onclick = async () => {
  const v = Number(digitsOnly($('#moveAmount').value));
  if (!v) { notice('Введите сумму'); return; }
  try {
    await request('api.php?action=goal_move', {method:'POST', body: JSON.stringify({id: moveGoal.id, amount: v * moveDir})});
    haptic('success'); notice(moveDir > 0 ? 'Отложено ✓' : 'Возвращено в бюджет ✓', true); closeMoveModal(); await reloadAfterChange();
  } catch(e) { haptic('error'); notice(e.message); }
};
$('#goalAdd').onclick = () => openGoalModal();

/* ========== SEARCH ========== */
let searchTimer = null, searchAbort = null;
function closeSearch(){
  $('#searchBox').hidden = true;
  $('#searchInput').value = '';
  $('#recentTitle').textContent = 'Последние операции';
  if (mainCache) renderRecent(mainCache.recent, mainCache.transfers);
}
$('#searchToggle').onclick = () => {
  if (!$('#searchBox').hidden) { closeSearch(); return; }
  $('#searchBox').hidden = false;
  $('#searchInput').focus();
};
$('#searchClose').onclick = closeSearch;
$('#searchInput').oninput = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const q = $('#searchInput').value.trim();
    if (q.length < 2) { $('#recentTitle').textContent = 'Последние операции'; if (mainCache) renderRecent(mainCache.recent, mainCache.transfers); return; }
    if (searchAbort) searchAbort.abort();
    searchAbort = new AbortController();
    try {
      const d = await request('api.php?action=search&q=' + encodeURIComponent(q), {signal: searchAbort.signal});
      $('#recentTitle').textContent = d.items.length ? `Найдено: ${d.items.length}${d.items.length >= 100 ? '+' : ''}` : 'Ничего не найдено';
      renderTxList($('#recent'), d.items, `По запросу «${safe(q)}» ничего нет`);
    } catch(e) { if (e.name !== 'AbortError') notice(e.message); }
  }, 300);
};
$('#searchInput').onkeydown = e => { if (e.key === 'Escape') closeSearch(); };

/* ========== RECURRING (profile) ========== */
let recurringCategories = [];
let recEditing = null, recPayer = 0;
async function loadRecurringList(){
  try {
    const d = await request('api.php?action=recurring');
    recurringCategories = d.categories || [];
    renderRecurringList(d.items);
  } catch(e) { $('#recurringList').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
function renderRecurringList(items){
  const c = cur(currency);
  $('#recSum').textContent = items.length ? `${items.length} ${plural(items.length, 'платёж', 'платежа', 'платежей')} · ${money(items.filter(r => r.active).reduce((s, r) => s + Number(r.amount), 0))} ${c} в месяц` : 'Кредиты, коммуналка, подписки';
  $('#recurringList').innerHTML = items.length ? items.map(r => `
    <button type="button" class="recurring-row ${r.active ? '' : 'paused'}" data-rec="${r.id}">
      <span class="recurring-main"><b>${safe(r.category)}${r.note ? ' · ' + safe(r.note) : ''}</b><small>${r.day}-го числа · ${safe(r.payer_name)}${r.active ? '' : ' · на паузе'}</small></span>
      <span class="recurring-amount">${money(r.amount)} ${c}</span>
    </button>`).join('') : '<div class="empty">Пока нет регулярных платежей</div>';
  $$('#recurringList [data-rec]').forEach(b => b.onclick = () => openRecModal(items.find(r => r.id === Number(b.dataset.rec))));
}
function openRecModal(r = null){
  recEditing = r;
  recPayer = r ? r.payer_id : 0;
  const cats = recurringCategories.length ? recurringCategories : [...(categoryGroups.fixed || []).map(c => ({category: c, group: 'fixed'})), ...(categoryGroups.variable || []).map(c => ({category: c, group: 'variable'}))];
  const names = cats.map(c => c.category);
  if (r && !names.includes(r.category)) cats.push({category: r.category, group: r.group});
  $('#recCategory').innerHTML = cats.map(c => `<option value="${safe(c.category)}" ${r ? (r.category === c.category ? 'selected' : '') : (c.category === 'Кредиты' ? 'selected' : '')}>${safe(c.category)}</option>`).join('');
  $('#recAmount').value = r ? money(r.amount) : '';
  $('#recNote').value = r ? r.note : '';
  $('#recDay').value = r ? r.day : '';
  $('#recActive').checked = r ? r.active : true;
  $('#recPayer').innerHTML = [{id: 0, name: 'Любой'}, ...familyMembers()].map(m => `<button type="button" data-id="${m.id}" class="${m.id === recPayer ? 'selected' : ''}">${safe(m.name)}</button>`).join('');
  $$('#recPayer button').forEach(b => b.onclick = () => { recPayer = Number(b.dataset.id); $$('#recPayer button').forEach(x => x.classList.toggle('selected', x === b)); });
  $('#recDelete').hidden = !r;
  $('#recTitle').textContent = r ? 'Регулярный платёж' : 'Новый платёж';
  $('#recModal').hidden = false;
}
const closeRecModal = () => { $('#recModal').hidden = true; };
$$('#recModal [data-rec-close]').forEach(el => el.onclick = closeRecModal);
$('#recAmount').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };
$('#recSave').onclick = async () => {
  try {
    const d = await request('api.php?action=recurring_save', {method:'POST', body: JSON.stringify({id: recEditing?.id, kind: 'expense', category: $('#recCategory').value,
      amount: digitsOnly($('#recAmount').value), note: $('#recNote').value, day: Number($('#recDay').value), payer_id: recPayer, active: $('#recActive').checked})});
    haptic('success'); notice('Платёж сохранён ✓', true); closeRecModal(); renderRecurringList(d.items); renderPayments(d.items);
  } catch(e) { haptic('error'); notice(e.message); }
};
$('#recDelete').onclick = async () => {
  if (!recEditing || !(await showConfirm('Удалить регулярный платёж? Уже записанные операции останутся.'))) return;
  try { const d = await request('api.php?action=recurring_delete', {method:'POST', body: JSON.stringify({id: recEditing.id})}); closeRecModal(); renderRecurringList(d.items); renderPayments(d.items); notice('Платёж удалён', true); }
  catch(e) { notice(e.message); }
};
$('#recurringAdd').onclick = () => openRecModal();

/* ========== STATEMENT IMPORT ========== */
const PDFJS = {src: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', worker: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',
  integrity: 'sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e'};
let importPayer = null, importRows = [], importCats = [];
function loadPdfJs(){
  if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = PDFJS.src; s.integrity = PDFJS.integrity; s.crossOrigin = 'anonymous';
    s.onload = () => { window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS.worker; resolve(window.pdfjsLib); };
    s.onerror = () => reject(Error('Не удалось загрузить модуль чтения PDF'));
    document.head.appendChild(s);
  });
}
// Rebuilds text lines from PDF glyph runs by their vertical position
async function pdfToText(file){
  const lib = await loadPdfJs();
  const pdf = await lib.getDocument({data: await file.arrayBuffer()}).promise;
  const out = [];
  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);
    const content = await page.getTextContent();
    const lines = new Map();
    content.items.forEach(it => {
      const y = Math.round(it.transform[5] / 2) * 2;
      if (!lines.has(y)) lines.set(y, []);
      lines.get(y).push({x: it.transform[4], s: it.str});
    });
    [...lines.entries()].sort((a, b) => b[0] - a[0]).forEach(([, parts]) => out.push(parts.sort((a, b) => a.x - b.x).map(x => x.s).join('  ')));
  }
  return out.join('\n');
}
function openImport(){
  importPayer = myId();
  $('#importPayer').innerHTML = familyMembers().map(m => `<button type="button" data-id="${m.id}" class="${m.id === importPayer ? 'selected' : ''}">${safe(m.name)}</button>`).join('');
  $$('#importPayer button').forEach(b => b.onclick = () => { importPayer = Number(b.dataset.id); $$('#importPayer button').forEach(x => x.classList.toggle('selected', x === b)); });
  $('#importFile').value = ''; $('#importText').value = '';
  $('#importFileName').textContent = 'Kaspi → Kaspi Gold → Выписка → Скачать PDF';
  $('#importStep1').hidden = false; $('#importStep2').hidden = true;
  const sheet = $('#importSheet'); sheet.hidden = false; sheet.classList.remove('closing');
}
async function closeImport(){ await animateClose($('#importSheet')); }
$$('#importSheet [data-import-close]').forEach(el => el.onclick = closeImport);
$('#importOpen').onclick = openImport;
$('#importFile').onchange = () => { const f = $('#importFile').files[0]; $('#importFileName').textContent = f ? f.name : ''; };
$('#importBack').onclick = () => { $('#importStep1').hidden = false; $('#importStep2').hidden = true; };
$('#importParse').onclick = async () => {
  const btn = $('#importParse');
  const file = $('#importFile').files[0];
  btn.disabled = true; btn.innerHTML = '<div class="spinner small"></div> Читаю выписку…';
  try {
    let text = $('#importText').value;
    if (file) text = /\.pdf$/i.test(file.name) || file.type === 'application/pdf' ? await pdfToText(file) : await file.text();
    if (!text.trim()) throw Error('Выберите файл или вставьте текст выписки');
    const d = await request('api.php?action=import_preview', {method:'POST', body: JSON.stringify({text, payer_id: importPayer})});
    if (!d.rows.length) throw Error('Не нашёл операций. Нужна выписка Kaspi Gold в PDF — или вставьте её текст.');
    importRows = d.rows; importCats = d.categories.map(c => c.category);
    renderImportRows();
    $('#importStep1').hidden = true; $('#importStep2').hidden = false;
  } catch(e) { haptic('error'); notice(e.message); }
  finally { btn.disabled = false; btn.textContent = 'Разобрать'; }
};
function renderImportRows(){
  const c = cur(currency);
  $('#importRows').innerHTML = importRows.map((r, i) => {
    const cats = importCats.includes(r.category) ? importCats : [r.category, ...importCats];
    return `<label class="import-row ${r.include ? '' : 'off'}">
      <input type="checkbox" data-i="${i}" ${r.include ? 'checked' : ''}>
      <span class="import-main">
        <span class="import-top"><b>${safe(r.note || r.type)}</b><b class="tx-amount ${r.kind}">${r.kind === 'expense' ? '−' : '+'}${money(r.amount)}</b></span>
        <span class="import-meta">${fmtDate(r.date)} · ${safe(r.type)}${r.dup === 'imported' ? ' · <em>уже импортирована раньше</em>' : r.dup === 'manual' ? ' · <em>уже записана вручную</em>' : ''}</span>
        <select data-cat="${i}" aria-label="Категория">${cats.map(x => `<option ${x === r.category ? 'selected' : ''}>${safe(x)}</option>`).join('')}</select>
      </span>
    </label>`;
  }).join('');
  $$('#importRows input[data-i]').forEach(cb => cb.onchange = () => { importRows[cb.dataset.i].include = cb.checked; cb.closest('.import-row').classList.toggle('off', !cb.checked); syncImportSummary(); });
  $$('#importRows select[data-cat]').forEach(sel => sel.onchange = () => { importRows[sel.dataset.cat].category = sel.value; });
  syncImportSummary();
}
function syncImportSummary(){
  const sel = importRows.filter(r => r.include);
  const sum = sel.reduce((s, r) => s + (r.kind === 'expense' ? r.amount : 0), 0);
  $('#importSummary').innerHTML = `Найдено ${importRows.length} операций. Выбрано <b>${sel.length}</b>${sum ? `, расходов на <b>${money(sum)} ${cur(currency)}</b>` : ''}. Переводы, пополнения и уже записанные операции не отмечены — проверьте их сами.`;
  $('#importConfirm').disabled = !sel.length;
  $('#importConfirm').textContent = sel.length ? `Импортировать ${sel.length}` : 'Ничего не выбрано';
}
$('#importConfirm').onclick = async () => {
  const rows = importRows.filter(r => r.include).map(r => ({kind: r.kind, amount: r.amount, category: r.category, note: r.note, date: r.date, key: r.key, type: r.type, payer_id: r.payer_id, account: r.account}));
  const btn = $('#importConfirm'); btn.disabled = true;
  try {
    const d = await request('api.php?action=import', {method:'POST', body: JSON.stringify({rows, payer_id: importPayer})});
    haptic('success');
    notice(`Импортировано: ${d.imported}` + (d.linked ? ` · совпали с ручными: ${d.linked}` : '') + (d.skipped ? ` · уже были: ${d.skipped}` : '') + ' ✓', true);
    await closeImport(); await reloadAfterChange();
  } catch(e) { haptic('error'); notice(e.message); btn.disabled = false; }
};

/* ========== DEBTS ========== */
// "Мне должны" is green with +, "Я должен" is red with −. Debts live beside the budget, not in it.
let debtsCache = [];
const debtSign = d => d.direction === 'owed_to_me' ? '+' : '−';
const debtClass = d => d.direction === 'owed_to_me' ? 'money-pos' : 'money-neg';

function renderDebts(debts){
  debtsCache = debts || [];
  const c = cur(currency);
  const t = {owed_to_me: 0, i_owe: 0};
  debtsCache.forEach(d => { if (!d.closed) t[d.direction] += d.amount; });
  const open = debtsCache.filter(d => !d.closed);
  // Tile: just the two totals, everything else is inside
  $('#debtTileSub').innerHTML = open.length
    ? [t.owed_to_me ? `<span class="money-pos">+${money(t.owed_to_me)}</span>` : '', t.i_owe ? `<span class="money-neg">−${money(t.i_owe)}</span>` : ''].filter(Boolean).join(' · ') + ' ' + c
    : 'Добавить запись';
  if (!$('#debtsSheet').hidden) renderDebtsSheet();
}

function renderDebtsSheet(){
  const c = cur(currency);
  const open = debtsCache.filter(d => !d.closed);
  const t = {owed_to_me: 0, i_owe: 0};
  open.forEach(d => { t[d.direction] += d.amount; });
  $('#debtTotals').innerHTML = `
    <div><span>Мне должны</span><b class="money-pos">${t.owed_to_me ? '+' : ''}${money(t.owed_to_me)} ${c}</b></div>
    <div><span>Я должен</span><b class="money-neg">${t.i_owe ? '−' : ''}${money(t.i_owe)} ${c}</b></div>`;
  const group = (dir, title) => {
    const rows = open.filter(d => d.direction === dir);
    if (!rows.length) return '';
    return `<div class="limit-group-title">${title}</div>` + rows.map(d => `
      <button type="button" class="debt-row" data-debt="${d.id}">
        <span class="debt-row-main"><b>${safe(d.person)}</b><small>${[d.note && safe(d.note), d.due_on && 'до ' + fmtDate(d.due_on)].filter(Boolean).join(' · ') || 'без комментария'}</small></span>
        <b class="${debtClass(d)}">${debtSign(d)}${money(d.amount)} ${c}</b>
      </button>`).join('');
  };
  $('#debtsList').innerHTML = open.length ? group('owed_to_me', 'Мне должны') + group('i_owe', 'Я должен') : '<div class="empty">Долгов нет</div>';
  $$('#debtsList [data-debt]').forEach(b => b.onclick = () => openDebtModal(debtsCache.find(d => d.id === Number(b.dataset.debt))));
}

function openDebtsSheet(){
  renderDebtsSheet();
  const s = $('#debtsSheet'); s.hidden = false; s.classList.remove('closing');
}
const closeDebtsSheet = () => animateClose($('#debtsSheet'));

let debtEditing = null, debtDir = 'owed_to_me';
async function openDebtModal(debt = null, dir = 'owed_to_me'){
  debtEditing = debt;
  debtDir = debt ? debt.direction : dir;
  const c = cur(currency);
  $('#debtTitle').textContent = debt ? debt.person : 'Новый долг';
  $('#debtPerson').value = debt ? debt.person : '';
  $('#debtNote').value = debt ? debt.note : '';
  $('#debtDue').value = debt?.due_on || '';
  $('#debtAmount').value = '';
  $('#debtAmountLabel').hidden = Boolean(debt);
  $('#debtDelete').hidden = !debt;
  $('#debtRepay').hidden = !debt;
  $('#debtCurrent').hidden = !debt;
  $('#debtMoveAmount').value = '';
  $$('#debtDirection button').forEach(b => b.classList.toggle('selected', b.dataset.dir === debtDir));
  if (debt) {
    $('#debtCurrent').innerHTML = `<span>${debt.direction === 'owed_to_me' ? 'Должен(на) мне' : 'Я должен'}</span><b class="${debtClass(debt)}">${debtSign(debt)}${money(debt.amount)} ${c}</b>`;
    $('#debtRepayBtn').textContent = debt.direction === 'owed_to_me' ? 'Вернули часть' : 'Вернул(а) часть';
    $('#debtMoreBtn').textContent = debt.direction === 'owed_to_me' ? 'Дал(а) ещё' : 'Взял(а) ещё';
    $('#debtMoves').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
    request('api.php?action=debt&id=' + debt.id).then(d => {
      $('#debtMoves').innerHTML = d.moves.length ? '<div class="limit-group-title">История</div>' + d.moves.map(m => `<div class="debt-move"><span>${fmtDate(m.occurred_on)}${m.note ? ' · ' + safe(m.note) : ''}</span><b>${m.amount > 0 ? '+' : '−'}${money(Math.abs(m.amount))}</b></div>`).join('') : '';
    }).catch(() => { $('#debtMoves').innerHTML = ''; });
  }
  $('#debtModal').hidden = false;
  if (!debt) setTimeout(() => $('#debtPerson').focus(), 100);
}
const closeDebtModal = () => { $('#debtModal').hidden = true; };
$$('#debtModal [data-debt-close]').forEach(el => el.onclick = closeDebtModal);
$$('#debtDirection button').forEach(b => b.onclick = () => { debtDir = b.dataset.dir; $$('#debtDirection button').forEach(x => x.classList.toggle('selected', x === b)); });
['#debtAmount', '#debtMoveAmount', '#depositAmount'].forEach(sel => { $(sel).oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; }; });
$('#debtSave').onclick = async () => {
  try {
    const d = await request('api.php?action=debt_save', {method: 'POST', body: JSON.stringify({id: debtEditing?.id, person: $('#debtPerson').value, direction: debtDir,
      amount: digitsOnly($('#debtAmount').value), note: $('#debtNote').value, due_on: $('#debtDue').value})});
    haptic('success'); notice(debtEditing ? 'Сохранено ✓' : 'Долг записан ✓', true); closeDebtModal(); renderDebts(d.debts);
  } catch(e) { haptic('error'); notice(e.message); }
};
async function debtMove(sign){
  const v = Number(digitsOnly($('#debtMoveAmount').value));
  if (!v) { notice('Введите сумму'); $('#debtMoveAmount').focus(); return; }
  try {
    const d = await request('api.php?action=debt_move', {method: 'POST', body: JSON.stringify({id: debtEditing.id, amount: v * sign, note: sign < 0 ? 'Возврат' : 'Добавлено'})});
    haptic('success');
    const updated = d.debts.find(x => x.id === debtEditing.id);
    notice(updated ? 'Записано ✓' : 'Долг погашен полностью 🎉', true);
    closeDebtModal(); renderDebts(d.debts);
  } catch(e) { haptic('error'); notice(e.message); }
}
$('#debtRepayBtn').onclick = () => debtMove(-1);
$('#debtMoreBtn').onclick = () => debtMove(1);
$('#debtDelete').onclick = async () => {
  if (!debtEditing || !(await showConfirm(`Удалить запись «${debtEditing.person}» вместе с историей?`))) return;
  try { const d = await request('api.php?action=debt_delete', {method: 'POST', body: JSON.stringify({id: debtEditing.id})}); closeDebtModal(); renderDebts(d.debts); notice('Удалено', true); }
  catch(e) { notice(e.message); }
};
$('#debtsOpen').onclick = openDebtsSheet;
$('#debtAdd2').onclick = () => openDebtModal();
$$('#debtsSheet [data-debts-close]').forEach(el => el.onclick = closeDebtsSheet);

/* ========== DEPOSITS ========== */
// Several named deposits; each has its own journal and its balance is the sum of the journal
let depositsCache = [], depositEditing = null, depositOpen = null, depositKind = 'in';
const DEPOSIT_KIND_LABEL = {init: 'Открытие', in: 'Пополнение', out: 'Снятие', interest: 'Проценты'};
const plural = (n, one, few, many) => { const m10 = n % 10, m100 = n % 100; return m10 === 1 && m100 !== 11 ? one : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many; };

function renderDeposits(deposits){
  depositsCache = deposits || [];
  const c = cur(currency);
  const total = depositsCache.reduce((s, d) => s + (d.amount_kzt ?? d.amount), 0);
  $('#depositTileSub').textContent = depositsCache.length ? `${depositsCache.length} ${plural(depositsCache.length, 'депозит', 'депозита', 'депозитов')} · ${money(total)} ${c}` : 'Добавить депозит';
  if (!$('#depositsSheet').hidden) renderDepositsSheet();
  if (depositOpen && !$('#depositSheet').hidden) {
    const fresh = depositsCache.find(d => d.id === depositOpen.id);
    if (fresh) openDeposit(fresh); else closeDeposit();
  }
}

function renderDepositsSheet(){
  const c = cur(currency);
  const total = depositsCache.reduce((s, d) => s + (d.amount_kzt ?? d.amount), 0);
  $('#depositsTotal').innerHTML = `<span>Всего на депозитах</span><b>${money(total)} ${c}</b>`;
  $('#depositsList').innerHTML = depositsCache.length ? depositsCache.map(d => `
    <button type="button" class="debt-row" data-deposit="${d.id}">
      <span class="debt-row-main"><b>${safe(d.title)}</b><small>${[d.bank && safe(d.bank), d.rate !== null && String(d.rate).replace('.', ',') + '% годовых', d.moves + ' ' + plural(d.moves, 'операция', 'операции', 'операций')].filter(Boolean).join(' · ')}</small></span>
      <b>${d.currency && d.currency !== 'KZT' ? `${fmtCur(d.amount, d.currency)}<small class="fx-sub">≈ ${money(d.amount_kzt)} ${c}</small>` : `${money(d.amount)} ${c}`}</b>
    </button>`).join('') : '<div class="empty">Депозитов пока нет</div>';
  $$('#depositsList [data-deposit]').forEach(b => b.onclick = () => openDeposit(depositsCache.find(d => d.id === Number(b.dataset.deposit))));
}

function openDepositsSheet(){ renderDepositsSheet(); const s = $('#depositsSheet'); s.hidden = false; s.classList.remove('closing'); }
const closeDepositsSheet = () => animateClose($('#depositsSheet'));

// One deposit: balance, quick operation form and its journal
async function openDeposit(dep){
  depositOpen = dep;
  const c = cur(currency);
  $('#depositSheetTitle').textContent = dep.title;
  const dc = dep.currency || 'KZT';
  const dcs = cur(dc);
  $('#depositBalance').innerHTML = `<span>${[dep.bank && safe(dep.bank), dep.rate !== null && String(dep.rate).replace('.', ',') + '% годовых'].filter(Boolean).join(' · ') || 'Баланс'}</span><b>${fmtCur(dep.amount, dc)}</b>${dc !== 'KZT' ? `<small class="fx-sub">≈ ${money(dep.amount_kzt)} ${c} по курсу Нацбанка</small>` : ''}`;
  $('#depositMoveAmount').placeholder = `Сумма, ${dcs}`;
  // Year ahead with monthly capitalisation at the stated rate
  if (dep.rate && dep.amount > 0) {
    const year = dep.amount * Math.pow(1 + dep.rate / 100 / 12, 12);
    $('#depositForecast').textContent = `Через год ≈ ${money(year)} ${dcs} (+${money(year - dep.amount)} ${dcs} процентов при ставке ${String(dep.rate).replace('.', ',')}% с ежемесячной капитализацией)`;
    $('#depositForecast').hidden = false;
  } else $('#depositForecast').hidden = true;
  depositKind = 'in';
  $$('#depositKind button').forEach(b => b.classList.toggle('selected', b.dataset.kind === 'in'));
  $('#depositMoveAmount').value = ''; $('#depositMoveNote').value = '';
  const s = $('#depositSheet'); s.hidden = false; s.classList.remove('closing');
  $('#depositJournal').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try {
    const d = await request('api.php?action=deposit&id=' + dep.id);
    $('#depositJournal').innerHTML = d.moves.length ? d.moves.map(m => `
      <div class="journal-row">
        <span class="journal-main"><b>${DEPOSIT_KIND_LABEL[m.kind] || safe(m.label)}</b><small>${fmtDate(m.occurred_on)}${m.note ? ' · ' + safe(m.note) : ''}</small></span>
        <b class="${m.amount < 0 ? 'money-neg' : 'money-pos'}">${m.amount < 0 ? '−' : '+'}${money(Math.abs(m.amount))}</b>
        <button type="button" class="tx-delete" data-move="${m.id}" aria-label="Удалить операцию">×</button>
      </div>`).join('') : '<div class="empty">Операций пока нет</div>';
    $$('#depositJournal [data-move]').forEach(b => b.onclick = async () => {
      if (!(await showConfirm('Удалить эту операцию из журнала?'))) return;
      try { const r = await request('api.php?action=deposit_move_delete', {method: 'POST', body: JSON.stringify({id: Number(b.dataset.move)})}); renderDeposits(r.deposits); notice('Удалено', true); }
      catch(e) { notice(e.message); }
    });
  } catch(e) { $('#depositJournal').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
function closeDeposit(){ depositOpen = null; return animateClose($('#depositSheet')); }

$$('#depositKind button').forEach(b => b.onclick = () => { depositKind = b.dataset.kind; haptic('select'); $$('#depositKind button').forEach(x => x.classList.toggle('selected', x === b)); });
$('#depositMoveSave').onclick = async () => {
  const v = Number(digitsOnly($('#depositMoveAmount').value));
  if (!v) { notice('Введите сумму'); $('#depositMoveAmount').focus(); return; }
  try {
    const d = await request('api.php?action=deposit_move', {method: 'POST', body: JSON.stringify({id: depositOpen.id, kind: depositKind, amount: v, note: $('#depositMoveNote').value})});
    haptic('success'); notice({in: 'Пополнение записано ✓', out: 'Снятие записано ✓', interest: 'Проценты записаны ✓'}[depositKind], true); renderDeposits(d.deposits);
  } catch(e) { haptic('error'); notice(e.message); }
};
$('#depositEdit').onclick = () => openDepositModal(depositOpen);

function openDepositModal(dep = null){
  depositEditing = dep;
  $('#depositTitle').textContent = dep ? 'Изменить депозит' : 'Новый депозит';
  $('#depositName').value = dep ? dep.title : '';
  $('#depositBank').value = dep ? dep.bank : '';
  $('#depositAmount').value = '';
  $('#depositAmountLabel').hidden = Boolean(dep);
  $('#depositRate').value = dep && dep.rate !== null ? String(dep.rate).replace('.', ',') : '';
  $('#depositNote').value = dep ? dep.note : '';
  $('#depositDelete').hidden = !dep;
  depositCurrency = 'KZT';
  $('#depositCurrencyRow').hidden = Boolean(dep);
  $$('#depositCurrency button').forEach(b => b.classList.toggle('selected', b.dataset.cur === 'KZT'));
  $('#depositModal').hidden = false;
  if (!dep) setTimeout(() => $('#depositName').focus(), 100);
}
const closeDepositModal = () => { $('#depositModal').hidden = true; };
let depositCurrency = 'KZT';
$$('#depositCurrency button').forEach(b => b.onclick = () => { depositCurrency = b.dataset.cur; haptic('select'); $$('#depositCurrency button').forEach(x => x.classList.toggle('selected', x === b)); });
$$('#depositModal [data-deposit-close]').forEach(el => el.onclick = closeDepositModal);
$('#depositSave').onclick = async () => {
  try {
    const d = await request('api.php?action=deposit_save', {method: 'POST', body: JSON.stringify({id: depositEditing?.id, title: $('#depositName').value, bank: $('#depositBank').value,
      amount: digitsOnly($('#depositAmount').value), rate: $('#depositRate').value, note: $('#depositNote').value, currency: depositCurrency})});
    haptic('success'); notice(depositEditing ? 'Сохранено ✓' : 'Депозит добавлен ✓', true); closeDepositModal(); renderDeposits(d.deposits);
    if (!depositEditing) { const created = d.deposits.find(x => x.id === d.id); if (created) openDeposit(created); }
  } catch(e) { haptic('error'); notice(e.message); }
};
$('#depositDelete').onclick = async () => {
  if (!depositEditing || !(await showConfirm(`Удалить депозит «${depositEditing.title}» вместе с журналом?`))) return;
  try { const d = await request('api.php?action=deposit_delete', {method: 'POST', body: JSON.stringify({id: depositEditing.id})}); closeDepositModal(); renderDeposits(d.deposits); notice('Удалено', true); }
  catch(e) { notice(e.message); }
};
$('#depositsOpen').onclick = openDepositsSheet;
$('#depositAdd').onclick = () => openDepositModal();
$$('#depositsSheet [data-deposits-close]').forEach(el => el.onclick = closeDepositsSheet);
$$('#depositSheet [data-deposit-sheet-close]').forEach(el => el.onclick = closeDeposit);
$('#depositMoveAmount').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };

/* ========== DATA RESET ========== */
function renderResetStatus(r){
  const el = $('#resetStatus');
  if (!r) { el.hidden = true; $('#resetRequest').disabled = false; $('#resetRequest').textContent = 'Сбросить все данные'; return; }
  el.hidden = false;
  el.innerHTML = `Запрос от ${safe(r.initiator)} ждёт подтверждения в Telegram:<br>` + r.members.map(m => `${m.approved ? '✅' : '⏳'} ${safe(m.name)}`).join(' · ');
  $('#resetRequest').disabled = true;
  $('#resetRequest').textContent = 'Ждём подтверждения';
}
$('#resetRequest').onclick = async () => {
  if (!(await showConfirm('Сбросить все данные бюджета? Бот попросит подтвердить вас обоих в Telegram.'))) return;
  try {
    const d = await request('api.php?action=reset_request', {method: 'POST', body: '{}'});
    haptic('warning');
    renderResetStatus(d.reset);
    notice('Запрос отправлен в Telegram — подтвердите его оба', 'warn');
  } catch(e) { haptic('error'); notice(e.message); }
};

/* ========== CAPITAL ("Всё наше") ========== */
function renderCapital(d){
  const el = $('#capitalCard');
  const dep = (d.deposits || []).reduce((s, x) => s + (x.amount_kzt ?? x.amount), 0);
  const t = {owed_to_me: 0, i_owe: 0};
  (d.debts || []).forEach(x => { if (!x.closed) t[x.direction] += x.amount; });
  if (!dep && !t.owed_to_me && !t.i_owe) { el.hidden = true; return; }
  const c = cur(currency);
  const bal = d.balances.shared.balance;
  const total = bal + dep + t.owed_to_me - t.i_owe;
  el.innerHTML = `
    <div class="capital-top"><span>Всё наше</span><b>${total < 0 ? '−' : ''}${money(Math.abs(total))} ${c}</b></div>
    <div class="capital-parts">
      <span>Остаток ${bal < 0 ? '−' : ''}${money(Math.abs(bal))}</span>
      ${dep ? `<span>Депозиты ${money(dep)}</span>` : ''}
      ${t.owed_to_me ? `<span class="money-pos">Нам должны +${money(t.owed_to_me)}</span>` : ''}
      ${t.i_owe ? `<span class="money-neg">Мы должны −${money(t.i_owe)}</span>` : ''}
    </div>`;
  el.hidden = false;
}

/* ========== ACCOUNTS ========== */
let accountsCache = [], accountOpen = null, accountEditing = null, accountKind = 'card';
const ACCOUNT_KIND = {card: 'Карта', cash: 'Наличные', other: 'Другое'};
function renderAccounts(list){
  accountsCache = list || [];
  const c = cur(currency);
  const total = accountsCache.reduce((s, a) => s + a.balance, 0);
  $('#accountTileSub').textContent = accountsCache.length ? `${accountsCache.length} ${plural(accountsCache.length, 'счёт', 'счёта', 'счетов')} · ${total < 0 ? '−' : ''}${money(Math.abs(total))} ${c}` : 'Карты и наличные';
  if (!$('#accountsSheet').hidden) renderAccountsSheet();
  renderAccountRow();
}
function renderAccountsSheet(){
  const c = cur(currency);
  const total = accountsCache.reduce((s, a) => s + a.balance, 0);
  $('#accountsTotal').innerHTML = `<span>На всех счетах</span><b>${total < 0 ? '−' : ''}${money(Math.abs(total))} ${c}</b>`;
  $('#accountsList').innerHTML = accountsCache.length ? accountsCache.map(a => `
    <button type="button" class="debt-row" data-account="${safe(a.name)}">
      <span class="debt-row-main"><b>${safe(a.name)}</b><small>${ACCOUNT_KIND[a.kind] || 'Счёт'} · ${a.count} ${plural(a.count, 'операция', 'операции', 'операций')}</small></span>
      <b class="${a.balance < 0 ? 'money-neg' : ''}">${a.balance < 0 ? '−' : ''}${money(Math.abs(a.balance))} ${c}</b>
    </button>`).join('') : '<div class="empty">Счетов пока нет</div>';
  $$('#accountsList [data-account]').forEach(b => b.onclick = () => openAccount(accountsCache.find(a => a.name === b.dataset.account)));
}
const openAccountsSheet = () => { renderAccountsSheet(); const s = $('#accountsSheet'); s.hidden = false; s.classList.remove('closing'); };
const closeAccountsSheet = () => animateClose($('#accountsSheet'));
async function openAccount(a){
  accountOpen = a;
  const c = cur(currency);
  $('#accountSheetTitle').textContent = a.name;
  $('#accountBalance').innerHTML = `<span>${ACCOUNT_KIND[a.kind] || 'Счёт'} · остаток в бюджете</span><b class="${a.balance < 0 ? 'money-neg' : ''}">${a.balance < 0 ? '−' : ''}${money(Math.abs(a.balance))} ${c}</b>`;
  $('#accountRealBalance').value = '';
  const s = $('#accountSheet'); s.hidden = false; s.classList.remove('closing');
  $('#accountOps').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try {
    const d = await request('api.php?action=account&name=' + encodeURIComponent(a.name));
    renderTxList($('#accountOps'), d.operations, 'Операций по этому счёту пока нет');
  } catch(e) { $('#accountOps').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
const closeAccount = () => { accountOpen = null; return animateClose($('#accountSheet')); };
$('#accountReconcile').onclick = async () => {
  const v = $('#accountRealBalance').value.replace(/\s/g, '').replace(',', '.');
  if (v === '' || isNaN(Number(v))) { notice('Введите, сколько на счёте на самом деле'); return; }
  try {
    const d = await request('api.php?action=account_reconcile', {method: 'POST', body: JSON.stringify({name: accountOpen.name, balance: v})});
    haptic('success');
    notice(d.diff ? `Сверено: корректировка ${d.diff > 0 ? '+' : '−'}${money(Math.abs(d.diff))} ${cur(currency)}` : 'Остаток уже совпадает ✓', true);
    renderAccounts(d.accounts);
    const fresh = d.accounts.find(x => x.name === accountOpen.name); if (fresh) openAccount(fresh);
    reloadAfterChange().catch(() => {});
  } catch(e) { haptic('error'); notice(e.message); }
};
function openAccountModal(a = null){
  accountEditing = a; accountKind = a ? a.kind : 'card';
  $('#accountModalTitle').textContent = a ? 'Счёт' : 'Новый счёт';
  $('#accountName').value = a ? a.name : '';
  $$('#accountKind button').forEach(b => b.classList.toggle('selected', b.dataset.kind === accountKind));
  $('#accountDelete').hidden = !a;
  $('#accountModal').hidden = false;
  if (!a) setTimeout(() => $('#accountName').focus(), 100);
}
const closeAccountModal = () => { $('#accountModal').hidden = true; };
$$('#accountKind button').forEach(b => b.onclick = () => { accountKind = b.dataset.kind; $$('#accountKind button').forEach(x => x.classList.toggle('selected', x === b)); });
$('#accountSave').onclick = async () => {
  try {
    const d = await request('api.php?action=account_save', {method: 'POST', body: JSON.stringify({old_name: accountEditing?.name || '', name: $('#accountName').value, kind: accountKind})});
    haptic('success'); notice('Счёт сохранён ✓', true); closeAccountModal();
    if (accountEditing && !$('#accountSheet').hidden) closeAccount();
    renderAccounts(d.accounts);
  } catch(e) { haptic('error'); notice(e.message); }
};
$('#accountDelete').onclick = async () => {
  if (!accountEditing || !(await showConfirm(`Убрать счёт «${accountEditing.name}»? Операции останутся, но без счёта; сверки по нему удалятся.`))) return;
  try { const d = await request('api.php?action=account_delete', {method: 'POST', body: JSON.stringify({name: accountEditing.name})}); closeAccountModal(); closeAccount(); renderAccounts(d.accounts); notice('Счёт убран', true); reloadAfterChange().catch(() => {}); }
  catch(e) { notice(e.message); }
};
$('#accountsOpen').onclick = openAccountsSheet;
$('#accountAdd').onclick = () => openAccountModal();
$('#accountEdit').onclick = () => openAccountModal(accountOpen);
$$('#accountsSheet [data-accounts-close]').forEach(el => el.onclick = closeAccountsSheet);
$$('#accountSheet [data-account-sheet-close]').forEach(el => el.onclick = closeAccount);
$$('#accountModal [data-account-close]').forEach(el => el.onclick = closeAccountModal);

// Account choice in the entry form; the last one used is remembered on this device
function renderAccountRow(){
  const row = $('#accountRow');
  if (!row) return;
  $('#accountField').hidden = !accountsCache.length || sheetState.kind === 'transfer';
  const names = accountsCache.map(a => a.name);
  if (sheetState.account && !names.includes(sheetState.account)) names.push(sheetState.account);
  row.innerHTML = [`<button type="button" data-account="" class="${!sheetState.account ? 'selected' : ''}">Без счёта</button>`, ...names.map(n => `<button type="button" data-account="${safe(n)}" class="${sheetState.account === n ? 'selected' : ''}">${safe(n)}</button>`)].join('');
  $$('#accountRow button').forEach(b => b.onclick = () => {
    sheetState.account = b.dataset.account;
    try { localStorage.setItem('fb_last_account', sheetState.account); } catch {}
    haptic('select'); renderAccountRow();
  });
}

/* ========== CATEGORY MANAGER (profile) ========== */
let catsCache = [], catEditing = null, catEditGroup = 'variable';
async function openCatsSheet(){
  const s = $('#catsSheet'); s.hidden = false; s.classList.remove('closing');
  $('#catsList').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try { renderCats((await request('api.php?action=categories')).items); } catch(e) { $('#catsList').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
const closeCatsSheet = () => animateClose($('#catsSheet'));
function renderCats(items){
  catsCache = items;
  const c = cur(currency);
  const group = (g, title) => `<div class="limit-group-title">${title}</div>` + items.filter(x => x.group === g).map(x => `
    <button type="button" class="debt-row" data-cat="${safe(x.name)}">
      <span class="debt-row-main"><b><i class="dot-mark ${x.group}"></i> ${safe(x.name)}</b><small>${x.count ? `${x.count} ${plural(x.count, 'операция', 'операции', 'операций')} · ${money(x.total)} ${c}` : 'операций нет'}</small></span>
      <i class="drill-arrow">›</i>
    </button>`).join('');
  $('#catsList').innerHTML = group('fixed', 'Обязательные') + group('variable', 'Переменные') + '<div id="rulesBlock"></div>';
  loadRules();
  $$('#catsList [data-cat]').forEach(b => b.onclick = () => openCatEdit(catsCache.find(x => x.name === b.dataset.cat)));
}
function openCatEdit(cat){
  catEditing = cat; catEditGroup = cat.group;
  $('#catEditTitle').textContent = cat.name;
  $('#catEditName').value = cat.name;
  $$('#catEditGroup button').forEach(b => b.classList.toggle('selected', b.dataset.group === catEditGroup));
  $('#catEditHelp').textContent = cat.count ? `Изменения применятся к ${cat.count} ${plural(cat.count, 'операции', 'операциям', 'операциям')}, лимитам и регулярным платежам. Если назвать так же, как другую категорию, они объединятся.` : 'По этой категории операций пока нет.';
  $('#catMoveTo').innerHTML = catsCache.filter(x => x.name !== cat.name).map(x => `<option ${x.name === 'Другое' ? 'selected' : ''}>${safe(x.name)}</option>`).join('');
  $('#catEditModal').hidden = false;
}
const closeCatEdit = () => { $('#catEditModal').hidden = true; };
$$('#catEditGroup button').forEach(b => b.onclick = () => { catEditGroup = b.dataset.group; $$('#catEditGroup button').forEach(x => x.classList.toggle('selected', x === b)); });
async function afterCategoryChange(d, msg){
  categoryGroups = d.category_groups || categoryGroups;
  if (mainCache) mainCache.category_groups = categoryGroups;
  renderCats(d.items); closeCatEdit(); haptic('success'); notice(msg, true);
  reloadAfterChange().catch(() => {});
}
$('#catEditSave').onclick = async () => {
  try { await afterCategoryChange(await request('api.php?action=category_update', {method: 'POST', body: JSON.stringify({name: catEditing.name, new_name: $('#catEditName').value, group: catEditGroup})}), 'Категория сохранена ✓'); }
  catch(e) { haptic('error'); notice(e.message); }
};
$('#catDelete').onclick = async () => {
  const to = $('#catMoveTo').value;
  if (!(await showConfirm(`Удалить «${catEditing.name}»? ${catEditing.count ? `Операции (${catEditing.count}) перейдут в «${to}».` : ''}`))) return;
  try { await afterCategoryChange(await request('api.php?action=category_delete', {method: 'POST', body: JSON.stringify({name: catEditing.name, move_to: to})}), 'Категория удалена'); }
  catch(e) { haptic('error'); notice(e.message); }
};
$('#categoriesOpen').onclick = openCatsSheet;
$('#catsAdd').onclick = () => openCustomCatModal('manager');
$$('#catsSheet [data-cats-close]').forEach(el => el.onclick = closeCatsSheet);
$$('#catEditModal [data-catedit-close]').forEach(el => el.onclick = closeCatEdit);

/* ========== BIG EXPENSE THRESHOLD ========== */
function renderSettings(s){ if (s && document.activeElement !== $('#bigExpenseInput')) $('#bigExpenseInput').value = s.big_expense_threshold ? money(s.big_expense_threshold) : '0'; }
$('#bigExpenseInput').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };
$('#bigExpenseInput').onchange = async () => {
  try { const d = await request('api.php?action=settings', {method: 'POST', body: JSON.stringify({big_expense_threshold: digitsOnly($('#bigExpenseInput').value) || '0'})}); renderSettings(d.settings); notice(d.settings.big_expense_threshold ? `Сообщим о тратах от ${money(d.settings.big_expense_threshold)} ${cur(currency)}` : 'Уведомления о крупных тратах выключены', true); }
  catch(e) { notice(e.message); }
};

/* ========== REPORT CHARTS ========== */
// Single series: one hue; the current period's month is the darker accent
function renderMonthsChart(months, periodFrom){
  const el = $('#monthsChart');
  if (!months || !months.length) { el.innerHTML = '<div class="empty">Нет данных</div>'; return; }
  const c = cur(currency);
  const max = Math.max(1, ...months.map(m => m.expenses));
  const cur7 = (periodFrom || '').slice(0, 7);
  el.innerHTML = `<div class="mbars" role="img" aria-label="Расходы по месяцам: ${months.map(m => MONTHS[Number(m.month.slice(5)) - 1] + ' ' + money(m.expenses)).join(', ')}">${months.map(m => {
    const h = m.expenses / max * 100;
    const label = MONTHS_SHORT[Number(m.month.slice(5)) - 1];
    return `<div class="mbar ${m.month === cur7 ? 'current' : ''}" tabindex="0" title="${MONTHS[Number(m.month.slice(5)) - 1]} ${m.month.slice(0, 4)}: ${money(m.expenses)} ${c}">
      <span class="mbar-val">${m.expenses ? (m.expenses >= 1000 ? (Math.round(m.expenses / 100) / 10).toString().replace('.', ',') + 'k' : money(m.expenses)) : ''}</span>
      <i style="height:${Math.max(m.expenses ? 3 : 0, h)}%"></i><span class="mbar-label">${label}</span></div>`;
  }).join('')}</div>`;
}

// Part-to-whole: one stacked bar (top 5 + other) with a legend that carries names and values
const SHARE_COLORS = ['var(--cat-1)', 'var(--cat-2)', 'var(--cat-3)', 'var(--cat-4)', 'var(--cat-5)', 'var(--cat-other)'];
function renderShareChart(categories, total){
  const el = $('#shareChart');
  const entries = Object.entries(categories || {}).map(([n, v]) => [n, v.total]).sort((a, b) => b[1] - a[1]);
  if (!entries.length || !total) { el.innerHTML = '<div class="empty">Трат за период нет</div>'; return; }
  const top = entries.slice(0, 5);
  const rest = entries.slice(5).reduce((s, x) => s + x[1], 0);
  if (rest > 0) top.push(['Другие', rest]);
  const c = cur(currency);
  el.innerHTML = `<div class="share-bar" role="img" aria-label="Структура расходов">${top.map(([n, v], i) => `<i style="flex:${v};background:${SHARE_COLORS[i]}" title="${safe(n)}: ${money(v)} ${c}"></i>`).join('')}</div>
    <div class="share-legend">${top.map(([n, v], i) => `<button type="button" class="share-item" ${n !== 'Другие' ? `data-cat="${safe(n)}"` : 'disabled'}>
      <i style="background:${SHARE_COLORS[i]}"></i><span>${safe(n)}</span><b>${money(v)} ${c}</b><small>${Math.round(v / total * 100)}%</small></button>`).join('')}</div>`;
  $$('#shareChart .share-item[data-cat]').forEach(b => b.onclick = () => openDrill({from: reportCache.from, to: reportCache.to, group: 'all', category: b.dataset.cat, view: 'transactions', title: b.dataset.cat + ' · ' + periodRange().title}));
}

/* ========== EXPORT ========== */
$('#export').onclick = () => { const m = $('#exportMenu'); m.hidden = !m.hidden; $('#export').setAttribute('aria-expanded', String(!m.hidden)); };
$$('#exportMenu [data-export]').forEach(b => b.onclick = async () => {
  const fmt = b.dataset.export;
  const from = reportCache?.from, to = reportCache?.to;
  b.disabled = true;
  try {
    if (inTelegram && fmt !== 'csv') {
      await request('api.php?action=export_send', {method: 'POST', body: JSON.stringify({format: fmt, from, to})});
      haptic('success'); notice('Файл отправлен в чат с ботом 📎', true);
    } else {
      const u = new URL('api.php', location.href);
      u.searchParams.set('action', fmt === 'csv' ? 'export' : 'export_' + fmt);
      if (from) { u.searchParams.set('from', from); u.searchParams.set('to', to); }
      const r = await fetch(u.toString(), {headers: headers(), credentials: 'same-origin'});
      if (!r.ok) throw Error('Не удалось подготовить файл');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(await r.blob());
      a.download = `family-budget-${from || 'all'}-${to || 'all'}.${fmt}`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }
  } catch(e) { haptic('error'); notice(e.message); }
  finally { b.disabled = false; }
});

/* ========== THEME ========== */
function themePref(){ try { return localStorage.getItem('fb_theme') || 'auto'; } catch { return 'auto'; } }
function applyTheme(){
  const pref = themePref();
  const systemDark = inTelegram && tg.colorScheme ? tg.colorScheme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  const dark = pref === 'dark' || (pref === 'auto' && systemDark);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  $$('#themeToggle button').forEach(b => b.classList.toggle('selected', b.dataset.themeChoice === pref));
  const bg = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim() || (dark ? '#0B0F17' : '#F5F6F8');
  document.querySelector('meta[name=theme-color]')?.setAttribute('content', bg);
  if (inTelegram) { try { tg.setHeaderColor?.(bg); tg.setBackgroundColor?.(bg); } catch {} }
}
$$('#themeToggle button').forEach(b => b.onclick = () => { try { localStorage.setItem('fb_theme', b.dataset.themeChoice); } catch {} haptic('select'); applyTheme(); });
matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', applyTheme);
tg?.onEvent?.('themeChanged', applyTheme);

/* ========== SIMPLE SHEETS (shopping, plan, calendar, log, trash) ========== */
function openLayer(sel){ const s = $(sel); s.hidden = false; s.classList.remove('closing'); }
const closeLayer = sel => animateClose($(sel));
$$('[data-layer-close]').forEach(el => el.onclick = () => closeLayer('#' + el.closest('.sheet').id));
const monthLabel = p => `${MONTHS[Number(p.slice(5, 7)) - 1]} ${p.slice(0, 4)}`;
const shiftMonth = (p, dir) => { const d = new Date(Number(p.slice(0, 4)), Number(p.slice(5, 7)) - 1 + dir, 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; };
const shortMoney = v => v >= 1000000 ? String(Math.round(v / 100000) / 10).replace('.', ',') + 'м' : v >= 1000 ? String(Math.round(v / 100) / 10).replace('.', ',') + 'к' : String(Math.round(v));
// Stored in UTC by the database
const fmtStamp = at => { const d = new Date(String(at).replace(' ', 'T') + 'Z'); return isNaN(d) ? at : `${d.getDate()} ${MONTHS_SHORT[d.getMonth()]}, ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; };

/* ----- shopping list ----- */
let shopItems = [];
function renderShopTile(n){ $('#shopTileSub').textContent = n ? `Купить: ${n} ${plural(n, 'позиция', 'позиции', 'позиций')}` : 'Общий список'; }
function renderShop(){
  $('#shopList').innerHTML = shopItems.length ? shopItems.map(i => `
    <div class="shop-row ${i.done ? 'done' : ''}">
      <button type="button" class="shop-check" data-shop-toggle="${i.id}" aria-pressed="${i.done}" aria-label="${i.done ? 'Вернуть в список' : 'Отметить купленным'}: ${safe(i.title)}"><i>${i.done ? '✓' : ''}</i><span>${safe(i.title)}</span></button>
      <button type="button" class="tx-delete" data-shop-del="${i.id}" aria-label="Удалить">×</button>
    </div>`).join('') : '<div class="empty">Список пуст — добавьте, что купить</div>';
  $('#shopActions').hidden = !shopItems.some(i => i.done);
  $$('[data-shop-toggle]').forEach(b => b.onclick = () => { haptic('select'); shopOp({op: 'toggle', id: Number(b.dataset.shopToggle)}); });
  $$('[data-shop-del]').forEach(b => b.onclick = () => shopOp({op: 'delete', id: Number(b.dataset.shopDel)}));
}
async function shopOp(body){
  try {
    const d = await request('api.php?action=shopping', {method: 'POST', body: JSON.stringify(body)});
    shopItems = d.items; renderShop(); renderShopTile(shopItems.filter(i => !i.done).length);
  } catch(e) { haptic('error'); notice(e.message); }
}
$('#shopOpen').onclick = async () => {
  openLayer('#shopSheet');
  $('#shopList').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try { shopItems = (await request('api.php?action=shopping')).items; renderShop(); } catch(e) { $('#shopList').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
};
const shopAdd = () => { const v = $('#shopInput').value.trim(); if (!v) { $('#shopInput').focus(); return; } $('#shopInput').value = ''; haptic('light'); shopOp({op: 'add', text: v}); };
$('#shopAddBtn').onclick = shopAdd;
$('#shopInput').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); shopAdd(); } };
$('#shopClear').onclick = () => shopOp({op: 'clear'});
// Bought items become one expense: the note lists them, after saving they leave the list
$('#shopRecord').onclick = async () => {
  const titles = shopItems.filter(i => i.done).map(i => i.title);
  await closeLayer('#shopSheet');
  openSheet();
  const groceries = [...(categoryGroups.variable || []), ...(categoryGroups.fixed || [])].find(c => c === 'Продукты');
  if (groceries) { sheetState.category = groceries; sheetState.group = (categoryGroups.fixed || []).includes(groceries) ? 'fixed' : 'variable'; renderCatGrid(); }
  $('#noteInput').value = titles.join(', ').slice(0, 500);
  sheetInitial = sheetSnapshot();
  afterSaveHook = () => shopOp({op: 'clear'});
};

/* ----- monthly plan (envelopes) ----- */
let planPeriod = todayISO().slice(0, 7), planData = null;
function renderPlanTile(p){
  const c = cur(currency);
  $('#planTileSub').innerHTML = p.has ? `${money(p.spent)} из ${money(p.planned)} ${c}${p.over ? ` · <span class="money-neg">${p.over} сверх</span>` : ''}` : 'Распределить доход';
}
function renderPlan(){
  const d = planData, c = cur(currency);
  $('#planMonth').textContent = monthLabel(planPeriod);
  $('#planNext').disabled = planPeriod >= shiftMonth(todayISO().slice(0, 7), 1);
  $('#planSummary').innerHTML = `
    <div><span>Доход</span><b>${money(d.income)} ${c}</b></div>
    <div><span>В плане</span><b>${money(d.planned)} ${c}</b></div>
    <div><span>${d.unallocated < 0 ? 'Не хватает' : 'Свободно'}</span><b class="${d.unallocated < 0 ? 'money-neg' : 'money-pos'}">${d.unallocated < 0 ? '−' : ''}${money(Math.abs(d.unallocated))} ${c}</b></div>`;
  const planned = d.items.filter(i => i.planned > 0), loose = d.items.filter(i => !(i.planned > 0) && i.spent > 0);
  $('#planList').innerHTML = (planned.length ? planned.map(i => {
    const share = Math.min(1, i.spent / i.planned), over = i.left < 0;
    return `<div class="plan-row">
      <div class="plan-row-top"><b>${safe(i.category)}</b><span>${money(i.spent)} из ${money(i.planned)} ${c}</span></div>
      <div class="plan-bar"><i class="${over ? 'over' : share > .85 ? 'warn' : ''}" style="width:${Math.max(2, share * 100)}%"></i></div>
      <small class="${over ? 'money-neg' : ''}">${over ? `⚠ перерасход ${money(-i.left)} ${c}` : `осталось ${money(i.left)} ${c}`}</small>
    </div>`;
  }).join('') : '<div class="empty">План на этот месяц ещё не составлен. Нажмите «Распределить» и разложите доход по категориям.</div>')
  + (loose.length ? `<div class="limit-group-title">Без плана</div>` + loose.map(i => `<div class="journal-row"><span class="journal-main"><b>${safe(i.category)}</b></span><b>${money(i.spent)} ${c}</b></div>`).join('') : '');
  $('#planCopy').hidden = planned.length > 0;
}
async function loadPlan(){
  $('#planList').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  $('#planEdit').hidden = true; $('#planButtons').hidden = false;
  try { planData = await request('api.php?action=plan&period=' + planPeriod); renderPlan(); }
  catch(e) { $('#planList').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
$('#planOpen').onclick = () => { planPeriod = todayISO().slice(0, 7); openLayer('#planSheet'); loadPlan(); };
$('#planPrev').onclick = () => { planPeriod = shiftMonth(planPeriod, -1); loadPlan(); };
$('#planNext').onclick = () => { planPeriod = shiftMonth(planPeriod, 1); loadPlan(); };
$('#planEditBtn').onclick = () => {
  const have = Object.fromEntries(planData.items.map(i => [i.category, i.planned]));
  $('#planForm').innerHTML = planData.categories.map(cat => `
    <label class="limit-row"><span>${safe(cat)}</span><span class="limit-input"><input data-plan-cat="${safe(cat)}" inputmode="numeric" autocomplete="off" placeholder="—" value="${have[cat] ? money(have[cat]) : ''}"><b>${cur(currency)}</b></span></label>`).join('');
  $$('#planForm input').forEach(i => i.oninput = () => { const v = digitsOnly(i.value); i.value = v ? money(Number(v)) : ''; });
  $('#planEdit').hidden = false; $('#planButtons').hidden = true;
  $('#planEdit').scrollIntoView({behavior: 'smooth', block: 'start'});
};
async function planSave(body, ok){
  try {
    planData = await request('api.php?action=plan_save', {method: 'POST', body: JSON.stringify({period: planPeriod, ...body})});
    haptic('success'); notice(ok, true);
    $('#planEdit').hidden = true; $('#planButtons').hidden = false; renderPlan();
    if (planPeriod === todayISO().slice(0, 7)) { mainCache = null; loadMain().catch(() => {}); }
  } catch(e) { haptic('error'); notice(e.message); }
}
$('#planSave').onclick = () => {
  const items = {};
  $$('#planForm input').forEach(i => { const v = digitsOnly(i.value); if (Number(v) > 0) items[i.dataset.planCat] = v; });
  planSave({items}, 'План сохранён ✓');
};
$('#planCopy').onclick = () => planSave({copy_previous: true}, 'План перенесён из прошлого месяца ✓');

/* ----- month calendar ----- */
let calMonth = todayISO().slice(0, 7), calData = null, calDay = null;
const EV_ICON = {payment: '💳', debt: '🤝', interest: '🏦', goal: '🎯', salary: '💰'};
function renderCalendar(){
  const d = calData, c = cur(currency);
  $('#calMonthTitle').textContent = monthLabel(calMonth);
  const first = new Date(Number(calMonth.slice(0, 4)), Number(calMonth.slice(5, 7)) - 1, 1);
  const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
  const offset = (first.getDay() + 6) % 7;
  const today = todayISO();
  let html = '<span class="mcal-pad"></span>'.repeat(offset);
  for (let n = 1; n <= days; n++) {
    const iso = `${calMonth}-${String(n).padStart(2, '0')}`;
    const ev = d.events[iso] || [];
    const tones = [...new Set(ev.map(e => e.type === 'goal' ? 'goal' : e.tone === 'pos' ? 'pos' : e.tone === 'done' ? 'done' : 'neg'))];
    const spent = d.spent[iso];
    html += `<button type="button" class="mcal-day ${iso === today ? 'today' : ''} ${iso === calDay ? 'selected' : ''}" data-day="${iso}" aria-label="${n} ${MONTHS_GEN[first.getMonth()]}${ev.length ? ', событий: ' + ev.length : ''}${spent ? ', потрачено ' + money(spent) + ' ' + c : ''}">
      <b>${n}</b><span class="mcal-dots">${tones.map(t => `<i class="ev-${t}"></i>`).join('')}</span><small>${spent ? shortMoney(spent) : ''}</small></button>`;
  }
  $('#mcalGrid').innerHTML = html;
  $$('#mcalGrid [data-day]').forEach(b => b.onclick = () => { calDay = calDay === b.dataset.day ? null : b.dataset.day; haptic('select'); renderCalendar(); });
  const row = (iso, e) => `<div class="journal-row"><span class="journal-main"><b>${EV_ICON[e.type] || '•'} ${safe(e.title)}</b><small>${fmtDateFriendly(iso)}</small></span>${e.amount ? `<b class="${e.tone === 'pos' ? 'money-pos' : e.tone === 'neg' ? 'money-neg' : ''}">${e.tone === 'pos' ? '+' : e.tone === 'neg' ? '−' : ''}${money(e.amount)} ${c}</b>` : ''}</div>`;
  if (calDay) {
    const ev = d.events[calDay] || [];
    $('#mcalDayTitle').textContent = fmtDateFriendly(calDay);
    $('#mcalEvents').innerHTML = ev.map(e => row(calDay, e)).join('') + (d.spent[calDay] ? `<div class="journal-row"><span class="journal-main"><b>Потрачено за день</b></span><b>${money(d.spent[calDay])} ${c}</b></div>` : '') || '<div class="empty">В этот день ничего нет</div>';
  } else {
    $('#mcalDayTitle').textContent = 'События месяца';
    const all = Object.entries(d.events).flatMap(([iso, list]) => list.map(e => row(iso, e)));
    $('#mcalEvents').innerHTML = all.join('') || '<div class="empty">Регулярных платежей, долгов и целей с датами пока нет</div>';
  }
}
async function loadCalendar(){
  $('#mcalEvents').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try { calData = await request('api.php?action=calendar&month=' + calMonth); renderCalendar(); }
  catch(e) { $('#mcalEvents').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
$('#calendarOpen').onclick = () => { calMonth = todayISO().slice(0, 7); calDay = null; openLayer('#calendarSheet'); loadCalendar(); };
$('#calPrevM').onclick = () => { calMonth = shiftMonth(calMonth, -1); calDay = null; loadCalendar(); };
$('#calNextM').onclick = () => { calMonth = shiftMonth(calMonth, 1); calDay = null; loadCalendar(); };

/* ----- change log and trash ----- */
$('#auditOpen').onclick = async () => {
  openLayer('#auditSheet');
  $('#auditList').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try {
    const d = await request('api.php?action=audit');
    $('#auditList').innerHTML = d.items.length ? d.items.map(i => `
      <div class="journal-row"><span class="journal-main"><b>${safe(i.who)} ${safe(i.verb)}</b><small class="wrap">${safe(i.summary)}</small></span><small class="journal-time">${fmtStamp(i.at)}</small></div>`).join('') : '<div class="empty">Изменений пока нет</div>';
  } catch(e) { $('#auditList').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
};
function renderTrash(items){
  $('#trashList').innerHTML = items.length ? items.map(i => `
    <div class="journal-row"><span class="journal-main"><b class="wrap">${safe(i.summary)}</b><small>Удалил(а) ${safe(i.who)} · ${fmtStamp(i.at)}</small></span>
      <button type="button" class="secondary-button small" data-restore="${i.id}">Вернуть</button></div>`).join('') : '<div class="empty">Корзина пуста</div>';
  $$('#trashList [data-restore]').forEach(b => b.onclick = async () => {
    b.disabled = true;
    try {
      const d = await request('api.php?action=trash_restore', {method: 'POST', body: JSON.stringify({id: Number(b.dataset.restore)})});
      haptic('success'); notice('Восстановлено ✓', true); renderTrash(d.items); mainCache = null; reportCache = null; loadMain().catch(() => {});
    } catch(e) { haptic('error'); notice(e.message); b.disabled = false; }
  });
}
$('#trashOpen').onclick = async () => {
  openLayer('#trashSheet');
  $('#trashList').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try { renderTrash((await request('api.php?action=trash')).items); } catch(e) { $('#trashList').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
};

/* ----- Siri / Shortcuts ----- */
async function loadSiri(renew = false){
  try {
    const d = await request('api.php?action=quick_token' + (renew ? '&renew=1' : ''));
    $('#siriUrl').textContent = d.url;
    $('#siriBox').hidden = false;
    $('#siriSetup').hidden = true;
  } catch(e) { notice(e.message); }
}
$('#siriSetup').onclick = () => loadSiri();
$('#siriRenew').onclick = async () => { if (await showConfirm('Старая ссылка перестанет работать — команду Siri нужно будет обновить. Продолжить?')) { await loadSiri(true); notice('Новая ссылка готова', true); } };
$('#siriCopy').onclick = async () => {
  const url = $('#siriUrl').textContent;
  try { await navigator.clipboard.writeText(url); notice('Ссылка скопирована ✓', true); }
  catch { const r = document.createRange(); r.selectNodeContents($('#siriUrl')); const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); notice('Выделил ссылку — скопируйте её'); }
};

/* ----- subscription finder ----- */
async function loadSubscriptions(){
  try {
    const d = await request('api.php?action=subscriptions');
    const items = d.items.filter(i => !i.already);
    $('#subsBlock').hidden = !items.length;
    const c = cur(currency);
    $('#subsList').innerHTML = items.map((i, n) => `
      <div class="debt-row sub-row">
        <span class="debt-row-main"><b>${safe(i.name)}</b><small>≈ ${money(i.amount)} ${c} в месяц · ${money(i.per_year)} ${c} в год · ${i.months} мес. подряд</small></span>
        <button type="button" class="secondary-button small" data-sub="${n}">В регулярные</button>
      </div>`).join('');
    $$('#subsList [data-sub]').forEach(b => b.onclick = () => {
      const s = items[Number(b.dataset.sub)];
      openRecModal();
      if ([...$('#recCategory').options].some(o => o.value === s.category)) $('#recCategory').value = s.category;
      $('#recAmount').value = money(s.amount);
      $('#recNote').value = s.name;
      $('#recDay').value = s.day;
    });
  } catch { $('#subsBlock').hidden = true; }
}

/* ----- year summary ----- */
$('#yearSend').onclick = async () => {
  const b = $('#yearSend');
  b.disabled = true;
  try { await request('api.php?action=year_send', {method: 'POST', body: JSON.stringify({year: Number(b.dataset.year)})}); haptic('success'); notice('Итоги года отправлены в Telegram ✓', true); }
  catch(e) { haptic('error'); notice(e.message); }
  finally { b.disabled = false; }
};

/* ========== SPLIT EXPENSES ========== */
function renderSplit(){
  $$('#splitToggle button').forEach(b => b.classList.toggle('selected', b.dataset.split === sheetState.split));
}
$$('#splitToggle button').forEach(b => b.onclick = () => { sheetState.split = b.dataset.split; haptic('select'); renderSplit(); });

function renderSettlement(s){
  const el = $('#settleCard');
  if (!s || !s.amount) { el.hidden = true; return; }
  const c = cur(currency);
  el.innerHTML = `<div class="settle-main"><span>Общие траты</span><b>${safe(s.from_name)} → ${safe(s.to_name)} ${money(s.amount)} ${c}</b></div>
    <button type="button" class="pill-button" id="settleBtn">Рассчитаться</button>`;
  el.hidden = false;
  $('#settleBtn').onclick = async () => {
    if (!(await showConfirm(`Записать перевод ${s.from_name} → ${s.to_name} на ${money(s.amount)} ${c}? Личные остатки пересчитаются.`))) return;
    try { await request('api.php?action=settle', {method: 'POST', body: '{}'}); haptic('success'); notice('Рассчитались ✓', true); await reloadAfterChange(); }
    catch(e) { haptic('error'); notice(e.message); }
  };
}

/* ========== RECEIPT PHOTOS ========== */
// Photos come through api.php with the Telegram auth header, so they are fetched as blobs
async function photoUrl(id){
  const r = await fetch('api.php?action=photo&id=' + id, {headers: headers(), credentials: 'same-origin'});
  if (!r.ok) throw Error('Фото не найдено');
  return URL.createObjectURL(await r.blob());
}
function renderPhotoRow(tx){
  const thumb = $('#photoThumb'), img = $('#photoThumbImg');
  if (img.src.startsWith('blob:')) URL.revokeObjectURL(img.src);
  img.removeAttribute('src');
  thumb.hidden = true; $('#photoRemove').hidden = true;
  $('.photo-pick span').textContent = '📎 Фото чека';
  if (tx && Number(tx.has_photo)) photoUrl(tx.id).then(u => { img.src = u; thumb.hidden = false; $('#photoRemove').hidden = false; $('.photo-pick span').textContent = '📎 Заменить'; }).catch(() => {});
}
// Shrunk on the phone before upload (max 1600 px, JPEG)
function shrinkImage(file){
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      const k = Math.min(1, 1600 / Math.max(img.width, img.height));
      const cv = document.createElement('canvas');
      cv.width = Math.round(img.width * k); cv.height = Math.round(img.height * k);
      cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
      URL.revokeObjectURL(img.src);
      resolve(cv.toDataURL('image/jpeg', 0.82));
    };
    img.onerror = () => reject(Error('Не удалось открыть фото'));
    img.src = URL.createObjectURL(file);
  });
}
$('#photoInput').onchange = async e => {
  const f = e.target.files?.[0];
  e.target.value = '';
  if (!f) return;
  try {
    sheetState.photoData = await shrinkImage(f);
    $('#photoThumbImg').src = sheetState.photoData;
    $('#photoThumb').hidden = false; $('#photoRemove').hidden = false;
    $('.photo-pick span').textContent = '📎 Заменить';
    haptic('light');
  } catch(err) { notice(err.message); }
};
$('#photoRemove').onclick = async () => {
  if (sheetState.photoData && !sheetState.hasPhoto) { sheetState.photoData = null; renderPhotoRow(null); return; }
  if (!(await showConfirm('Убрать фото чека из этой записи?'))) return;
  try {
    await request('api.php?action=photo', {method: 'POST', body: JSON.stringify({id: sheetState.editId, remove: true})});
    sheetState.photoData = null; sheetState.hasPhoto = false; renderPhotoRow(null); notice('Фото убрано', true);
  } catch(e) { notice(e.message); }
};
$('#photoThumb').onclick = () => { $('#photoFull').src = $('#photoThumbImg').src; $('#photoModal').hidden = false; };
$$('#photoModal [data-photo-close]').forEach(el => el.onclick = () => { $('#photoModal').hidden = true; });

/* ========== MERCHANT RULES ========== */
async function offerRule({note, category}){
  if (!(await showConfirm(`Всегда относить «${note}» к категории «${category}»? Прошлые такие операции тоже исправлю.`))) return;
  try {
    const r = await request('api.php?action=rule_save', {method: 'POST', body: JSON.stringify({note, category})});
    notice(`Запомнил: «${r.pattern}» → ${category}${r.fixed ? ` · исправлено ${r.fixed}` : ''} ✓`, true);
    if (r.fixed) reloadAfterChange().catch(() => {});
  } catch(e) { notice(e.message); }
}
async function loadRules(){
  try {
    const d = await request('api.php?action=rules');
    const el = $('#rulesBlock');
    if (!el) return;
    el.innerHTML = d.items.length ? `<details class="fold rules-fold"><summary><span><b>Правила магазинов</b><small>${d.items.length} ${plural(d.items.length, 'правило', 'правила', 'правил')} — магазин всегда в своей категории</small></span><i class="pf-chev" aria-hidden="true"></i></summary>
      ${d.items.map(r => `<div class="journal-row"><span class="journal-main"><b>${safe(r.pattern)}</b><small>→ ${safe(r.category)}</small></span><button type="button" class="tx-delete" data-rule="${safe(r.pattern)}" aria-label="Удалить правило">×</button></div>`).join('')}</details>`
      : '<p class="pf-note">Смените категорию у операции с названием магазина — приложение предложит запомнить правило.</p>';
    $$('#rulesBlock [data-rule]').forEach(b => b.onclick = async () => {
      try { await request('api.php?action=rule_delete', {method: 'POST', body: JSON.stringify({pattern: b.dataset.rule})}); loadRules(); } catch(e) { notice(e.message); }
    });
  } catch {}
}

/* ========== PROFILE: folding sections and preferences ========== */
let prefs = {digest: false, roundup: {goal: 0, step: 1000}};
// One open section at a time keeps the profile short
$$('#profileSheet .pf-sec').forEach(d => d.addEventListener('toggle', () => {
  if (d.open) $$('#profileSheet .pf-sec').forEach(o => { if (o !== d) o.open = false; });
}));
function updateProfileSums(){
  const c = cur(currency);
  const t = mainCache?.settings?.big_expense_threshold;
  $('#notifySum').textContent = [t ? `крупная трата от ${money(t)} ${c}` : 'крупные траты выкл', prefs.digest ? 'сводка в 9:00' : ''].filter(Boolean).join(' · ');
  $('#themeSum').textContent = {auto: 'Как в системе', light: 'Светлая', dark: 'Тёмная'}[themePref()] || 'Авто';
  const g = (mainCache?.goals || []).find(x => x.id === prefs.roundup.goal);
  $('#roundupSum').textContent = g ? `до ${money(prefs.roundup.step)} ${c} → «${g.title}»` : 'Выкл';
  $('#roundupSum').classList.toggle('on', Boolean(g));
  if (mainCache?.recurring) renderRecurringSum(mainCache.recurring);
}
function renderRecurringSum(items){
  $('#recSum').textContent = items.length ? `${items.length} ${plural(items.length, 'платёж', 'платежа', 'платежей')}` : 'Кредиты, коммуналка, подписки';
}
function renderPrefs(p){
  if (p) prefs = p;
  $('#digestToggle').checked = prefs.digest;
  const goals = mainCache?.goals || [];
  $('#roundupGoal').innerHTML = `<option value="0">Выключено</option>` + goals.map(g => `<option value="${g.id}" ${g.id === prefs.roundup.goal ? 'selected' : ''}>${safe(g.title)}</option>`).join('');
  $('#roundupGoal').disabled = !goals.length;
  $$('#roundupStep button').forEach(b => b.classList.toggle('selected', Number(b.dataset.step) === prefs.roundup.step));
  updateProfileSums();
}
async function savePrefs(body){
  try { const d = await request('api.php?action=prefs', {method: 'POST', body: JSON.stringify(body)}); prefs = d.prefs; renderPrefs(); haptic('light'); }
  catch(e) { notice(e.message); renderPrefs(); }
}
$('#digestToggle').onchange = e => savePrefs({digest: e.target.checked});
$('#digestPreview').onclick = async () => {
  const box = $('#digestBox');
  if (!box.hidden) { box.hidden = true; return; }
  try { box.textContent = (await request('api.php?action=digest_preview')).text; box.hidden = false; } catch(e) { notice(e.message); }
};
$('#roundupGoal').onchange = e => savePrefs({roundup_goal: Number(e.target.value), roundup_step: prefs.roundup.step});
$$('#roundupStep button').forEach(b => b.onclick = () => savePrefs({roundup_goal: prefs.roundup.goal, roundup_step: Number(b.dataset.step)}));
$$('#themeToggle button').forEach(b => b.addEventListener('click', () => setTimeout(updateProfileSums, 0)));

// Scriptable widget: reads the personal link with mode=json. Run inside Scriptable it always
// shows something: the preview, or the reason it could not load.
function widgetCode(url){
  return `// Семейный бюджет — виджет для Scriptable
const FEED = ${JSON.stringify(url + '&mode=json')};
const fmt = n => Math.round(n).toLocaleString('ru-RU') + ' ₸';
let r = null, err = null;
try {
  const req = new Request(FEED);
  req.timeoutInterval = 15;
  const body = await req.loadString();
  const code = req.response ? req.response.statusCode : 0;
  if (code === 403) throw new Error('Ссылка устарела. Скопируйте код виджета заново в профиле бюджета.');
  if (code !== 200) throw new Error('Сервер ответил ' + code);
  r = JSON.parse(body);
} catch (e) { err = String(e && e.message ? e.message : e); }
const w = new ListWidget();
w.backgroundColor = new Color('#141B29');
w.url = ${JSON.stringify(url.replace(/quick\.php.*$/, ''))};
const t = w.addText('Семейный бюджет'); t.textColor = new Color('#A9B0BE'); t.font = Font.mediumSystemFont(12);
w.addSpacer(6);
if (r) {
  const b = w.addText(fmt(r.balance)); b.textColor = Color.white(); b.font = Font.boldSystemFont(24); b.minimumScaleFactor = 0.6;
  w.addSpacer(4);
  const m = w.addText('За ' + r.month + ': ' + fmt(r.spent_month)); m.textColor = new Color('#A9B0BE'); m.font = Font.systemFont(12);
  if (r.plan_left !== null) { const p = w.addText('По плану: ' + fmt(r.plan_left)); p.textColor = new Color(r.plan_left < 0 ? '#FF6B6B' : '#5BD69A'); p.font = Font.systemFont(12); }
  w.addSpacer();
  const u = w.addText('обновлено ' + r.updated); u.textColor = new Color('#6E7584'); u.font = Font.systemFont(10);
} else {
  const e = w.addText('Нет данных'); e.textColor = new Color('#FF6B6B'); e.font = Font.boldSystemFont(16);
  w.addSpacer(4);
  const d = w.addText(err || ''); d.textColor = new Color('#A9B0BE'); d.font = Font.systemFont(11);
}
w.refreshAfterDate = new Date(Date.now() + 30 * 60 * 1000);
if (config.runsInWidget) {
  Script.setWidget(w);
} else {
  if (err) { const a = new Alert(); a.title = 'Виджет не загрузился'; a.message = err; a.addAction('OK'); await a.presentAlert(); }
  await w.presentSmall();
}
Script.complete();`;
}
$('#widgetCopy').onclick = async () => {
  const url = $('#siriUrl').textContent.trim();
  if (!url) return;
  const code = widgetCode(url);
  const box = $('#widgetCode');
  try { await navigator.clipboard.writeText(code); box.hidden = true; notice('Код виджета скопирован ✓ Вставьте его в Scriptable', true); return; } catch {}
  // Telegram may block the clipboard: show the code to copy by hand
  box.value = code; box.hidden = false; box.focus(); box.select(); box.setSelectionRange(0, code.length);
  let copied = false;
  try { copied = document.execCommand('copy'); } catch {}
  notice(copied ? 'Код виджета скопирован ✓' : 'Код выделен ниже — нажмите «Скопировать» в меню', copied);
};

/* ========== COMPARISON WITH LAST YEAR ========== */
function renderCompare(cmp){
  const sec = $('#compareSection');
  if (!cmp || (!cmp.yoy && !cmp.unusual?.length)) { sec.hidden = true; return; }
  sec.hidden = false;
  const c = cur(currency);
  $('#unusualList').innerHTML = (cmp.unusual || []).slice(0, 3).map(u => `<div class="unusual"><b>⚠ ${safe(u.category)}</b><span>${money(u.now)} ${c} — в ${String(u.times).replace('.', ',')} раза больше обычного (${money(u.usual)} ${c})${u.early ? ', а месяц ещё не закончился' : ''}</span></div>`).join('');
  const y = cmp.yoy;
  $('#yoyFold').hidden = !y;
  if (!y) return;
  const d = y.now - y.before;
  $('#yoySum').innerHTML = `${safe(y.label)}: ${money(y.before)} → ${money(y.now)} ${c} <span class="${d > 0 ? 'money-neg' : 'money-pos'}">${d > 0 ? '+' : '−'}${money(Math.abs(d))}</span>`;
  $('#yoyList').innerHTML = y.rows.map(r => `<div class="journal-row"><span class="journal-main"><b>${safe(r.category)}</b><small>${money(r.before)} → ${money(r.now)} ${c}</small></span><b class="${r.diff > 0 ? 'money-neg' : 'money-pos'}">${r.diff > 0 ? '+' : '−'}${money(Math.abs(r.diff))}</b></div>`).join('');
}

/* ========== CATEGORY PDF ========== */
$('#catPdfOpen').onclick = () => {
  $('#exportMenu').hidden = true;
  const y = new Date().getFullYear();
  $('#catPdfYear').innerHTML = [y, y - 1, y - 2].map(v => `<option>${v}</option>`).join('');
  const cats = [...(categoryGroups.fixed || []), ...(categoryGroups.variable || [])];
  $('#catPdfCats').innerHTML = cats.map(cname => `<label class="chip-check"><input type="checkbox" value="${safe(cname)}" ${cname === 'Здоровье' ? 'checked' : ''}><span>${safe(cname)}</span></label>`).join('');
  $('#catPdfModal').hidden = false;
};
$$('#catPdfModal [data-catpdf-close]').forEach(el => el.onclick = () => { $('#catPdfModal').hidden = true; });
$('#catPdfSend').onclick = async () => {
  const categories = $$('#catPdfCats input:checked').map(i => i.value);
  if (!categories.length) { notice('Выберите категории'); return; }
  const b = $('#catPdfSend'); b.disabled = true; b.textContent = 'Готовлю PDF…';
  try {
    await request('api.php?action=export_categories', {method: 'POST', body: JSON.stringify({year: Number($('#catPdfYear').value), categories})});
    haptic('success'); notice('PDF отправлен вам в Telegram ✓', true); $('#catPdfModal').hidden = true;
  } catch(e) { haptic('error'); notice(e.message); }
  finally { b.disabled = false; b.textContent = 'Прислать PDF в Telegram'; }
};

/* ========== TRIPS ========== */
let tripsCache = [], tripCur = 'KZT';
function renderTripCard(t){
  const el = $('#tripCard');
  $('#tripTileSub').textContent = t ? `${t.title} · идёт` : 'Бюджет отпуска';
  if (!t) { el.hidden = true; return; }
  const share = t.budget ? Math.min(1, t.spent_cur / t.budget) : 0;
  el.innerHTML = `<span class="trip-top"><b>✈️ ${safe(t.title)}</b><span>${fmtCur(t.spent_cur, t.currency)}${t.budget ? ' из ' + fmtCur(t.budget, t.currency) : ''}</span></span>
    ${t.budget ? `<span class="plan-bar"><i class="${share >= 1 ? 'over' : share > .85 ? 'warn' : ''}" style="width:${Math.max(2, share * 100)}%"></i></span>` : ''}
    <small>${t.days} ${plural(t.days, 'день', 'дня', 'дней')} · ${money(t.spent_kzt)} ₸ · в день ≈ ${money(t.per_day_kzt)} ₸</small>`;
  el.hidden = false;
}
$('#tripCard').onclick = () => openTrips();
$('#tripsOpen').onclick = () => openTrips();
async function openTrips(){
  openLayer('#tripsSheet');
  $('#tripActive').innerHTML = '<div class="loading-placeholder"><div class="spinner"></div></div>';
  try { tripsCache = (await request('api.php?action=trips')).items; renderTrips(); } catch(e) { $('#tripActive').innerHTML = `<div class="empty">${safe(e.message)}</div>`; }
}
function renderTrips(){
  const active = tripsCache.find(t => Number(t.active));
  const past = tripsCache.filter(t => !Number(t.active));
  if (active) {
    const t = active, budget = t.budget ? Number(t.budget) : 0, share = budget ? Math.min(1, t.spent_cur / budget) : 0;
    $('#tripActive').innerHTML = `<div class="trip-panel card">
      <div class="trip-top"><b>✈️ ${safe(t.title)}</b><span>с ${fmtDate(t.start_on)}</span></div>
      <div class="trip-big">${fmtCur(t.spent_cur, t.currency)}${budget ? `<small> из ${fmtCur(budget, t.currency)}</small>` : ''}</div>
      ${budget ? `<div class="plan-bar"><i class="${share >= 1 ? 'over' : share > .85 ? 'warn' : ''}" style="width:${Math.max(2, share * 100)}%"></i></div><small>${budget - t.spent_cur >= 0 ? 'осталось ' + fmtCur(budget - t.spent_cur, t.currency) : 'сверх бюджета ' + fmtCur(t.spent_cur - budget, t.currency)}</small>` : ''}
      <div class="trip-stats"><span>${money(t.spent_kzt)} ₸</span><span>${t.count} оп.</span><span>≈ ${money(t.per_day_kzt)} ₸/день</span></div>
      ${t.categories.length ? `<div class="journal">${t.categories.slice(0, 5).map(x => `<div class="journal-row"><span class="journal-main"><b>${safe(x.category)}</b></span><b>${money(x.total)} ₸</b></div>`).join('')}</div>` : '<p class="pf-note">Трат в поездке пока нет — нажмите «+» или напишите боту «кафе 20».</p>'}
      <div class="trip-actions"><button type="button" class="secondary-button small" data-trip-ops="${t.id}">Операции</button><button type="button" class="secondary-button small" id="tripEnd">Завершить</button></div>
      <div id="tripOps"></div>
    </div>`;
    $('#tripEnd').onclick = async () => {
      if (!(await showConfirm(`Завершить поездку «${t.title}»? Новые траты снова будут в тенге.`))) return;
      try { tripsCache = (await request('api.php?action=trip_end', {method: 'POST', body: JSON.stringify({id: t.id})})).items; renderTrips(); mainCache = null; loadMain().catch(() => {}); notice('Поездка завершена', true); } catch(e) { notice(e.message); }
    };
  } else $('#tripActive').innerHTML = '';
  $('#tripNew').hidden = Boolean(active);
  $('#tripsPast').innerHTML = past.length ? `<div class="limit-group-title">Прошлые поездки</div>` + past.map(t => `
    <div class="debt-row"><span class="debt-row-main"><b>${safe(t.title)}</b><small>${fmtDate(t.start_on)} — ${t.end_on ? fmtDate(t.end_on) : '…'} · ${t.days} ${plural(t.days, 'день', 'дня', 'дней')} · ${t.count} оп.</small></span>
      <b>${fmtCur(t.spent_cur, t.currency)}</b><button type="button" class="tx-delete" data-trip-del="${t.id}" aria-label="Удалить поездку">×</button></div>`).join('') : '';
  $$('[data-trip-ops]').forEach(b => b.onclick = async () => {
    const box = $('#tripOps');
    if (box.innerHTML) { box.innerHTML = ''; return; }
    try { const d = await request('api.php?action=trip_ops&id=' + b.dataset.tripOps); renderTxList(box, d.items, 'Операций нет'); } catch(e) { notice(e.message); }
  });
  $$('[data-trip-del]').forEach(b => b.onclick = async () => {
    if (!(await showConfirm('Удалить поездку из списка? Операции останутся в бюджете.'))) return;
    try { tripsCache = (await request('api.php?action=trip_delete', {method: 'POST', body: JSON.stringify({id: Number(b.dataset.tripDel)})})).items; renderTrips(); } catch(e) { notice(e.message); }
  });
}
$('#tripNew').onclick = () => {
  tripCur = 'KZT';
  $('#tripTitle').value = ''; $('#tripBudget').value = ''; $('#tripStart').value = todayISO();
  $$('#tripCurrency button').forEach(b => b.classList.toggle('selected', b.dataset.cur === 'KZT'));
  $('#tripModal').hidden = false;
  setTimeout(() => $('#tripTitle').focus(), 100);
};
$$('#tripCurrency button').forEach(b => b.onclick = () => { tripCur = b.dataset.cur; haptic('select'); $$('#tripCurrency button').forEach(x => x.classList.toggle('selected', x === b)); });
$('#tripBudget').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };
$$('#tripModal [data-trip-close]').forEach(el => el.onclick = () => { $('#tripModal').hidden = true; });
$('#tripSave').onclick = async () => {
  try {
    tripsCache = (await request('api.php?action=trip_save', {method: 'POST', body: JSON.stringify({title: $('#tripTitle').value, currency: tripCur, budget: digitsOnly($('#tripBudget').value), start_on: $('#tripStart').value})})).items;
    $('#tripModal').hidden = true; haptic('success'); notice('Хорошей поездки! ✈️', true); renderTrips(); mainCache = null; loadMain().catch(() => {});
  } catch(e) { haptic('error'); notice(e.message); }
};

/* ========== GUIDE ========== */
// Screen by screen like a bank's help: home (search, popular, sections) → section → answer.
// «Назад» goes one level up; from the guide's home it returns to the app.
const GUIDE = JSON.parse($('#guideData').textContent);
const GUIDE_GO = {
  add: () => openSheet(), reports: () => switchTab('reports'), categories: () => $('#categoriesOpen').click(),
  plan: () => $('#planOpen').click(), calendar: () => $('#calendarOpen').click(), trips: () => $('#tripsOpen').click(), shop: () => $('#shopOpen').click(),
  accounts: () => $('#accountsOpen').click(), deposits: () => $('#depositsOpen').click(), debts: () => $('#debtsOpen').click(),
  audit: () => $('#auditOpen').click(), trash: () => $('#trashOpen').click(),
  limits: () => openProfile('limitsSec'), roundup: () => openProfile('roundupSec'), notify: () => openProfile('notifySec'), siri: () => openProfile('siriSec'),
  recurring: () => openProfile('recurringBlock'), import: () => openProfile('importSec'), reset: () => openProfile('resetSec'),
};
const GUIDE_GO_LABEL = {add: 'Добавить запись', reports: 'Открыть отчёты', categories: 'Открыть категории', plan: 'Открыть план', calendar: 'Открыть календарь', trips: 'Открыть поездки',
  shop: 'Открыть покупки', accounts: 'Открыть счета', deposits: 'Открыть депозиты', debts: 'Открыть долги', audit: 'Открыть журнал', trash: 'Открыть корзину',
  limits: 'Настроить лимиты', roundup: 'Настроить копилку', notify: 'Открыть уведомления', siri: 'Настроить Siri', recurring: 'Открыть платежи', import: 'Загрузить выписку', reset: 'Открыть сброс'};
let guideStack = [], guideQuery = '';
const guideSec = id => GUIDE.sections.find(s => s.id === id);
const guideIcon = d => `<span class="pf-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="${d}"/></svg></span>`;
const guideRow = (sid, i, extra = '') => { const q = guideSec(sid).items[i]; return `<button type="button" class="guide-row" data-gq="${sid}:${i}">${extra}<span>${safe(q.q)}</span><i class="pf-chev side" aria-hidden="true"></i></button>`; };

function renderGuide(){
  const view = guideStack[guideStack.length - 1] || {v: 'home'};
  const el = $('#guideView');
  let html = '';
  if (view.v === 'home') {
    $('#guideTitle').textContent = 'Гид';
    html = `<div class="guide-hero"><b>Чем помочь?</b><span>Ответы на вопросы о бюджете и боте</span></div>
      <input type="search" class="note-input guide-search" id="guideSearch" placeholder="Поиск по гиду" autocomplete="off" aria-label="Поиск по гиду" value="${safe(guideQuery)}">
      <div id="guideResults"></div>`;
    el.innerHTML = html;
    renderGuideHome();
    $('#guideSearch').oninput = e => { guideQuery = e.target.value; renderGuideHome(); };
  } else if (view.v === 'sec') {
    const s = guideSec(view.id);
    $('#guideTitle').textContent = s.title;
    el.innerHTML = `<div class="guide-sec-head">${guideIcon(s.icon)}<span><b>${safe(s.title)}</b><small>${s.items.length} ${plural(s.items.length, 'вопрос', 'вопроса', 'вопросов')}</small></span></div>
      <div class="guide-list">${s.items.map((_, i) => guideRow(s.id, i)).join('')}</div>`;
  } else {
    const s = guideSec(view.id), q = s.items[view.i];
    $('#guideTitle').textContent = s.title;
    const others = s.items.map((_, i) => i).filter(i => i !== view.i).slice(0, 4);
    el.innerHTML = `<article class="guide-article">
      ${q.where ? `<div class="guide-where"><span>Где найти</span><b>${safe(q.where)}</b></div>` : ''}
      <h3>${safe(q.q)}</h3>
      ${q.text ? `<p class="guide-text">${safe(q.text)}</p>` : ''}
      ${q.steps ? `<ol class="guide-steps">${q.steps.map((t, n) => `<li><i>${n + 1}</i><span>${safe(t)}</span></li>`).join('')}</ol>` : ''}
      ${q.tip ? `<div class="guide-tip">💡 ${safe(q.tip)}</div>` : ''}
      ${q.go ? `<button type="button" class="sheet-submit guide-go" data-go="${q.go}">${GUIDE_GO_LABEL[q.go] || 'Перейти'}</button>` : ''}
      <div class="guide-feedback" id="guideFeedback"><span>Ответ помог?</span><button type="button" data-fb="1">👍 Да</button><button type="button" data-fb="0">👎 Нет</button></div>
    </article>
    ${others.length ? `<div class="guide-label">Ещё в разделе «${safe(s.title)}»</div><div class="guide-list">${others.map(i => guideRow(s.id, i)).join('')}</div>` : ''}`;
    $$('#guideFeedback [data-fb]').forEach(b => b.onclick = () => {
      haptic('light');
      $('#guideFeedback').innerHTML = b.dataset.fb === '1' ? '<span>Спасибо! Рады, что помогли 🙌</span>' : '<span>Спросите бота своими словами — например, «сколько потратили на кафе?» — или напишите Миржану.</span>';
    });
    $$('#guideView [data-go]').forEach(b => b.onclick = async () => { const go = GUIDE_GO[b.dataset.go]; await closeGuide(); setTimeout(() => go?.(), 50); });
  }
  bindGuideRows();
  $('#guidePage').scrollTop = 0;
}

function renderGuideHome(){
  const q = guideQuery.trim().toLowerCase();
  const box = $('#guideResults');
  if (q) {
    const hits = [];
    GUIDE.sections.forEach(s => s.items.forEach((it, i) => { if ([it.q, it.text, it.where, it.tip, ...(it.steps || [])].join(' ').toLowerCase().includes(q)) hits.push([s, i]); }));
    box.innerHTML = hits.length
      ? `<div class="guide-label">Найдено: ${hits.length}</div><div class="guide-list">${hits.map(([s, i]) => guideRow(s.id, i, `<small class="guide-row-sec">${safe(s.title)}</small>`)).join('')}</div>`
      : '<p class="guide-empty">Ничего не нашлось. Попробуйте другое слово или спросите бота.</p>';
  } else {
    box.innerHTML = `<div class="guide-label">Популярные вопросы</div>
      <div class="guide-list">${GUIDE.top.map(([sid, i], n) => guideRow(sid, i, `<b class="guide-num">${n + 1}</b>`)).join('')}</div>
      <div class="guide-label">Разделы</div>
      <div class="guide-tiles">${GUIDE.sections.map(s => `<button type="button" class="guide-tile" data-gs="${s.id}">${guideIcon(s.icon)}<b>${safe(s.title)}</b><small>${s.items.length} ${plural(s.items.length, 'вопрос', 'вопроса', 'вопросов')}</small></button>`).join('')}</div>`;
  }
  bindGuideRows();
}

function bindGuideRows(){
  $$('#guideView [data-gq]').forEach(b => b.onclick = () => { const [id, i] = b.dataset.gq.split(':'); haptic('select'); guideStack.push({v: 'art', id, i: Number(i)}); renderGuide(); });
  $$('#guideView [data-gs]').forEach(b => b.onclick = () => { haptic('select'); guideStack.push({v: 'sec', id: b.dataset.gs}); renderGuide(); });
}

function openGuide(){
  guideStack = []; guideQuery = '';
  renderGuide();
  $('#guidePage').hidden = false;
  document.body.style.overflow = 'hidden';
}
async function closeGuide(){
  $('#guidePage').hidden = true;
  document.body.style.overflow = '';
  if (!$('#profileSheet').hidden) await closeProfile(true);
  switchTab('main');
  window.scrollTo({top: 0});
}
// One level up; from the guide's home back into the app
function guideBackStep(){
  if (guideStack.length) { guideStack.pop(); renderGuide(); return; }
  closeGuide();
}
$('#guideOpen').onclick = openGuide;
$('#guideBack').onclick = guideBackStep;

/* ========== TELEGRAM BACK BUTTON ========== */
// Closes the topmost open layer, like the system back gesture would
const LAYERS = ['#confirmModal', '#guidePage', '#photoModal', '#catPdfModal', '#tripModal', '#tripsSheet', '#shopSheet', '#planSheet', '#calendarSheet', '#auditSheet', '#trashSheet', '#calModal', '#rangeCalModal', '#customCatModal', '#goalModal', '#moveModal', '#recModal', '#debtModal', '#depositModal', '#accountModal', '#catEditModal', '#importSheet', '#accountSheet', '#accountsSheet', '#catsSheet', '#depositSheet', '#depositsSheet', '#debtsSheet', '#drillSheet', '#profileSheet', '#sheet'];
function topLayer(){ return LAYERS.find(sel => !$(sel).hidden) || null; }
function closeTopLayer(){
  const top = topLayer();
  ({'#calModal': closeSheetCalendar, '#rangeCalModal': closeRangeCalendar, '#customCatModal': closeCustomCatModal, '#goalModal': closeGoalModal,
    '#moveModal': closeMoveModal, '#recModal': closeRecModal, '#importSheet': closeImport, '#drillSheet': closeDrill,
    '#debtModal': closeDebtModal, '#depositModal': closeDepositModal, '#debtsSheet': closeDebtsSheet,
    '#depositSheet': closeDeposit, '#depositsSheet': closeDepositsSheet,
    '#accountModal': closeAccountModal, '#catEditModal': closeCatEdit, '#accountSheet': closeAccount, '#accountsSheet': closeAccountsSheet, '#catsSheet': closeCatsSheet,
    '#photoModal': () => { $('#photoModal').hidden = true; }, '#catPdfModal': () => { $('#catPdfModal').hidden = true; }, '#tripModal': () => { $('#tripModal').hidden = true; },
    '#tripsSheet': () => closeLayer('#tripsSheet'), '#guidePage': () => guideBackStep(),
    '#shopSheet': () => closeLayer('#shopSheet'), '#planSheet': () => closeLayer('#planSheet'), '#calendarSheet': () => closeLayer('#calendarSheet'),
    '#auditSheet': () => closeLayer('#auditSheet'), '#trashSheet': () => closeLayer('#trashSheet'),
    '#profileSheet': () => closeProfile(), '#sheet': () => closeSheet(), '#confirmModal': () => $('#confirmCancel').click()})[top]?.();
}
function syncBackButton(){
  if (!inTelegram || !tg.BackButton) return;
  topLayer() ? tg.BackButton.show() : tg.BackButton.hide();
}
if (inTelegram && tg.BackButton) {
  tg.BackButton.onClick(closeTopLayer);
  const obs = new MutationObserver(syncBackButton);
  LAYERS.forEach(sel => obs.observe($(sel), {attributes: true, attributeFilter: ['hidden']}));
}

/* ========== INIT ========== */
tg?.ready(); tg?.expand();
applyTheme();
// Stop vertical swipes in sheets from collapsing the Mini App
if (inTelegram) { try { tg.disableVerticalSwipes?.(); } catch {} }

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
  if (b.dataset.preset === 'custom') { openRangeCalendar('from'); return; }
  $$('#presets button').forEach(x => x.classList.toggle('selected', x === b));
  reportState.preset = b.dataset.preset;
  reportState.anchor = todayISO();
  reportState.page = 1;
  loadReport().catch(()=>{});
});
$$('#categoryFilter button').forEach(b => b.onclick = () => {
  $$('#categoryFilter button').forEach(x => x.classList.toggle('selected', x === b));
  reportState.filter = b.dataset.filter;
  if (reportCache) renderReport(reportCache);
});



bindSheet();
switchTab('main');
})();
