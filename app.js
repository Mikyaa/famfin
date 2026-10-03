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

/* ========== SHEET: ENTRY (add, edit, transfer) ========== */
const sheetState = {mode:'add', editId:null, kind:'expense', amount:'', category:null, group:'variable', date:todayISO(), dateMode:'today', note:'', payerId:null, from:null, to:null};
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
  const other = familyMembers().find(m => m.id !== myId());
  Object.assign(sheetState, {
    mode: tx ? 'edit' : 'add', editId: tx ? Number(tx.id) : null, kind: tx ? tx.kind : 'expense',
    amount: tx ? String(Number(tx.amount)) : '', category: tx ? tx.category : null, group: tx ? (tx.group || 'variable') : 'variable',
    date: tx ? tx.occurred_on : todayISO(), note: tx ? (tx.note || '') : '', payerId: tx ? Number(tx.telegram_id) : myId(),
    from: myId(), to: other ? other.id : null,
  });
  $('#sheetTitle').textContent = tx ? 'Изменить запись' : 'Новая запись';
  $('#noteInput').value = sheetState.note;
  delete $('#amountInput').dataset.touched;
  $('#sheetCurrency').textContent = cur(currency);
  updateAmountDisplay();
  setDateButtons();
  renderCatGrid();
  renderTransferDir();
  renderPayerToggle();
  applyKindUI();
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

  $('#customCatBtn').onclick = openCustomCatModal;
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
    body = {kind: sheetState.kind, amount, category: sheetState.category, category_group: sheetState.group, note, date: sheetState.date};
    if (sheetState.mode === 'edit') { url = 'api.php?action=update'; body.id = sheetState.editId; body.payer_id = sheetState.payerId; okText = 'Изменения сохранены ✓'; }
    else { url = 'api.php'; okText = 'Запись добавлена ✓'; }
  }
  submitting = true;
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner small"></div> Сохранение…';
  tgMain.progress(true);
  try {
    const saved = await request(url, {method:'POST', body: JSON.stringify(body)});
    const limitMsg = limitNoticeAfterSave(saved.limits);
    if (limitMsg) { haptic('warning'); notice(okText.replace(' ✓', '') + '. ' + limitMsg, 'warn'); }
    else { haptic('success'); notice(okText, true); }
    await closeSheet(true);
    await reloadAfterChange();
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
      <div class="tx-meta">${safe(t.display_name)} · ${t.kind==='topup' ? 'пополнение' : t.group==='fixed'?'обязательные':'переменные'}</div>
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
  const amount = Number(sheetState.amount) || 0;
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
  if (typeof scrollTo === 'string') setTimeout(() => $('#' + scrollTo)?.scrollIntoView({behavior: 'smooth', block: 'start'}), 250);
  try {
    renderProfile(await request('api.php?action=limits'));
  } catch(e) { notice(e.message); closeProfile(true); }
}

function renderProfile(d){
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
$('#limitsEdit').onclick = () => openProfile();
$('#limitsSave').onclick = saveProfile;
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
    <div class="forecast-top"><span>Прогноз на конец месяца</span><span>${f.days_left ? 'ещё ' + f.days_left + ' дн.' : 'последний день'}</span></div>
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
    return `<article class="goal card" data-goal="${g.id}">
      <button type="button" class="goal-head" data-goal-edit="${g.id}" aria-label="Изменить цель ${safe(g.title)}">
        <span class="goal-title">${safe(g.title)}</span>
        <span class="goal-amount">${money(g.saved)} <small>/ ${money(g.target)} ${c}</small></span>
      </button>
      <div class="limit-track"><i style="width:${g.percent}%"></i></div>
      <div class="goal-sub">${sub}</div>
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
  $('#goalCloseBtn').hidden = !goal;
  $('#goalModal').hidden = false;
  if (!goal) setTimeout(() => $('#goalTitle').focus(), 100);
}
const closeGoalModal = () => { $('#goalModal').hidden = true; };
$$('#goalModal [data-goal-close]').forEach(el => el.onclick = closeGoalModal);
$('#goalTarget').oninput = e => { const v = digitsOnly(e.target.value); e.target.value = v ? money(Number(v)) : ''; };
$('#goalSave').onclick = async () => {
  try {
    await request('api.php?action=goal_save', {method:'POST', body: JSON.stringify({id: goalEditing?.id, title: $('#goalTitle').value, target: digitsOnly($('#goalTarget').value), deadline: $('#goalDeadline').value})});
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
  const rows = importRows.filter(r => r.include).map(r => ({kind: r.kind, amount: r.amount, category: r.category, note: r.note, date: r.date, key: r.key, type: r.type}));
  const btn = $('#importConfirm'); btn.disabled = true;
  try {
    const d = await request('api.php?action=import', {method:'POST', body: JSON.stringify({rows, payer_id: importPayer})});
    haptic('success');
    notice(`Импортировано: ${d.imported}` + (d.linked ? ` · совпали с ручными: ${d.linked}` : '') + (d.skipped ? ` · уже были: ${d.skipped}` : '') + ' ✓', true);
    await closeImport(); await reloadAfterChange();
  } catch(e) { haptic('error'); notice(e.message); btn.disabled = false; }
};

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

/* ========== TELEGRAM BACK BUTTON ========== */
// Closes the topmost open layer, like the system back gesture would
const LAYERS = ['#confirmModal', '#calModal', '#rangeCalModal', '#customCatModal', '#goalModal', '#moveModal', '#recModal', '#importSheet', '#drillSheet', '#profileSheet', '#sheet'];
function topLayer(){ return LAYERS.find(sel => !$(sel).hidden) || null; }
function closeTopLayer(){
  const top = topLayer();
  ({'#calModal': closeSheetCalendar, '#rangeCalModal': closeRangeCalendar, '#customCatModal': closeCustomCatModal, '#goalModal': closeGoalModal,
    '#moveModal': closeMoveModal, '#recModal': closeRecModal, '#importSheet': closeImport, '#drillSheet': closeDrill,
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
