/* ============================================================
   Shared WebSocket client — vanilla, zero dependencies.
   Auto-detects the WS URL and reconnects with backoff.
   ============================================================ */

export class ShowSocket {
  /**
   * @param {object} opts
   * @param {string} opts.role    'main' | 'touch' | 'op'
   * @param {string} [opts.slot]  A..F for touch pads
   * @param {string} [opts.label] human label for the presence list
   * @param {(msg:object)=>void} opts.onState
   * @param {(status:string)=>void} [opts.onStatus]
   * @param {(msg:object)=>void} [opts.onEvent]
   */
  constructor(opts) {
    this.opts = opts;
    this.ws = null;
    this.role = opts.role;
    this.slot = opts.slot || null;
    this.label = opts.label || opts.role;
    this.retry = 0;
    this.lastState = null;
    this.closed = false;
  }

  url() {
    if (window.BF1_WS_URL) return window.BF1_WS_URL;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${proto}//${location.hostname}:8787/ws`;
  }

  connect() {
    if (this.closed) return;
    this.opts.onStatus?.('connecting');

    let ws;
    try {
      ws = new WebSocket(this.url());
    } catch (e) {
      this.#retry();
      return;
    }
    this.ws = ws;

    ws.onopen = () => {
      this.retry = 0;
      this.opts.onStatus?.('online');
      this.send({ type: 'hello', role: this.role, slot: this.slot, label: this.label });
    };

    ws.onmessage = (ev) => {
      let msg;
      try { msg = JSON.parse(ev.data); } catch { return; }

      switch (msg.type) {
        case 'welcome':
          if (msg.state) this.#applyState(msg.state, 'welcome');
          this.opts.onEvent?.(msg);
          break;

        case 'state':
          // The console payload carries the answer; the public one never does.
          //
          // ORDER MATTERS: onEvent runs FIRST so the event handler can stash
          // per-event data (e.g. revealInfo with the correct answer) BEFORE
          // onState renders. If onState ran first it would render against
          // stale revealInfo — showing pads as "wrong" when they were right.
          this.opts.onEvent?.(msg);
          this.#applyState(this.role === 'main' ? msg.state : (msg.console || msg.state), msg.event);
          break;

        case 'tap_rejected':
          this.opts.onEvent?.(msg);
          break;

        case 'presence':
        case 'standings':
        case 'leaderboard':
        case 'error':
          this.opts.onEvent?.(msg);
          break;
      }
    };

    ws.onclose = () => {
      this.opts.onStatus?.('offline');
      this.#retry();
    };

    ws.onerror = () => { /* onclose always follows */ };
  }

  #retry() {
    if (this.closed) return;
    // 500ms, 1s, 2s, 4s… capped at 8s
    const delay = Math.min(8000, 500 * Math.pow(2, this.retry++));
    setTimeout(() => this.connect(), delay);
  }

  /** Ignore stale frames — an out-of-order delivery can never rewind a screen. */
  #applyState(state, event) {
    if (!state) return;
    if (this.lastState && typeof state.rev === 'number' && state.rev <= this.lastState.rev) {
      // Same or older revision: ignore, unless the event forces a re-render
      // (e.g. a rename that doesn't bump into a new phase).
      if (event !== 'rename' && event !== 'settings') return;
    }
    this.lastState = state;
    this.opts.onState?.(state, event);
  }

  send(obj) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(obj));
      return true;
    }
    return false;
  }

  // -- controller verbs (console only) -------------------------------
  prepare(opts = {})     { return this.send({ type: 'prepare', opts }); }
  question(opts = {})    { return this.send({ type: 'question', opts }); }
  reveal()               { return this.send({ type: 'reveal' }); }
  next(opts = {})        { return this.send({ type: 'next', opts }); }
  podium()               { return this.send({ type: 'podium' }); }
  reset(opts = {})       { return this.send({ type: 'reset', opts }); }
  rename(slot, name)     { return this.send({ type: 'rename', slot, name }); }
  setting(key, value)    { return this.send({ type: 'setting', key, value }); }
  skipTimer()            { return this.send({ type: 'skip' }); }
  standings()            { return this.send({ type: 'standings' }); }
  leaderboard()          { return this.send({ type: 'board' }); }
  autopilot(on)          { return this.send({ type: 'autopilot', on }); }

  // -- pad taps -------------------------------------------------------
  tap(answer) {
    return this.send({ type: 'tap', slot: this.slot, answer });
  }

  ping() {
    this.send({ type: 'ping', t: Date.now() / 1000 });
  }

  close() {
    this.closed = true;
    this.ws?.close();
  }
}

/**
 * Smooth countdown driven by rAF + a clock offset learned from the server.
 * The server is the source of truth for `deadline`; this only interpolates
 * locally so the bar moves smoothly without 20 Hz network chatter.
 */
export class Countdown {
  constructor() {
    this.offset = 0;      // serverNow - clientNow
    this.deadline = 0;     // server clock timestamp
    this.total = 0;        // ms
    this.raf = null;
    this.onTick = null;
    this.onDone = null;
  }

  /** Learn the server/client clock skew from a welcome/state payload. */
  sync(serverNow) {
    if (typeof serverNow === 'number') {
      this.offset = serverNow - Date.now() / 1000;
    }
  }

  start(deadline, totalMs, { onTick, onDone } = {}) {
    this.stop();
    this.deadline = deadline;
    this.total = Math.max(1, totalMs);
    this.onTick = onTick;
    this.onDone = onDone;

    const step = () => {
      const now = Date.now() / 1000 + this.offset;
      const left = Math.max(0, (this.deadline - now) * 1000);
      this.onTick?.(left, left / this.total);

      if (left <= 0) {
        this.raf = null;
        this.onDone?.();
        return;
      }
      this.raf = requestAnimationFrame(step);
    };
    this.raf = requestAnimationFrame(step);
  }

  stop() {
    if (this.raf) cancelAnimationFrame(this.raf);
    this.raf = null;
  }

  get msLeft() {
    const now = Date.now() / 1000 + this.offset;
    return Math.max(0, (this.deadline - now) * 1000);
  }
}

/** Tiny flash helper — pulses an element to draw the eye. */
export function flash(el, className = 'flash', ms = 620) {
  if (!el) return;
  el.classList.remove(className);
  void el.offsetWidth;          // force reflow so the animation restarts
  el.classList.add(className);
  setTimeout(() => el.classList.remove(className), ms);
}

/** Escape untrusted text before it touches innerHTML. */
export function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}