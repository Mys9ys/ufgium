const api = (r, body, method = 'POST') => {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (method !== 'GET') opts.body = JSON.stringify(body || {});
  const url = method === 'GET'
    ? `/api/${r}?player_id=${encodeURIComponent(body.player_id)}&secret=${encodeURIComponent(body.secret)}`
    : `/api/${r}`;
  return fetch(url, opts).then(async (res) => {
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error('Сервер вернул не JSON. Обнови страницу и попробуй ещё раз.');
    }
    if (!res.ok) throw new Error(data.error || 'Ошибка');
    return data;
  });
};

const store = {
  get() {
    try { return JSON.parse(localStorage.getItem('ufgium') || 'null'); } catch { return null; }
  },
  set(s) { localStorage.setItem('ufgium', JSON.stringify({ player_id: s.me.id, secret: s.me.secret })); },
  clear() { localStorage.removeItem('ufgium'); }
};

let state = null;
let timer = null;
let selectedCard = null;
let pendingBetSlot = null;
let settleDismissed = false;
let scanStream = null;
let scanLoop = 0;
let pendingJoinCode = null;

const $ = (id) => document.getElementById(id);
const showErr = (m) => { $('err').textContent = m || ''; };

function joinUrl(code) {
  const u = new URL(location.href);
  u.search = '';
  u.hash = '';
  u.searchParams.set('join', String(code || '').toUpperCase());
  return u.toString();
}

function parseJoinFromText(text) {
  const raw = String(text || '').trim();
  try {
    const u = new URL(raw);
    const fromQuery = u.searchParams.get('join') || u.searchParams.get('code');
    if (fromQuery) return fromQuery.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
  } catch (_) { /* plain code */ }
  const m = raw.toUpperCase().match(/\b([A-Z0-9]{6})\b/);
  return m ? m[1] : '';
}

function isLoopbackHost() {
  const h = location.hostname;
  return h === '127.0.0.1' || h === 'localhost' || h === '::1';
}

async function copyText(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const ta = document.createElement('textarea');
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand('copy');
  ta.remove();
}

function closeInviteModal() {
  stopScan();
  $('invite-modal').classList.add('hide');
  $('qr-show-pane').classList.add('hide');
  $('qr-scan-pane').classList.add('hide');
  $('qr-scan-err').textContent = '';
  const file = $('qr-file');
  if (file) file.value = '';
}

function stopScan() {
  scanLoop += 1;
  if (scanStream) {
    scanStream.getTracks().forEach((t) => t.stop());
    scanStream = null;
  }
  const video = $('qr-video');
  if (video) {
    video.pause();
    video.srcObject = null;
  }
}

async function openQrShow(code) {
  closeInviteModal();
  $('invite-modal-title').textContent = 'QR комнаты';
  $('qr-show-pane').classList.remove('hide');
  $('invite-modal').classList.remove('hide');
  $('qr-code-label').textContent = code;
  $('qr-host-hint').textContent = isLoopbackHost()
    ? 'Сейчас адрес localhost — телефоны друзей по QR не зайдут. Открой игру по IP в Wi‑Fi (или ufgium.loc) и снова покажи QR.'
    : 'Друзья должны быть в той же сети / открывать тот же хост.';
  const url = joinUrl(code);
  const canvas = $('qr-canvas');
  if (typeof QRCode === 'undefined' || !QRCode.toCanvas) {
    throw new Error('Библиотека QR не загрузилась');
  }
  await QRCode.toCanvas(canvas, url, {
    width: 240,
    margin: 2,
    color: { dark: '#1a1408', light: '#ffffff' },
  });
}

async function openQrScan() {
  closeInviteModal();
  $('invite-modal-title').textContent = 'Сканер QR';
  $('qr-scan-pane').classList.remove('hide');
  $('invite-modal').classList.remove('hide');
  $('qr-scan-err').textContent = '';
  const video = $('qr-video');
  const myLoop = ++scanLoop;
  try {
    scanStream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: { facingMode: { ideal: 'environment' } },
    });
    video.srcObject = scanStream;
    await video.play();
    tickScan(myLoop);
  } catch (err) {
    $('qr-scan-err').textContent = 'Камера недоступна — загрузи фото QR ниже. ' + (err.message || '');
  }
}

function tickScan(loopId) {
  if (loopId !== scanLoop) return;
  const video = $('qr-video');
  const canvas = $('qr-scan-canvas');
  if (!video || video.readyState < 2) {
    requestAnimationFrame(() => tickScan(loopId));
    return;
  }
  const w = video.videoWidth;
  const h = video.videoHeight;
  if (!w || !h) {
    requestAnimationFrame(() => tickScan(loopId));
    return;
  }
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(video, 0, 0, w, h);
  const imageData = ctx.getImageData(0, 0, w, h);
  let code = '';
  if (typeof jsQR === 'function') {
    const result = jsQR(imageData.data, w, h, { inversionAttempts: 'dontInvert' });
    if (result?.data) code = parseJoinFromText(result.data);
  }
  if (code) {
    stopScan();
    closeInviteModal();
    onJoinCodeFound(code);
    return;
  }
  requestAnimationFrame(() => tickScan(loopId));
}

async function decodeQrFromFile(file) {
  if (!file) return;
  const bmp = await createImageBitmap(file);
  const canvas = $('qr-scan-canvas');
  canvas.width = bmp.width;
  canvas.height = bmp.height;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(bmp, 0, 0);
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
  if (typeof jsQR !== 'function') throw new Error('Сканер QR не загрузился');
  const result = jsQR(imageData.data, canvas.width, canvas.height);
  const code = result?.data ? parseJoinFromText(result.data) : '';
  if (!code) throw new Error('QR не распознан');
  closeInviteModal();
  onJoinCodeFound(code);
}

function onJoinCodeFound(code) {
  pendingJoinCode = code;
  $('join-code').value = code;
  if ($('join-code-host')) $('join-code-host').value = code;
  showGateMode('guest');
  showErr(`Код ${code} — введи ник и жми «За стол».`);
  $('name-guest')?.focus();
}

async function doJoin(code) {
  const c = String(code || $('join-code').value || pendingJoinCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
  if (!c) throw new Error('Нет кода комнаты');
  const name = tableName();
  if (!name) throw new Error('Как тебя звать сегодня?');
  state = await api('session/join', {
    code: c,
    name,
    drink_tag: 'steklyshki',
  });
  store.set(state);
  pendingJoinCode = null;
  if (location.search.includes('join=')) {
    history.replaceState({}, '', location.pathname);
  }
  render();
}

function forceStartScreen() {
  store.clear();
  state = null;
  pendingJoinCode = null;
  closeInviteModal();
  showErr('');
  render();
}

function showGateMode(mode) {
  const host = $('gate-host');
  const guest = $('gate-guest');
  if (!host || !guest) return;
  const isGuest = mode === 'guest';
  host.classList.toggle('hide', isGuest);
  guest.classList.toggle('hide', !isGuest);
  if (isGuest && pendingJoinCode) {
    $('join-code').value = pendingJoinCode;
    $('guest-code-show').textContent = pendingJoinCode;
    $('name-guest')?.focus();
  }
}

function tableName() {
  if (!$('gate-guest').classList.contains('hide')) {
    return ($('name-guest')?.value || '').trim();
  }
  return ($('name')?.value || '').trim();
}

function seatPoint(i, n) {
  const aspect = 1.35;
  const peri = 2 * (aspect + 1);
  let d = ((i + 0.5) / n) * peri;
  let x;
  let y;
  if (d < aspect) {
    x = d / aspect;
    y = 0;
  } else if (d < aspect + 1) {
    x = 1;
    y = (d - aspect) / 1;
  } else if (d < aspect + 1 + aspect) {
    x = 1 - (d - aspect - 1) / aspect;
    y = 1;
  } else {
    x = 0;
    y = 1 - (d - aspect - 1 - aspect);
  }
  return { x, y };
}

function renderKit(me, _players, playing) {
  const coins = $('bar-coins');
  const blank = $('bar-blank');
  if (coins) {
    coins.innerHTML = playing ? barCoin(me.balance) : '';
  }
  if (blank) {
    const n = (me.vouchers || []).filter((v) => v.status === 'blank').length;
    blank.classList.toggle('hide', !playing || n < 1);
    blank.innerHTML = n ? `<b class="blank-n">${n}</b>` : '';
    blank.title = n > 1 ? `Бланки векселя: ${n}` : 'Бланк векселя';
  }
  const dot = $('side-dot');
  if (dot) {
    const side = me.token_ready ? me.side : 'mystery';
    dot.className = `side-dot ${side || 'mystery'}`;
    dot.classList.toggle('hide', !playing);
  }
}

function seatPlateCard() {
  return $('seat-plate')?.closest('.table-card') || null;
}

function placeSeatPlateOverHead() {
  const head = $('table-head');
  const plate = $('seat-plate');
  if (!head || !plate) return;
  plate.style.top = `${head.offsetTop}px`;
  plate.style.left = `${head.offsetLeft}px`;
  plate.style.width = `${head.offsetWidth}px`;
  plate.style.height = `${Math.max(head.offsetHeight, 1)}px`;
}

function hideSeatPlate() {
  const card = seatPlateCard();
  const plate = $('seat-plate');
  if (!card || !plate) return;
  card.classList.remove('plate-on');
  plate.setAttribute('aria-hidden', 'true');
}

function showSeatPlate(p, me) {
  const card = seatPlateCard();
  const plate = $('seat-plate');
  if (!card || !plate || !p) return;
  const num = p.token_ready ? p.token : '?';
  const name = `${p.name || 'Игрок'}${p.id === me.id ? ' · ты' : ''}`;
  const bio = p.bio || (p.is_bot ? '' : 'игрок за столом');
  const gold = Number.isFinite(Number(p.balance)) ? Number(p.balance) : 0;
  $('seat-plate-num').textContent = String(num);
  $('seat-plate-name').textContent = name;
  $('seat-plate-bio').textContent = bio || '—';
  $('seat-plate-gold').textContent = `монеты ${gold}`;
  $('seat-plate-num').className = `seat-plate-num ${p.token_ready ? p.side : 'mystery'}`;
  placeSeatPlateOverHead();
  plate.setAttribute('aria-hidden', 'false');
  card.classList.add('plate-on');
}

function playerBySeatId(id) {
  return (state?.players || []).find((x) => String(x.id) === String(id));
}

function renderSeats(players, me) {
  const ring = $('seat-ring');
  if (!ring) return;
  const rows = players || [];
  ring.innerHTML = rows.map((p, i) => {
    const { x, y } = seatPoint(i, rows.length);
    const num = p.token_ready ? p.token : '?';
    const side = p.token_ready ? p.side : 'mystery';
    return `<span class="seat ${side}${p.id === me.id ? ' me' : ''}" style="left:${(x * 100).toFixed(2)}%;top:${(y * 100).toFixed(2)}%" tabindex="0" data-seat-id="${p.id}">${num}</span>`;
  }).join('');
  if (ring.dataset.plateBound !== '1') {
    ring.dataset.plateBound = '1';
    ring.addEventListener('pointerover', (e) => {
      const seat = e.target.closest('.seat');
      if (!seat || !ring.contains(seat)) return;
      const p = playerBySeatId(seat.dataset.seatId);
      const self = state?.me || me;
      if (p) showSeatPlate(p, self);
    });
    ring.addEventListener('pointerout', (e) => {
      const to = e.relatedTarget;
      if (to && ring.contains(to) && to.closest?.('.seat')) return;
      hideSeatPlate();
    });
    ring.addEventListener('focusin', (e) => {
      const seat = e.target.closest('.seat');
      if (!seat) return;
      const p = playerBySeatId(seat.dataset.seatId);
      if (p && state?.me) showSeatPlate(p, state.me);
    });
    ring.addEventListener('focusout', (e) => {
      if (ring.contains(e.relatedTarget)) return;
      hideSeatPlate();
    });
  }
}

function authBody(extra = {}) {
  const a = store.get();
  return { ...extra, player_id: a.player_id, secret: a.secret };
}

async function refresh() {
  const a = store.get();
  if (!a) return;
  state = await api('state', a, 'GET');
  render();
}

function sideLabel(s) { return s === 'white' ? 'белые' : 'красные'; }

let rulesTab = 'base';
let lastRulesCount = 0;

function renderRulesCards(rules) {
  const box = $('rules-cards');
  const tabs = $('rules-tabs');
  if (!box || !tabs) return;
  const cards = rules || [];
  lastRulesCount = cards.length;

  const tabNew = $('tab-rules-new');
  const tabBase = $('tab-rules-base');
  const hasNew = cards.length > 0;

  // Пока новых нет — только стандартные, без табов.
  tabs.classList.toggle('hide', !hasNew);
  tabNew?.classList.toggle('hide', !hasNew);

  if (hasNew) {
    tabs.insertBefore(tabNew, tabBase);
    tabNew.innerHTML = `Новые<span class="tab-badge">${cards.length}</span>`;
    box.innerHTML = cards.map((r) =>
      `<div class="rule-card"><b>Тур ${r.from_round}. ${esc(r.title)}</b><p class="muted">${esc(r.body || '')}</p></div>`
    ).join('');
  } else {
    box.innerHTML = '';
    rulesTab = 'base';
  }
  paintRulesTabs();
}

function paintRulesTabs() {
  const hasNew = lastRulesCount > 0;
  const show = hasNew && rulesTab === 'new' ? 'new' : 'base';
  rulesTab = show;
  document.querySelectorAll('.rules-tab').forEach((b) => {
    b.classList.toggle('on', b.dataset.rulesTab === show);
    b.setAttribute('aria-selected', b.dataset.rulesTab === show ? 'true' : 'false');
  });
  $('pane-rules-new')?.classList.toggle('hide', show !== 'new');
  $('pane-rules-base')?.classList.toggle('hide', show !== 'base');
}

function openRules() {
  rulesTab = lastRulesCount > 0 ? 'new' : 'base';
  paintRulesTabs();
  $('rules-modal')?.classList.remove('hide');
}

function closeRules() {
  $('rules-modal')?.classList.add('hide');
}

const PACK_NAMES = {
  'ink-yard': 'Чернильный Ярд',
  'blue-dossier': 'Синее досье',
  'balcony-tales': 'Балконные сказки',
};

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

const LOUPE = '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><circle cx="10" cy="10" r="6.2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14.8 14.8L20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

function cardArt(c, overlayHtml = '') {
  if (!c?.image) return overlayHtml ? `<div class="art no-img">${overlayHtml}</div>` : '';
  return `<div class="art"><img src="${esc(c.image)}" alt="" loading="lazy">${overlayHtml}</div>`;
}

function ownerMark(slot) {
  const token = slot?.owner_token;
  if (!token) return '<span class="card-owner mystery" title="Пока не видно, чья карта">?</span>';
  const side = token % 2 === 0 ? 'white' : 'red';
  const name = slot.owner_name ? ` ${esc(slot.owner_name)}` : '';
  return `<span class="card-owner ${side}" title="№${token}${name}">${token}</span>`;
}

function loupeButton(src) {
  if (!src) return '';
  return `<button type="button" class="card-loupe" data-zoom="${esc(src)}" aria-label="Открыть карту">${LOUPE}</button>`;
}

function openCardZoom(src, from) {
  const frame = $('card-zoom-frame');
  if (!src || !frame) return;
  frame.querySelectorAll('.card-owner, .bet-layer').forEach((n) => n.remove());
  $('card-zoom-img').src = src;
  const card = from?.closest?.('.stand-card, .slot, .art');
  const owner = card?.querySelector('.card-owner');
  const bets = card?.querySelector('.bet-layer');
  if (owner) frame.appendChild(owner.cloneNode(true));
  if (bets) frame.appendChild(bets.cloneNode(true));
  $('card-modal').classList.remove('hide');
}

function closeCardZoom() {
  $('card-modal')?.classList.add('hide');
  $('card-zoom-frame')?.querySelectorAll('.card-owner, .bet-layer').forEach((n) => n.remove());
  if ($('card-zoom-img')) $('card-zoom-img').removeAttribute('src');
}

function barCoin(n) {
  const count = Math.max(0, Number(n) || 0);
  const coin = count
    ? `<span class="coin-pile bar-coin" title="${count}"><span class="coin" style="bottom:0"><i></i></span></span>`
    : '';
  return `<span class="coin-with-n">${coin}<b class="coin-n">${count}</b></span>`;
}

function coinPile(n) {
  const count = Math.max(0, Math.min(12, Number(n) || 0));
  if (!count) {
    return '<div class="coin-pile ghost" title="Ставок нет" aria-label="Ставок нет"><span class="coin" style="bottom:0"><i></i></span></div>';
  }
  const shown = Math.min(3, count);
  const coins = Array.from({ length: shown }, (_, i) =>
    `<span class="coin" style="bottom:${i * 4}px"><i></i></span>`).join('');
  const badge = count > shown ? `<span class="coin-count">${count}</span>` : '';
  return `<div class="coin-pile" title="Куча ${count}" aria-label="Куча ${count}">${coins}${badge}</div>`;
}

function avatarStack(betters, hideNames) {
  const list = Array.isArray(betters) ? betters : [];
  if (!list.length) return '<div class="avatar-stack empty"></div>';
  // Пока видно до 4 кружков, дальше +N (дизайнер дорисует расклад).
  const visible = 4;
  const more = Math.max(0, list.length - visible);
  const avatars = list.map((b, i) => {
    const tip = hideNames ? `+${b.amount}` : `№${b.token} ${b.name} · ${b.amount}`;
    return `<span class="bet-avatar ${b.side || ''}${i >= visible ? ' is-extra' : ''}" title="${esc(tip)}">${b.token}</span>`;
  }).join('');
  const badge = more > 0
    ? `<span class="bet-more" title="${hideNames ? 'ещё ставки' : list.slice(visible).map((b) => `№${b.token} ${b.name}`).join(', ')}">+${more}</span>`
    : '';
  return `<div class="avatar-stack" tabindex="0">${avatars}${badge}</div>`;
}

const LAY_UP = '<svg viewBox="0 0 32 20" width="28" height="16" aria-hidden="true"><path d="M6 18V8h20v10" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 14V3M11.5 7L16 2.5 20.5 7" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

/**
 * Три фиксированные позы кучи (как у дизайнера), дальше только цифра:
 * 1 — диагональ ↘ (правый верх → левый низ)
 * 2 — почти вертикаль, −30°
 * 3 — почти горизонталь сверху, +20° от горизонтали вниз
 */
const LAY_POSES = [
  { transform: 'translate(-8%, 6%) rotate(45deg)', z: 1, cls: 'lay-1' },
  { transform: 'translate(6%, -3%) rotate(-30deg)', z: 2, cls: 'lay-2' },
  { transform: 'translate(-2%, 8%) rotate(110deg)', z: 3, cls: 'lay-3' },
];

function handCardChrome(c, canLay) {
  const title = esc(c.title || 'Карта');
  const body = esc(c.body || '');
  const info = `<button type="button" class="card-info" data-info-title="${title}" data-info-body="${body}" aria-label="Название карты">i</button>`;
  const zoom = loupeButton(c.image);
  const lay = canLay
    ? `<button type="button" class="card-lay-up" data-lay="${c.id}" aria-label="Положить карту">${LAY_UP}</button>`
    : '';
  return `${info}${zoom}${lay}`;
}

function openCardInfo(title, body) {
  $('card-info-title').textContent = title || 'Карта';
  $('card-info-body').textContent = body || '';
  $('card-info-modal')?.classList.remove('hide');
}

function closeCardInfo() {
  $('card-info-modal')?.classList.add('hide');
}

function render() {
  const gate = $('gate');
  const game = $('game');
  if (!state) {
    gate.classList.remove('hide');
    game.classList.add('hide');
    showGateMode(pendingJoinCode ? 'guest' : 'host');
    return;
  }
  gate.classList.add('hide');
  game.classList.remove('hide');
  const { me, session, round, situation, hand, board, players, ledger, flags, rules, teams, settle } = state;
  $('code').textContent = session.code;
  const invite = joinUrl(session.code);
  $('invite-url').textContent = invite;
  // В игре блок кода/QR не показываем (лобби — да; зритель — позже).
  const showInvite = session.status === 'lobby';
  $('invite-block')?.classList.toggle('hide', !showInvite);
  $('game').classList.toggle('playing', session.status === 'playing');
  if (session.status === 'playing') {
    $('meta').textContent = '';
    const token = me.token_ready
      ? `<span class="token ${me.side}">${me.token}</span>`
      : `<span class="token mystery">?</span>`;
    $('who').innerHTML = token;
    renderKit(me, players, true);
  } else {
    $('meta').textContent = `${session.status} · ${PACK_NAMES[session.pack_id] || session.pack_id || 'колода'} · тур ${session.current_round || '—'} / ${session.max_rounds} · лимит займа ${session.loan_limit}${session.bots_auto === false ? ' · ручной темп' : ''}`;
    const tokenBit = me.token_ready
      ? `<span class="token ${me.side}">${me.token}</span> ${esc(me.name)} · ${me.side === 'white' ? 'белый' : 'красный'}`
      : `<span class="token mystery">?</span> ${esc(me.name)} · номер откроется на старте`;
    $('who').innerHTML = `Ты ${tokenBit} · золотых ${me.balance} · траты ${me.spend}${me.deposited ? ' · вклад' : ''}`;
    if (!me.token_ready && session.token_buy_rub) {
      $('who').innerHTML += `<div class="muted" style="margin-top:4px">Хочешь свой номер — позже можно будет выбрать за ${session.token_buy_rub} ₽ (донат).</div>`;
    }
    renderKit(me, players, false);
  }
  renderSeats(players, me);
  const drinkBtn = $('drink-toggle');
  const isBlue = me.drink_tag === 'blue';
  drinkBtn.classList.toggle('blue', isBlue);
  drinkBtn.classList.toggle('steklyshki', !isBlue);
  drinkBtn.title = isBlue
    ? 'Синие. Нажми — стать стеклышком'
    : 'Стеклышки. Нажми — в синие';
  $('drink-label').textContent = isBlue ? 'Синие' : 'Стеклышки';

  $('host-actions').classList.toggle('hide', !me.is_host);
  $('start-btn').classList.toggle('hide', session.status !== 'lobby');
  $('bots-btn').classList.toggle('hide', session.status !== 'lobby' || !me.is_host);
  const tickBtn = $('bots-tick-btn');
  if (tickBtn) {
    tickBtn.classList.toggle('hide', !(me.is_host && session.status === 'playing' && session.bots_auto === false));
  }
  const sizeBox = $('table-size-box');
  if (sizeBox) {
    const showSize = me.is_host && session.status === 'lobby';
    sizeBox.classList.toggle('hide', !showSize);
    if (showSize) {
      const target = Number(session.target_players) || 6;
      document.querySelectorAll('.size-preset').forEach((b) => {
        b.classList.toggle('on', Number(b.dataset.size) === target);
      });
      const custom = $('table-size-custom');
      if (custom && ![6, 12, 24].includes(target)) custom.value = String(target);
      const now = (players || []).length;
      const needBots = Math.max(0, target - now);
      $('table-size-hint').textContent = needBots
        ? `Цель ${target}. Сейчас ${now}. «Добить ботами» добавит ещё ${needBots}.`
        : `Цель ${target}. Стол полный (${now}).`;
      $('bots-btn').textContent = needBots ? `Добить ботами (+${needBots})` : 'Стол полный';
    }
  }

  if (round) {
    $('round-bubble').textContent = `Тур ${round.number}`;
    let sideText = `Кладут ${sideLabel(round.laying_side)}, ставят ${sideLabel(round.betting_side)}.`;
    if (round.phase === 'lay') {
      sideText = `Выкладка: ${sideLabel(round.laying_side)} кладут карты. ${sideLabel(round.betting_side)} ждут.`;
    } else if (round.phase === 'bet') {
      sideText = `Ставки: ${sideLabel(round.betting_side)} ставят. ${sideLabel(round.laying_side)} ждут.`;
    } else if (round.phase === 'settle') {
      sideText = 'Итог тура — прочитай и отметь 👍 или ✕';
    }
    $('side-frame').textContent = sideText;
    $('side-frame').classList.remove('hide');
    $('situation').textContent = situation && situation.body ? situation.body : '';
    $('situation-bubble').classList.toggle('hide', !(situation && situation.body));
    const cap = flags?.bet_total_cap || 1;
    $('bet-cap').textContent = round.phase === 'bet'
      ? `Ставки: ${me.bet_total || 0} из ${cap} за тур${flags?.bet_split ? ' (можно дробить по картам)' : ''}.`
      : '';
  } else {
    $('round-bubble').textContent = 'Стол';
    $('side-frame').textContent = '';
    $('side-frame').classList.add('hide');
    $('situation').textContent = '';
    $('situation-bubble').classList.add('hide');
    $('bet-cap').textContent = '';
  }
  renderRulesCards(rules);

  const others = (players || []).filter((p) => p.id !== me.id);
  $('deal-player').innerHTML = others.map((p) => `<option value="${p.id}">№${p.token} ${p.name}</option>`).join('');

  const cards = hand || [];
  const canLayEarly = round && round.phase === 'lay' && me.side === round.laying_side && !me.laid && session.status === 'playing';
  const handCount = $('hand-count');
  if (handCount) {
    handCount.textContent = cards.length ? String(cards.length) : '';
    handCount.hidden = !cards.length;
  }
  $('hand').innerHTML = cards.map((c) => `
    <div class="slot art-card hand-card ${selectedCard === c.id ? 'picked' : ''}" data-card="${c.id}">
      ${cardArt(c, handCardChrome(c, canLayEarly))}
    </div>`).join('') || '<div class="muted">Рука пуста</div>';

  const layingNow = round && round.phase === 'lay';
  const laid = board || [];
  const layPile = $('lay-pile');

  if (layingNow) {
    // Стол дизайнера (пунктир + высота 1 ряд + ~2/3 второго) всегда виден.
    // Рубашки — поверх, кучей по центру, без меток.
    $('board').innerHTML = '';
    $('board').hidden = true;
    $('empty-table').hidden = false;
    if (layPile) {
      if (!laid.length) {
        layPile.hidden = true;
        layPile.setAttribute('aria-hidden', 'true');
        layPile.innerHTML = '';
      } else {
        layPile.hidden = false;
        layPile.setAttribute('aria-hidden', 'false');
        const show = Math.min(3, laid.length);
        const imgs = LAY_POSES.slice(0, show).map((p) =>
          `<img class="lay ${p.cls}" src="/assets/card-back.jpg" alt="" style="transform:${p.transform};z-index:${p.z}" draggable="false">`
        ).join('');
        // С 4-й карты визуально не кладём — на куче цифра.
        const count = laid.length >= 4 ? `<b class="lay-count">${laid.length}</b>` : '';
        layPile.innerHTML = imgs + count;
      }
    }
  } else {
    if (layPile) {
      layPile.hidden = true;
      layPile.setAttribute('aria-hidden', 'true');
      layPile.innerHTML = '';
    }
    const bettingUi = round?.phase === 'bet'
      && me.side === round.betting_side
      && !me.ready
      && session.status === 'playing';
    const myBetSlots = new Set((me.my_bets || []).map((b) => Number(b.slot)));
    const boardHtml = laid.map((s) => {
      const plus = (bettingUi && !s.mine)
        ? `<button type="button" class="bet-plus ${myBetSlots.has(Number(s.slot)) ? 'on' : ''}" data-bet-slot="${s.slot}" aria-label="Поставить на эту карту">+</button>`
        : '';
      const overlay = `${ownerMark(s)}${loupeButton(s.image)}${plus}<div class="bet-layer">${coinPile(s.pile || 0)}${avatarStack(s.betters || [], !!flags?.hide_owners && round?.phase === 'bet')}</div>`;
      return `
      <div class="slot art-card board-card stand-card ${s.mine ? 'mine' : ''}${myBetSlots.has(Number(s.slot)) ? ' my-bet' : ''}" data-slot="${s.slot}">
        ${cardArt(s, overlay)}
      </div>`;
    }).join('');
    const ghost = boardHtml && laid.length % 2 === 1
      ? '<div class="slot-ghost" aria-hidden="true"></div>'
      : '';
    $('board').innerHTML = boardHtml + ghost;
    $('board').hidden = !boardHtml;
    $('empty-table').hidden = !!boardHtml;
  }

  const settleOv = $('settle-overlay');
  const settleReopen = $('settle-reopen');
  if (round?.phase !== 'settle') settleDismissed = false;
  if (settleOv) {
    const inSettle = round?.phase === 'settle' && settle;
    const showSettle = inSettle && !settleDismissed;
    settleOv.classList.toggle('hide', !showSettle);
    settleOv.hidden = !showSettle;
    settleOv.setAttribute('aria-hidden', showSettle ? 'false' : 'true');
    if (settleReopen) {
      const showReopen = inSettle && settleDismissed && !settle.me_acked;
      settleReopen.classList.toggle('hide', !showReopen);
      settleReopen.hidden = !showReopen;
    }
    if (inSettle) {
      $('settle-title').textContent = `Итог тура ${round.number}`;
      const list = settle.payouts || [];
      $('settle-list').innerHTML = list.length
        ? list.map((p) => {
          const side = p.side || ((Number(p.token) % 2 === 0) ? 'white' : 'red');
          return `<div class="settle-row">
            <span class="token ${side}">${p.token}</span>
            <span class="settle-dots" aria-hidden="true"></span>
            <span class="settle-plus">+</span>
            ${coinPile(p.gold)}
          </div>`;
        }).join('')
        : '<div class="settle-row"><span class="muted">Никто не получил золото</span></div>';
      const waiting = !settle.me_acked;
      $('settle-hint').textContent = waiting
        ? `Ознакомься с итогом. Ждём игроков: ${settle.acks_humans || 0} / ${settle.humans || 0}`
        : `Ознакомлен. Ждём игроков: ${settle.acks_humans || 0} / ${settle.humans || 0}`;
      $('settle-like').disabled = !waiting;
    }
  }

  $('players').innerHTML = players.map((p) => `
    <tr>
      <td>${p.token_ready ? `<span class="token ${p.side}">${p.token}</span>` : `<span class="token mystery">?</span>`}</td>
      <td>${esc(p.name)}${p.is_host ? ' ★' : ''}${p.is_bot ? ' · бот' : ''}</td>
      <td>${p.team_name || '—'}</td>
      <td>${p.balance}</td>
      <td>${p.spend}</td>
      <td>${p.points_top} / ${p.points_volume}</td>
    </tr>`).join('');

  $('ledger').innerHTML = (ledger || []).map((t) =>
    `<div class="muted">${t.created_at} · ${t.type} · ${t.amount}${t.spend ? ' (трата)' : ''} ${t.meta || ''}</div>`
  ).join('');

  const canLay = !!canLayEarly;
  const bettingSide = round && round.phase === 'bet' && me.side === round.betting_side && session.status === 'playing';
  const canBet = bettingSide && !me.ready && (me.bet_left > 0 || !(me.my_bets || []).length);
  const hasMyBet = bettingSide && (me.my_bets || []).length > 0 && !me.ready;
  const trayCue = $('tray-cue');
  if (trayCue) {
    let cue = '';
    if (session.status === 'playing' && round) {
      if (canLay) {
        cue = 'Стрелка вверх на карте — положить. Пас справа сверху.';
      } else if (round.phase === 'lay' && me.laid) {
        cue = 'Уже выложил — ждём свою сторону.';
      } else if (round.phase === 'lay') {
        cue = `Кладут ${sideLabel(round.laying_side)}. Ты ждёшь.`;
      } else if (bettingSide && me.ready) {
        cue = 'Ты готов — ждём остальных красных.';
      } else if (hasMyBet) {
        cue = 'Ставка стоит. Можно снять и переставить или нажать «Готов».';
      } else if (canBet) {
        cue = 'Плюс на карте — ставка. Пас — без ставки. Готов — зафиксировать.';
      } else if (round.phase === 'bet') {
        cue = `Ставят ${sideLabel(round.betting_side)}.`;
      }
    }
    trayCue.textContent = cue;
    trayCue.classList.toggle('hide', !cue);
  }
  $('lay-pass')?.classList.toggle('hide', !canLay);
  $('bet-pass')?.classList.toggle('hide', !(bettingSide && !me.ready && !(me.my_bets || []).length));
  $('bet-cancel')?.classList.toggle('hide', !hasMyBet);
  $('ready-btn')?.classList.toggle('hide', !(bettingSide && !me.ready && ((me.my_bets || []).length > 0 || me.bet_done)));
  // Пас или ставка+готов всегда видны красным до ready
  if (bettingSide && !me.ready) {
    $('ready-btn')?.classList.toggle('hide', (me.my_bets || []).length === 0 && !me.bet_done);
  }
  const showActions = ['bet-pass', 'bet-cancel', 'ready-btn'].some((id) => {
    const el = $(id);
    return el && !el.classList.contains('hide');
  }) || !!$('bet-cap').textContent;
  $('actions-card').classList.toggle('hide', !showActions);
  $('econ').classList.toggle('hide', session.status === 'lobby');
  const playing = session.status === 'playing';
  $('deals').classList.toggle('hide', !playing);
  $('p2p-btn').classList.toggle('hide', !(playing && flags?.p2p_loan));
  $('sell-card-btn').classList.toggle('hide', !(playing && flags?.card_market));
  $('card-bank-btn').classList.toggle('hide', !(playing && flags?.card_bank));
  $('deposit-btn').classList.toggle('hide', !(playing && flags?.deposit && !me.deposited));
  $('team-box').classList.toggle('hide', !(playing && flags?.teams));
  $('sell-hint').textContent = selectedCard
    ? `Выбрана карта #${selectedCard} — продай выбранному игроку или в фазе выкладки положи на стенд.`
    : (flags?.card_market ? 'Кликни карту в руке, чтобы выбрать для продажи.' : '');
  $('invites').innerHTML = (me.invites || []).map((i) =>
    `<div class="row" style="margin-top:6px"><span>Приглашение в «${i.team_name}» от ${i.captain_name}</span><button data-accept-team="${i.team_id}">Войти (1 монета при сборе)</button></div>`
  ).join('');
  $('teams-list').innerHTML = (teams || []).map((t) =>
    `${t.status === 'active' ? '●' : '○'} ${t.name} · траты ${t.spend} · ${(t.members || []).map((m) => m.name).join(', ')}`
  ).join('<br>');
  const owed = (me.debts_owed || []).map((d) =>
    `Должен №${d.token} ${d.name}: ${d.amount} <button data-repay="${d.lender_id}">Вернуть 1</button>`
  );
  const lent = (me.debts_lent || []).map((d) => `Тебе должен №${d.token} ${d.name}: ${d.amount}`);
  $('debts').innerHTML = [...owed, ...lent].join('<br>');
}

document.addEventListener('click', async (e) => {
  const zoomBtn = e.target.closest?.('.card-loupe');
  if (zoomBtn) {
    e.preventDefault();
    e.stopPropagation();
    openCardZoom(zoomBtn.dataset.zoom, zoomBtn);
    return;
  }
  const infoBtn = e.target.closest?.('.card-info');
  if (infoBtn) {
    e.preventDefault();
    e.stopPropagation();
    openCardInfo(infoBtn.dataset.infoTitle, infoBtn.dataset.infoBody);
    return;
  }
  const layUp = e.target.closest?.('[data-lay]');
  if (layUp) {
    e.preventDefault();
    e.stopPropagation();
    (async () => {
      try {
        showErr('');
        const id = Number(layUp.dataset.lay);
        state = await api('round/lay', authBody({ card_id: id }));
        selectedCard = null;
        render();
      } catch (err) {
        showErr(err.message);
      }
    })();
    return;
  }
  if (e.target.id === 'card-info-close' || e.target.id === 'card-info-modal') {
    closeCardInfo();
    return;
  }
  try {
    showErr('');
    if (e.target.id === 'create') {
      if (!$('name').value.trim()) throw new Error('Как звать за столом?');
      state = await api('session/create', {
        name: $('name').value,
        drink_tag: 'steklyshki',
        pack_id: $('pack').value,
      });
      store.set(state);
      render();
    }
    if (e.target.id === 'join') {
      await doJoin($('join-code').value || pendingJoinCode);
    }
    if (e.target.id === 'goto-join-btn') {
      const code = ($('join-code-host')?.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
      if (!code) throw new Error('Введи код комнаты');
      pendingJoinCode = code;
      $('join-code').value = code;
      showGateMode('guest');
      showErr(`Комната ${code} — введи ник и жми «За стол».`);
    }
    if (e.target.id === 'goto-host-btn') {
      pendingJoinCode = null;
      history.replaceState({}, '', location.pathname);
      showGateMode('host');
      showErr('');
    }
    if (e.target.id === 'scan-qr-guest-btn') {
      await openQrScan();
    }
    if (e.target.closest('#drink-toggle')) {
      const next = state?.me?.drink_tag === 'blue' ? 'steklyshki' : 'blue';
      state = await api('player/drink', authBody({ drink_tag: next }));
      render();
    }
    if (e.target.id === 'copy-code-btn') {
      if (!state?.session?.code) throw new Error('Нет кода комнаты');
      await copyText(state.session.code);
      showErr(`Код ${state.session.code} скопирован`);
    }
    if (e.target.id === 'share-invite-btn') {
      if (!state?.session?.code) throw new Error('Нет кода комнаты');
      const url = joinUrl(state.session.code);
      const text = `UFGium — код ${state.session.code}\n${url}`;
      if (navigator.share) {
        await navigator.share({ title: 'UFGium', text, url });
      } else {
        await copyText(text);
        showErr('Ссылка скопирована — вставь в чат');
      }
    }
    if (e.target.id === 'show-qr-btn') {
      if (!state?.session?.code) throw new Error('Нет кода комнаты');
      await openQrShow(state.session.code);
    }
    if (e.target.id === 'scan-qr-btn') {
      await openQrScan();
    }
    if (e.target.id === 'bots-btn') {
      state = await api('session/bots', authBody());
      render();
    }
    if (e.target.id === 'bots-tick-btn') {
      state = await api('session/bots-tick', authBody());
      const tick = state.bot_tick;
      if (tick?.name) {
        const act = tick.action === 'lay' ? 'выложил карту' : (tick.action === 'bet' ? 'поставил' : (tick.action === 'pass' ? 'пас' : 'сходил'));
        showErr(`Бот №${tick.token} ${tick.name}: ${act}`);
      } else {
        showErr('Больше некому ходить в этой фазе');
      }
      render();
    }
    if (e.target.classList?.contains('size-preset')) {
      const size = Number(e.target.dataset.size);
      state = await api('session/table-size', authBody({ size }));
      render();
    }
    if (e.target.id === 'table-size-apply') {
      const size = Number($('table-size-custom').value);
      if (!size) throw new Error('Впиши число игроков');
      state = await api('session/table-size', authBody({ size }));
      render();
    }
    if (e.target.id === 'start-btn') {
      state = await api('session/start', authBody());
      render();
    }
    if (e.target.id === 'end-btn') {
      state = await api('session/end', authBody());
      render();
    }
    if (e.target.id === 'lay-pass') {
      state = await api('round/lay', authBody({ card_id: null }));
      selectedCard = null;
      render();
    }
    if (e.target.id === 'bet-pass') {
      state = await api('round/pass', authBody());
      render();
    }
    if (e.target.id === 'bet-cancel') {
      state = await api('round/bet-cancel', authBody());
      render();
    }
    if (e.target.id === 'ready-btn') {
      state = await api('round/ready', authBody());
      render();
    }
    if (e.target.id === 'settle-like' || e.target.closest?.('#settle-like')) {
      state = await api('round/settle-ack', authBody({ choice: 'like' }));
      settleDismissed = false;
      render();
    }
    if (e.target.id === 'settle-close' || e.target.closest?.('#settle-close')) {
      settleDismissed = true;
      render();
      return;
    }
    if (e.target.id === 'settle-reopen') {
      settleDismissed = false;
      render();
      return;
    }
    if (e.target.id === 'bet-confirm-no' || e.target.id === 'bet-confirm-modal') {
      closeBetConfirm();
    }
    if (e.target.id === 'bet-confirm-yes') {
      const slot = pendingBetSlot;
      closeBetConfirm();
      if (!slot) return;
      if ((state?.me?.my_bets || []).length) {
        state = await api('round/bet-cancel', authBody());
      }
      state = await api('round/bet', authBody({ slot, amount: 1 }));
      render();
    }
    if (e.target.id === 'loan-btn') {
      state = await api('wallet/loan', authBody());
      render();
    }
    if (e.target.id === 'voucher-btn') {
      state = await api('wallet/voucher', authBody());
      render();
    }
    if (e.target.id === 'voucher-sell-btn') {
      state = await api('wallet/voucher-sell', authBody());
      render();
    }
    if (e.target.id === 'card-bank-btn') {
      state = await api('wallet/card-bank', authBody());
      render();
    }
    if (e.target.id === 'deposit-btn') {
      state = await api('wallet/deposit', authBody());
      render();
    }
    if (e.target.id === 'p2p-btn') {
      state = await api('wallet/p2p', authBody({ to_player_id: Number($('deal-player').value) }));
      render();
    }
    if (e.target.id === 'sell-card-btn') {
      if (!selectedCard) throw new Error('Сначала кликни карту в руке');
      state = await api('deal/card', authBody({ to_player_id: Number($('deal-player').value), card_id: selectedCard }));
      selectedCard = null;
      render();
    }
    if (e.target.id === 'team-create-btn') {
      state = await api('team/create', authBody({ name: $('team-name').value || 'Без имени' }));
      render();
    }
    if (e.target.id === 'team-invite-btn') {
      state = await api('team/invite', authBody({ to_player_id: Number($('deal-player').value) }));
      render();
    }
    if (e.target.dataset.acceptTeam) {
      state = await api('team/accept', authBody({ team_id: Number(e.target.dataset.acceptTeam) }));
      render();
    }
    if (e.target.dataset.repay) {
      state = await api('wallet/p2p-repay', authBody({ lender_id: Number(e.target.dataset.repay) }));
      render();
    }
    if (e.target.id === 'redeem-btn') {
      state = await api('wallet/redeem', authBody());
      render();
    }
    if (e.target.id === 'leave') {
      forceStartScreen();
    }
    const card = e.target.closest('[data-card]');
    if (card && state?.session?.status === 'playing') {
      const id = Number(card.dataset.card);
      selectedCard = selectedCard === id ? null : id;
      render();
    }
    const plus = e.target.closest?.('[data-bet-slot]');
    if (plus) {
      e.preventDefault();
      e.stopPropagation();
      openBetConfirm(Number(plus.dataset.betSlot));
      return;
    }
  } catch (err) {
    showErr(err.message);
  }
});

function openBetConfirm(slot) {
  pendingBetSlot = slot;
  const has = (state?.me?.my_bets || []).length > 0;
  const title = $('bet-confirm-title');
  const text = $('bet-confirm-text');
  const yes = $('bet-confirm-yes');
  if (title) title.textContent = has ? 'Переставить ставку?' : 'Поставить ставку?';
  if (text) {
    text.textContent = has
      ? `Снять текущую и поставить 1 монету на карту #${slot}?`
      : `Поставить 1 монету на карту #${slot}?`;
  }
  if (yes) yes.textContent = has ? 'Переставить' : 'Поставить';
  $('bet-confirm-modal')?.classList.remove('hide');
}

function closeBetConfirm() {
  pendingBetSlot = null;
  $('bet-confirm-modal')?.classList.add('hide');
}

document.getElementById('qr-file')?.addEventListener('change', async (e) => {
  try {
    showErr('');
    $('qr-scan-err').textContent = '';
    await decodeQrFromFile(e.target.files?.[0]);
  } catch (err) {
    $('qr-scan-err').textContent = err.message || 'Не удалось прочитать QR';
  }
});

document.getElementById('card-zoom-close')?.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  closeCardZoom();
});
document.getElementById('card-modal')?.addEventListener('click', (e) => {
  if (e.target.id === 'card-modal') closeCardZoom();
});
document.getElementById('rules-open')?.addEventListener('click', (e) => {
  e.preventDefault();
  openRules();
});
document.getElementById('rules-close')?.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  closeRules();
});
document.getElementById('rules-modal')?.addEventListener('click', (e) => {
  if (e.target.id === 'rules-modal') closeRules();
});
document.getElementById('rules-tabs')?.addEventListener('click', (e) => {
  const tab = e.target.closest('[data-rules-tab]');
  if (!tab) return;
  rulesTab = tab.dataset.rulesTab;
  paintRulesTabs();
});

document.getElementById('invite-modal-close')?.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  closeInviteModal();
});

document.getElementById('invite-modal')?.addEventListener('click', (e) => {
  if (e.target.id === 'invite-modal') closeInviteModal();
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !$('card-modal').classList.contains('hide')) {
    closeCardZoom();
  }
  if (e.key === 'Escape' && !$('card-info-modal')?.classList.contains('hide')) {
    closeCardInfo();
  }
  if (e.key === 'Escape' && !$('bet-confirm-modal')?.classList.contains('hide')) {
    closeBetConfirm();
  }
  if (e.key === 'Escape' && !$('invite-modal').classList.contains('hide')) {
    closeInviteModal();
  }
  if (e.key === 'Escape' && !$('rules-modal').classList.contains('hide')) {
    closeRules();
  }
});

(function initTheme() {
  const btn = document.getElementById('theme');
  if (!btn) return;
  const paint = (light) => {
    document.documentElement.classList.toggle('light', light);
    btn.classList.toggle('light', light);
  };
  paint(document.documentElement.classList.contains('light'));
  btn.addEventListener('click', () => {
    const light = !document.documentElement.classList.contains('light');
    paint(light);
    try { localStorage.setItem('ufgium-theme', light ? 'light' : 'dark'); } catch (e) {}
  });
})();

window.addEventListener('load', async () => {
  const params = new URLSearchParams(location.search);
  if (params.get('reset') === '1' || params.get('leave') === '1') {
    forceStartScreen();
    history.replaceState({}, '', location.pathname);
  }

  const fromLink = (params.get('join') || params.get('code') || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
  if (fromLink) {
    pendingJoinCode = fromLink;
    $('join-code').value = fromLink;
  }

  render();
  if (store.get()) {
    try { await refresh(); } catch (e) { showErr(e.message); store.clear(); render(); }
  } else if (fromLink) {
    showGateMode('guest');
    showErr(`Тебя зовут за стол ${fromLink}. Ник на сегодня → «За стол».`);
  }
  timer = setInterval(() => { if (store.get()) refresh().catch(() => {}); }, 2000);
});
