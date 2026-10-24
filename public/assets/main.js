/* ============================================================
   BANORTE-F1 · Pantalla principal (proyector)
   Role: 'main' → sólo recibe estado público, nunca la respuesta correcta.
   ============================================================ */

//?v=2 busts the ES-module cache so a kiosk that was left open overnight
// always runs the current build instead of a stale cached module.
import { ShowSocket, Countdown, esc } from './socket.js?v=2';

// ?bg=dark renders the same layout on navy (control monitor / browser test)
const bg = new URLSearchParams(location.search).get('bg');
if (bg === 'dark') document.body.dataset.bg = 'dark';

const $ = (id) => document.getElementById(id);

const el = {
  conn: $('conn'),
  phaseChip: $('phaseChip'),
  viewLobby: $('viewLobby'),
  viewIntro: $('viewIntro'),
  viewQuestion: $('viewQuestion'),
  viewPodium: $('viewPodium'),
  introCat: $('introCat'),
  qCat: $('qCat'),
  qNum: $('qNum'),
  qText: $('qText'),
  answers: $('answers'),
  revealExtras: $('revealExtras'),
  board: $('board'),
  timer: $('timer'),
  timerRing: $('timerRing'),
  timerNum: $('timerNum'),
  wash: $('wash'),
  sweep: $('sweep'),
  lobbyHint: $('lobbyHint'),
};

const KEY_LABELS = ['A', 'B', 'C', 'D'];

const countdown = new Countdown();
let lastPhase = null;
let lastState = null;
let revealInfo = null;   // { winnerSlot, winnerName, deltas, correctSlots }

const socket = new ShowSocket({
  role: 'main',
  label: 'proyector',

  onStatus: (s) => {
    el.conn.dataset.state = s === 'online' ? 'online' : (s === 'connecting' ? 'offline' : 'offline');
    el.conn.textContent = s === 'online' ? 'en vivo' : 'reconectando';
    if (s !== 'online') {
      el.lobbyHint.textContent = 'Reconectando con el servidor…';
    }
  },

  onEvent: (msg) => {
    switch (msg.type) {
      case 'welcome':
        countdown.sync(msg.state?.serverNow);
        el.lobbyHint.textContent = 'Esperando al presentador…';
        break;

      case 'state': {
        countdown.sync(msg.state?.serverNow);

        // --- flash beats -------------------------------------------------
        if (msg.event === 'reveal') {
          playWash('gold');
          playSweep();
          // Reveal data arrives in `data`; stash for the current view render.
          revealInfo = msg.data;
        } else if (msg.event === 'tap') {
          flashPad(msg.data.slot);
        } else if (msg.event === 'question') {
          revealInfo = null;
        } else if (msg.event === 'podium') {
          playWash();
          playSweep();
        }
        break;
      }
    }
  },

  onState: (state) => render(state),
});

function render(s) {
  renderPhase(s.phase);
  renderBoard(s.players, s.phase === 'reveal');

  switch (s.phase) {
    case 'intro':     renderIntro(s); break;
    case 'question':
    case 'reveal':    renderQuestion(s); break;
    case 'podium':    renderPodium(s); break;
    default:          renderLobby(s); break;
  }
}

function show(node) {
  [el.viewLobby, el.viewIntro, el.viewQuestion, el.viewPodium]
    .forEach((v) => v.classList.add('hidden'));
  node.classList.remove('hidden');
}

function renderPhase(phase) {
  const labels = {
    idle: 'En espera', intro: 'Próxima', question: 'Respondiendo',
    reveal: 'Respuesta', podium: 'Podio',
  };
  el.phaseChip.dataset.phase = phase;
  el.phaseChip.textContent = labels[phase] ?? phase;
  lastPhase = phase;
}

function renderLobby() {
  show(el.viewLobby);
  el.timer.style.opacity = '0';
  countdown.stop();
  el.revealExtras.innerHTML = '';
  el.answers.innerHTML = '';
}

function renderIntro(s) {
  show(el.viewIntro);
  el.timer.style.opacity = '0';
  countdown.stop();
  el.introCat.textContent = (s.next?.category ?? 'f1').toUpperCase();
}

function renderQuestion(s) {
  show(el.viewQuestion);
  const q = s.question;
  if (!q) return;

  el.qCat.textContent = (q.category ?? '').toUpperCase();
  el.qNum.textContent = `PREGUNTA ${String(s.questionIndex).padStart(2, '0')}`;
  el.qText.textContent = q.text;

  // --- answers ---
  const isReveal = s.phase === 'reveal';

  // The correct answer is read from the QUESTION itself (the server only
  // includes `ok` once revealed). This makes a cold load — a projector that
  // reboots mid-show — highlight the right answer just like a live reveal.
  const correctIdx = q.answers.findIndex((a) => a.ok === true);
  const revealedCorrect = isReveal && correctIdx >= 0 ? correctIdx : null;

  // Fall back to the event payload for the winner/tally (not in state).
  const breakdown = revealInfo?.breakdown ?? {};

  el.answers.innerHTML = q.answers.map((a, i) => {
    const key = KEY_LABELS[i] ?? String(i + 1);
    const hits = breakdown[i] ?? 0;
    const cls = [
      'answer',
      isReveal && i === revealedCorrect ? 'correct' : '',
      isReveal && i !== revealedCorrect ? 'dim' : '',
    ].filter(Boolean).join(' ');

    return `
      <div class="${cls}">
        <div class="tally" style="width:${hits ? Math.min(100, hits * 33) : 0}%"></div>
        <span class="key">${key}</span>
        <span>${esc(a.t)}</span>
        ${hits ? `<span class="tally-n">×${hits}</span>` : ''}
      </div>`;
  }).join('');

  // --- reveal extras: winner + fact ---
  if (isReveal) {
    let html = '';

    if (revealInfo?.winnerName) {
      html += `<div class="winner-banner">
        <span>🏆</span>
        <span>${esc(revealInfo.winnerName)}</span>
        <span class="mono">+${revealInfo.deltas?.[revealInfo.winnerSlot] ?? 0}</span>
      </div>`;
    }

    if (q.fact) {
      html += `<div class="fact">${esc(q.fact)}</div>`;
    }
    el.revealExtras.innerHTML = html;
  } else {
    el.revealExtras.innerHTML = '';
  }

  // --- timer ---
  if (s.deadline > 0) {
    el.timer.style.opacity = '1';
    const total = Math.max(1, (s.deadline - s.startedAt) * 1000);
    countdown.start(s.deadline, total, {
      onTick: (left, frac) => {
        el.timerNum.textContent = Math.ceil(left / 1000);
        el.timerRing.style.setProperty('--p', frac.toFixed(4));
        el.timer.dataset.urgent = left < 5000 && s.phase === 'question' ? '1' : '0';
      },
      onDone: () => { /* the server auto-reveals; nothing to do here */ },
    });
  } else {
    el.timer.style.opacity = '0';
    countdown.stop();
  }
}

function renderBoard(players, isReveal = false) {
  if (!players?.length) return;

  const sorted = [...players].sort((a, b) => b.points - a.points);
  const lead = sorted[0]?.points > 0 ? sorted[0].slot : null;

  el.board.innerHTML = sorted.map((p) => {
    const isCorrect = revealInfo?.correctSlots?.includes(p.slot);
    const isWrong   = isReveal && revealInfo && !isCorrect && p.lastAnswer !== null;

    return `
      <div class="board-p"
           data-slot="${esc(p.slot)}"
           data-lead="${p.slot === lead ? 1 : 0}"
           data-correct="${isCorrect ? 1 : 0}"
           data-wrong="${isWrong ? 1 : 0}">
        <span class="nm">${esc(p.name)}</span>
        <span class="pt">${p.points}</span>
      </div>`;
  }).join('');

  // Re-flash pads that just scored.
  if (revealInfo?.correctSlots) {
    revealInfo.correctSlots.forEach((s) => flashPad(s, 'padFlash'));
  }
}

function renderPodium(s) {
  show(el.viewPodium);
  el.timer.style.opacity = '0';
  countdown.stop();

  const top = [...(s.players ?? [])]
    .sort((a, b) => b.points - a.points)
    .slice(0, 3);

  // Visual order: 2nd, 1st, 3rd
  const order = [top[1], top[0], top[2]].filter(Boolean);

  el.viewPodium.innerHTML = order.map((p) => {
    const pos = top.indexOf(p) + 1;
    return `
      <div class="podium-col" data-pos="${pos}">
        <span class="podium-name">${esc(p.name)}</span>
        <span class="podium-pts">${p.points} pts</span>
        <div class="podium-block">${pos}</div>
      </div>`;
  }).join('');
}

// --- flash helpers -------------------------------------------------------
function playWash(kind = '') {
  el.wash.className = `wash ${kind}`;
  void el.wash.offsetWidth;
  el.wash.classList.add('go');
}

function playSweep() {
  el.sweep.classList.remove('go');
  void el.sweep.offsetWidth;
  el.sweep.classList.add('go');
}

function flashPad(slot, cls = 'flash') {
  const node = el.board.querySelector(`[data-slot="${slot}"]`);
  if (!node) return;
  node.classList.remove(cls);
  void node.offsetWidth;
  node.classList.add(cls);
  setTimeout(() => node.classList.remove(cls), 700);
}

// --- boot -----------------------------------------------------------------
// Kiosk diagnostics: keep the last runtime error on window so the operator
// (or a remote devtools session) can read it without console access.
window.__bf1LastError = null;
window.addEventListener('error', (e) => {
  window.__bf1LastError = {
    message: e.message,
    source: e.filename,
    line: e.lineno,
    col: e.colno,
    stack: e.error?.stack ?? null,
  };
});
window.addEventListener('unhandledrejection', (e) => {
  window.__bf1LastError = {
    message: String(e.reason?.message ?? e.reason),
    stack: e.reason?.stack ?? null,
  };
});

socket.connect();
setInterval(() => socket.ping(), 15000);

// Keep the screen awake on touch kiosks / long idle events.
if ('wakeLock' in navigator) {
  const request = () => navigator.wakeLock.request('screen').catch(() => {});
  request();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') request();
  });
}