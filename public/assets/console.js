/* ============================================================
   BANORTE-F1 · Consola de control (pantalla secundaria)
   Role: 'op' → comandos del show + edición del banco.
   Los pads A–F sirven como "buzzer" manual: al tocarlos envía un tap
   para el slot, útil cuando el gamepad físico no está a la mano.
   ============================================================ */

//?v=2 busts the ES-module cache so a kiosk that was left open overnight
// always runs the current build instead of a stale cached module.
import { ShowSocket, Countdown, esc } from './socket.js?v=2';

const $ = (id) => document.getElementById(id);

const el = {
  conn: $('conn'),
  toast: $('toast'),
  pads: $('pads'),
  roster: $('roster'),
  preview: $('preview'),
  pvCat: $('pvCat'),
  pvText: $('pvText'),
  pvAnswers: $('pvAnswers'),
  tallyBody: $('tallyBody'),
  padsQ: $('padsQ'),
  padsCat: $('padsCat'),
  padsText: $('padsText'),
  miniTimer: $('miniTimer'),
  miniTime: $('miniTime'),
  miniBar: $('miniBar'),
  bankList: $('bankList'),
  bankForm: $('bankForm'),
  ansEdit: $('ansEdit'),
  bankCat: $('bankCat'),
};

const SLOT_COLORS = {
  A: '#ff5f6d', B: '#ffa64d', C: '#ffe14d',
  D: '#5dff9e', E: '#4dd6ff', F: '#b98cff',
};
const KEY_LABELS = ['A', 'B', 'C', 'D'];

const API_BASE = window.BF1_API || `${location.protocol}//${location.hostname}:8091/api`;

let state = null;
let lastEvent = null;
let revealInfo = null;
let editingId = null;
let bankItems = [];
let categories = [];
const countdown = new Countdown();

// ---------------------------------------------------------------------------
// Socket
// ---------------------------------------------------------------------------
const socket = new ShowSocket({
  role: 'op',
  label: 'consola',

  onStatus: (s) => {
    el.conn.dataset.state = s === 'online' ? 'online' : 'offline';
    el.conn.textContent = s === 'online' ? 'en vivo' : 'reconectando';
  },

  onEvent: (msg) => {
    switch (msg.type) {
      case 'state':
        if (msg.event === 'reveal') revealInfo = msg.data;
        if (msg.event === 'question') revealInfo = null;
        break;
      case 'tap_rejected':
        buzz(msg.slot);
        toast(msg.error ?? 'Tap rechazado');
        break;
      case 'error':
        toast(msg.error ?? 'Error del servidor');
        break;
      case 'leaderboard':
        // could render a history panel later
        break;
    }
  },

  onState: (s) => {
    state = s;
    countdown.sync(s.serverNow);
    renderAll();
  },
});

function renderAll() {
  if (!state) return;
  renderQuestion();
  renderPads();
  renderRoster();
  renderTally();
  renderTimer();
  renderTransportState();
}

// ---------------------------------------------------------------------------
// Question preview (the console DOES see the correct answer)
// ---------------------------------------------------------------------------
function renderQuestion() {
  const q = state.question;
  const nxt = state.next;

  if (!q && nxt) {
    el.pvCat.textContent = (nxt.category ?? '').toUpperCase();
    el.pvText.textContent = `Preparada: ${nxt.text}`;
    el.pvAnswers.innerHTML = nxt.answers.map((a, i) => `
      <div class="pv-a"><span class="k">${KEY_LABELS[i] ?? i}</span>${esc(a.t)}</div>
    `).join('');
    el.padsCat.textContent = (nxt.category ?? '').toUpperCase();
    el.padsText.textContent = nxt.text;
    el.padsQ.classList.remove('idle');
    return;
  }

  if (!q) {
    el.pvCat.textContent = '—';
    el.pvText.textContent = state.phase === 'podium' ? 'Show finalizado' : 'Sin pregunta activa';
    el.pvAnswers.innerHTML = '';
    el.padsCat.textContent = '—';
    el.padsText.textContent = state.phase === 'podium'
      ? 'Show finalizado — ¡a trillions!'
      : 'Esperando al presentador…';
    el.padsQ.classList.add('idle');
    return;
  }

  el.pvCat.textContent = (q.category ?? '').toUpperCase();
  el.pvText.textContent = q.text;
  el.pvAnswers.innerHTML = q.answers.map((a, i) => `
    <div class="pv-a ${a.ok ? 'ok' : ''}">
      <span class="k">${KEY_LABELS[i] ?? i}</span>${esc(a.t)}
    </div>`).join('');

  el.padsCat.textContent = (q.category ?? '').toUpperCase();
  el.padsText.textContent = q.text;
  el.padsQ.classList.remove('idle');
}

// ---------------------------------------------------------------------------
// Pads — the flashing touch surface
// ---------------------------------------------------------------------------
function renderPads() {
  const players = state.players ?? [];
  if (!players.length) return;

  const isReveal = state.phase === 'reveal';
  const correct = revealInfo?.correct;
  const breakdown = revealInfo?.breakdown ?? {};

  // Which pads were right — prefer the live event payload, but fall back to
  // the per-player `correctIdx`/`lastAnswer` in state so a cold load (console
  // reloaded mid-reveal) still paints the right pads.
  const correctSlots = revealInfo?.correctSlots ?? players
    .filter((p) => p.correctIdx !== null && p.correctIdx !== undefined
                  && p.lastAnswer !== null && p.lastAnswer === p.correctIdx)
    .map((p) => p.slot);

  el.pads.innerHTML = players.map((p) => {
    let cls = 'pad';
    if (p.lastTapMs !== null && p.lastAnswer !== null) cls += ' locked';
    if (isReveal) {
      if (correctSlots.includes(p.slot)) cls += ' correct';
      else if (p.lastAnswer !== null) cls += ' wrong';
      else cls += ' dim';
    }

    return `
      <button class="${cls}" data-slot="${esc(p.slot)}" style="border-color:${SLOT_COLORS[p.slot]}33">
        ${p.lastTapMs !== null ? `<span class="pms">${p.lastTapMs}ms</span>` : ''}
        <span class="letter" style="color:${SLOT_COLORS[p.slot]}">${esc(p.slot)}</span>
        <span class="pname">${esc(p.name)}</span>
        <span class="ppts">${p.points}</span>
      </button>`;
  }).join('');

  // Show how many pads picked each answer (operator-only insight).
  if (isReveal && correct !== undefined) {
    [...el.pvAnswers.querySelectorAll('.pv-a')].forEach((node, i) => {
      const hits = breakdown[i] ?? 0;
      node.style.boxShadow = hits ? `inset 3px 0 0 var(--cyan)` : '';
    });
  }
}

function buzz(slot) {
  const pad = el.pads.querySelector(`[data-slot="${slot}"]`);
  if (!pad) return;
  pad.classList.remove('buzz');
  void pad.offsetWidth;
  pad.classList.add('buzz');
  setTimeout(() => pad.classList.remove('buzz'), 500);
}

// ---------------------------------------------------------------------------
// Roster (rename) + tally
// ---------------------------------------------------------------------------
function renderRoster() {
  const players = state.players ?? [];
  el.roster.innerHTML = players.map((p) => `
    <div class="roster-row" data-slot="${esc(p.slot)}">
      <span class="sl">${esc(p.slot)}</span>
      <input type="text" value="${esc(p.name)}" data-slot="${esc(p.slot)}" maxlength="18">
      <span class="sc">${p.points}</span>
    </div>`).join('');
}

function renderTally() {
  if (!revealInfo) {
    el.tallyBody.innerHTML = `<tr><td colspan="4" style="color:var(--ink-mute)">Sin datos aún</td></tr>`;
    return;
  }

  const rows = (state.players ?? [])
    .filter((p) => p.lastTapMs !== null || revealInfo.correctSlots?.includes(p.slot))
    .sort((a, b) => (a.lastTapMs ?? 1e9) - (b.lastTapMs ?? 1e9))
    .map((p) => {
      const ok = revealInfo.correctSlots?.includes(p.slot);
      const delta = revealInfo.deltas?.[p.slot] ?? 0;
      return `
        <tr data-ok="${ok ? 1 : 0}">
          <td>${esc(p.name)}</td>
          <td class="n">${p.lastTapMs ?? '—'}</td>
          <td class="n">${delta ? '+' + delta : '—'}</td>
          <td class="n">${p.points}</td>
        </tr>`;
    }).join('');

  el.tallyBody.innerHTML = rows || `<tr><td colspan="4" style="color:var(--ink-mute)">Nadie respondió</td></tr>`;
}

// ---------------------------------------------------------------------------
// Timer
// ---------------------------------------------------------------------------
function renderTimer() {
  if (!state.deadline || state.phase === 'idle') {
    el.miniTime.textContent = '—';
    el.miniBar.style.width = '0%';
    el.miniTimer.dataset.urgent = '0';
    countdown.stop();
    return;
  }

  const total = Math.max(1, (state.deadline - state.startedAt) * 1000);
  countdown.start(state.deadline, total, {
    onTick: (left, frac) => {
      el.miniTime.textContent = `${(left / 1000).toFixed(1)}s`;
      el.miniBar.style.width = `${(frac * 100).toFixed(1)}%`;
      el.miniTimer.dataset.urgent = left < 5000 && state.phase === 'question' ? '1' : '0';
    },
  });
}

function renderTransportState() {
  const q = (sel) => document.querySelector(sel);
  const active = state.phase;

  q('[data-act="reveal"]').disabled  = active !== 'question';
  q('[data-act="question"]').disabled = active !== 'intro' && active !== 'idle';
  q('[data-act="skip"]').disabled     = !state.deadline || active === 'idle' || active === 'reveal';
}

// ---------------------------------------------------------------------------
// Question bank editor
// ---------------------------------------------------------------------------
async function loadBank() {
  try {
    const [list, cats] = await Promise.all([
      fetch(`${API_BASE}/questions.php?limit=200`).then((r) => r.json()),
      fetch(`${API_BASE}/questions.php?action=categories`).then((r) => r.json()),
    ]);

    bankItems = list.items ?? [];
    categories = cats.items ?? [];

    el.bankCat.innerHTML = categories
      .map((c) => `<option value="${esc(c.slug)}">${esc(c.slug)} (${c.total})</option>`)
      .join('');

    el.bankList.innerHTML = bankItems.map((q) => `
      <div class="bank-item" data-active="${q.active}" data-id="${q.id}">
        <span class="bid">${q.id}</span>
        <span class="btext">${esc(q.text)}</span>
        <span class="bcat">${esc(q.category)}</span>
        <span class="bacts">
          <button class="mini-btn" data-edit="${q.id}" title="Editar">✎</button>
          <button class="mini-btn danger" data-del="${q.id}" title="Eliminar">✕</button>
        </span>
      </div>`).join('');
  } catch (e) {
    toast('No se pudo cargar el banco');
  }
}

function fillAnswerEditor(answers = [], correct = 0) {
  const rows = answers.length ? answers : ['', '', '', ''];
  el.ansEdit.innerHTML = rows.map((text, i) => `
    <div class="ans-edit-row" data-ok="${i === correct ? 1 : 0}">
      <button type="button" class="pick" data-pick="${i}">${KEY_LABELS[i] ?? i}</button>
      <input type="text" data-ans="${i}" value="${esc(typeof text === 'object' ? text.t : text)}"
             placeholder="Respuesta ${KEY_LABELS[i] ?? i + 1}">
    </div>`).join('');
}

function markCorrect(i) {
  [...el.ansEdit.children].forEach((row, idx) => {
    row.dataset.ok = idx === i ? '1' : '0';
  });
}

function currentCorrect() {
  const idx = [...el.ansEdit.children].findIndex((r) => r.dataset.ok === '1');
  return idx < 0 ? 0 : idx;
}

function currentAnswers() {
  return [...el.ansEdit.querySelectorAll('input[data-ans]')].map((i) => i.value.trim());
}

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------
document.querySelectorAll('[data-act]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const act = btn.dataset.act;
    ({
      prepare:  () => socket.prepare(),
      question: () => socket.question(),
      reveal:   () => socket.reveal(),
      next:     () => { revealInfo = null; socket.next(); },
      podium:   () => socket.podium(),
      reset:    () => socket.reset(),
      skip:     () => socket.skipTimer(),
    }[act] ?? (() => {}))();
  });
});

// Manual buzzers.
//
// Two ways to answer without the physical pads, both for when hardware is
// missing or a guest insists on using the tablet:
//   • tapping a PAD  → buzzes that slot with the last answer chosen on the
//     A–D bar (default A)
//   • tapping A–D    → buzzes the next slot in rotation with that answer

const SLOT_ORDER = ['A', 'B', 'C', 'D', 'E', 'F'];
let lastTouchedAnswer = 0;   // answer currently selected on the A–D bar
let rotSlot = 'A';           // next slot an A–D key will buzz

el.pads.addEventListener('click', (e) => {
  const pad = e.target.closest('.pad');
  if (!pad) return;
  socket.send({ type: 'tap', slot: pad.dataset.slot, answer: lastTouchedAnswer });
});

document.querySelectorAll('.touch-key').forEach((btn) => {
  btn.addEventListener('click', () => {
    const answer = Number(btn.dataset.answer);
    lastTouchedAnswer = answer;
    socket.send({ type: 'tap', slot: rotSlot, answer });
    rotSlot = SLOT_ORDER[(SLOT_ORDER.indexOf(rotSlot) + 1) % SLOT_ORDER.length];
  });
});

// Rename
el.roster.addEventListener('change', (e) => {
  const input = e.target.closest('input[data-slot]');
  if (!input) return;
  socket.rename(input.dataset.slot, input.value);
});

// Tabs
document.querySelectorAll('.tab').forEach((tab) => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t === tab));
    const onBank = tab.dataset.tab === 'bank';
    $('tabPads').classList.toggle('hidden', onBank);
    $('tabBank').classList.toggle('hidden', !onBank);
    if (onBank) loadBank();
  });
});

// Bank editor
el.ansEdit.addEventListener('click', (e) => {
  const pick = e.target.closest('[data-pick]');
  if (pick) markCorrect(Number(pick.dataset.pick));
});

el.bankList.addEventListener('click', async (e) => {
  const edit = e.target.closest('[data-edit]');
  const del = e.target.closest('[data-del]');

  if (edit) {
    const id = Number(edit.dataset.edit);
    const q = bankItems.find((x) => x.id === id);
    if (!q) return;
    editingId = id;
    el.bankForm.text.value = q.text;
    el.bankForm.fact.value = q.fact ?? '';
    el.bankForm.difficulty.value = String(q.difficulty ?? 1);
    el.bankCat.value = q.category;
    fillAnswerEditor(q.answers.map((a) => a.t), q.correct);
    el.bankForm.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }

  if (del) {
    const id = Number(del.dataset.del);
    if (!confirm(`¿Eliminar la pregunta #${id}?`)) return;
    await fetch(`${API_BASE}/questions.php?id=${id}`, { method: 'DELETE' });
    loadBank();
  }
});

el.bankForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    id: editingId || 0,
    text: el.bankForm.text.value.trim(),
    answers: currentAnswers(),
    correct: currentCorrect(),
    fact: el.bankForm.fact.value.trim(),
    category: el.bankCat.value,
    difficulty: Number(el.bankForm.difficulty.value),
  };

  const res = await fetch(`${API_BASE}/questions.php${editingId ? `?id=${editingId}` : ''}`, {
    method: editingId ? 'PUT' : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  }).then((r) => r.json());

  if (res.ok) {
    toast(editingId ? 'Pregunta actualizada' : 'Pregunta creada');
    resetForm();
    loadBank();
  } else {
    toast(res.error ?? 'No se pudo guardar');
  }
});

$('bankCancel').addEventListener('click', resetForm);

function resetForm() {
  editingId = null;
  el.bankForm.reset();
  fillAnswerEditor([], 0);
  markCorrect(0);
}

// ---------------------------------------------------------------------------
function toast(msg) {
  el.toast.textContent = msg;
  el.toast.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.toast.classList.remove('show'), 2400);
}

// ---------------------------------------------------------------------------
socket.connect();
setInterval(() => socket.ping(), 15000);
fillAnswerEditor([], 0);
markCorrect(0);