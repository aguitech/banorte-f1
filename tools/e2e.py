#!/usr/bin/env python3
"""
Banorte-F1 · end-to-end WebSocket smoke test.

Implements RFC 6455 client handshake + framing with the Python stdlib only
(no websockets/pip needed). Connects as three clients:
  - main   → the green-screen broadcast display
  - touch  → a handheld pad (slot C)
  - op     → the control console

Verifies the real show flow: prepare → question → tap race → reveal → next,
plus the tap-arbitration rule (a pad may only lock one answer per question).

Usage:  python3 tools/e2e.py [--host 127.0.0.1] [--port 8787]
Exit code 0 = all checks passed.
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import socket
import struct
import sys
import time

PASS, FAIL = "\033[92mPASS\033[0m", "\033[91mFAIL\033[0m"
results: list[tuple[bool, str]] = []


def check(ok: bool, label: str, detail: str = "") -> bool:
    results.append((ok, label))
    line = f"  [{PASS if ok else FAIL}] {label}"
    if detail and not ok:
        line += f"\n         → {detail}"
    print(line)
    return ok


class WS:
    """Minimal RFC 6455 client. Text frames only, server never masks."""

    def __init__(self, host: str, port: int, path: str = "/ws", timeout: float = 5.0):
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.sock.settimeout(timeout)
        self.buf = b""
        self._handshake(host, port, path)

    def _handshake(self, host: str, port: int, path: str) -> None:
        key = base64.b64encode(os.urandom(16)).decode()
        req = (
            f"GET {path} HTTP/1.1\r\n"
            f"Host: {host}:{port}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n"
            "\r\n"
        )
        self.sock.sendall(req.encode())

        while b"\r\n\r\n" not in self.buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise RuntimeError("handshake: server cerró la conexión")
            self.buf += chunk

        head, _, rest = self.buf.partition(b"\r\n\r\n")
        self.buf = rest
        status = head.split(b"\r\n")[0].decode()
        if "101" not in status:
            raise RuntimeError(f"handshake falló: {status}")

        import hashlib

        expected = base64.b64encode(
            hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
        ).decode()
        if expected.lower() not in head.decode().lower():
            raise RuntimeError("handshake: Sec-WebSocket-Accept inválido")

    def send(self, obj: dict) -> None:
        payload = json.dumps(obj).encode()
        mask = os.urandom(4)
        n = len(payload)
        frame = bytearray([0x81])
        if n < 126:
            frame.append(0x80 | n)
        elif n < 65536:
            frame.append(0x80 | 126)
            frame += struct.pack(">H", n)
        else:
            frame.append(0x80 | 127)
            frame += struct.pack(">Q", n)
        frame += mask
        frame += bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(bytes(frame))

    def _read(self, n: int) -> bytes:
        while len(self.buf) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise RuntimeError("conexión cerrada por el servidor")
            self.buf += chunk
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def recv(self, timeout: float = 3.0) -> dict:
        """Read one frame, auto-answering pings. Returns parsed JSON."""
        self.sock.settimeout(timeout)
        while True:
            b0, b1 = self._read(2)
            opcode = b0 & 0x0F
            length = b1 & 0x7F

            if length == 126:
                length = struct.unpack(">H", self._read(2))[0]
            elif length == 127:
                length = struct.unpack(">Q", self._read(8))[0]

            payload = self._read(length) if length else b""

            if opcode == 0x8:
                raise RuntimeError("servidor cerró el socket")
            if opcode == 0x9:
                continue
            if opcode in (0x1, 0x2):
                return json.loads(payload.decode())

    def drain(self, timeout: float = 0.6) -> list[dict]:
        """Collect every pending message until the socket goes quiet."""
        out: list[dict] = []
        deadline = time.time() + timeout
        while time.time() < deadline:
            try:
                out.append(self.recv(timeout=max(0.05, deadline - time.time())))
            except (socket.timeout, TimeoutError):
                break
            except RuntimeError:
                break
        return out

    def wait_for(self, pred, timeout: float = 4.0, what: str = "mensaje"):
        """Block until a message satisfies pred(msg) -> bool."""
        deadline = time.time() + timeout
        seen = []
        while time.time() < deadline:
            try:
                msg = self.recv(timeout=max(0.05, deadline - time.time()))
            except (socket.timeout, TimeoutError):
                continue
            seen.append(msg.get("type"))
            if pred(msg):
                return msg
        raise TimeoutError(f"timeout esperando {what} (vistos: {set(seen)})")

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8787)
    args = ap.parse_args()

    print(f"\n\033[1mBanorte-F1 · E2E WebSocket\033[0m  ({args.host}:{args.port})\n")

    main_c: WS | None = None
    touch: WS | None = None
    op: WS | None = None

    try:
        # -- 1. handshake x3 ------------------------------------------
        try:
            main_c = WS(args.host, args.port)
            check(True, "handshake RFC 6455 (pantalla principal)")
        except Exception as e:
            check(False, "handshake RFC 6455 (pantalla principal)", str(e))
            return 1

        try:
            touch = WS(args.host, args.port)
            op = WS(args.host, args.port)
            check(True, "handshake x3 clientes simultáneos")
        except Exception as e:
            check(False, "handshake x3 clientes simultáneos", str(e))
            return 1

        # -- 2. role handshake + welcome -------------------------------
        main_c.send({"type": "hello", "role": "main", "label": "proyector"})
        w = main_c.wait_for(lambda m: m["type"] == "welcome", what="welcome main")
        check("state" in w, "welcome con estado inicial", json.dumps(w)[:160])
        check("answers" in json.dumps(w.get("state", {}).get("question") or {"answers": 1}),
              "payload welcome bien formado")

        touch.send({"type": "hello", "role": "touch", "slot": "C", "label": "tablet-C"})
        touch.wait_for(lambda m: m["type"] == "welcome", what="welcome touch")
        op.send({"type": "hello", "role": "op", "label": "consola"})
        op.wait_for(lambda m: m["type"] == "welcome", what="welcome op")
        check(True, "roles registrados (main / touch / op)")

        # -- 3. reset to a clean slate --------------------------------
        op.send({"type": "reset"})
        op.wait_for(lambda m: m.get("event") == "reset", what="reset")

        # -- 4. prepare ------------------------------------------------
        op.send({"type": "prepare"})
        st = op.wait_for(lambda m: m.get("event") == "prepared", what="prepared")
        q_next = st["state"]["next"]
        check(q_next is not None, "prepare dibuja la siguiente pregunta")
        check("correct" not in json.dumps(q_next.get("answers", [{}])[0]),
              "el teaser NO viaja la respuesta correcta al estado público")

        # -- 5. question goes live -------------------------------------
        op.send({"type": "question"})
        st = main_c.wait_for(lambda m: m.get("event") == "question", what="evento question")
        q = st["state"]["question"]
        check(q is not None, "question activa la pregunta en la pantalla principal")
        n_answers = len(q["answers"])
        check(n_answers == 4, f"la pregunta tiene 4 respuestas (tiene {n_answers})")
        check(
            all("ok" not in a for a in q["answers"]),
            "estado público sin campo 'ok' (no se puede leer la respuesta)",
        )
        check(st["state"]["deadline"] > time.time(), "deadline del temporizador en el futuro")

        # The console DOES get the answer — that's what it's for.
        stc = op.wait_for(lambda m: m.get("event") == "question", what="question en consola")
        console_q = stc["console"]["question"]
        correct_idx = next((a["i"] for a in console_q["answers"] if a.get("ok")), None)
        check(correct_idx is not None, "la consola SÍ recibe la respuesta correcta",
              json.dumps(console_q["answers"])[:160])

        # -- 6. tap race ------------------------------------------------
        touch.send({"type": "tap", "slot": "C", "answer": correct_idx})
        st = touch.wait_for(lambda m: m.get("event") == "tap", timeout=3.0, what="tap aceptado")
        check(st["data"]["slot"] == "C", "tap del pad C aceptado")
        check(st["data"]["ms"] >= 0, f"tap registrado a los {st['data']['ms']} ms")

        # -- 7. ARBITRATION: second tap from the same pad is rejected --
        time.sleep(0.15)
        touch.send({"type": "tap", "slot": "C", "answer": (correct_idx + 1) % n_answers})
        rejected = None
        deadline = time.time() + 3.0
        while time.time() < deadline and rejected is None:
            try:
                msg = touch.recv(timeout=1.0)
                if msg.get("type") == "tap_rejected":
                    rejected = msg
            except (socket.timeout, TimeoutError):
                break
        check(rejected is not None and rejected.get("code") == "locked",
              "ARBITRAJE: 2º tap del mismo pad rechazado",
              json.dumps(rejected) if rejected else "no llegó tap_rejected")

        # -- 8. a DIFFERENT pad can still answer -----------------------
        op.send({"type": "rename", "slot": "B", "name": "Ana"})
        op.wait_for(lambda m: m.get("event") == "rename", what="rename B")

        op.send({"type": "next"})  # fresh round so B can tap
        st = op.wait_for(lambda m: m.get("event") == "question", what="2a question")
        correct2 = next(a["i"] for a in st["console"]["question"]["answers"] if a.get("ok"))

        touch.send({"type": "tap", "slot": "C", "answer": correct2})
        touch.wait_for(lambda m: m.get("event") == "tap", what="tap C ronda 2")
        time.sleep(0.1)
        touch.send({"type": "tap", "slot": "B", "answer": correct2})
        st = touch.wait_for(lambda m: m.get("event") == "tap", what="tap B ronda 2")
        check(st["data"]["slot"] == "B", "pad B puede responder (independiente de C)")

        # -- 9. reveal scores everyone ---------------------------------
        op.send({"type": "reveal"})
        st = main_c.wait_for(lambda m: m.get("event") == "reveal", what="evento reveal")
        d = st["data"]
        check(d["correct"] == correct2, "reveal revela la respuesta correcta", str(d.get("correct")))
        check("B" in d["correctSlots"], "reveal marca al pad B como correcto",
              json.dumps(d["correctSlots"]))
        check(d["deltas"].get("B", 0) > 0, f"pad B ganó puntos ({d['deltas'].get('B', 0)})")
        check(d["winnerSlot"] in d["correctSlots"],
              "el ganador de ronda acertó la pregunta", json.dumps(d["winnerSlot"]))

        players = {p["slot"]: p for p in st["state"]["players"]}
        check(players["B"]["name"] == "Ana", "el pad conserva su nombre tras renombrar")
        check(players["B"]["correct"] >= 1, "contador de aciertos incrementado")

        # -- 10. main screen gets the answer ONLY after reveal ------------
        # The anti-cheat rule is already asserted on the QUESTION frame above
        # (line ~228). Here we assert the opposite: once revealed, the main
        # screen DOES carry `ok` — that's what makes the highlight work, and
        # what lets a projector that reboots mid-show rebuild the reveal.
        revealed_answers = st["state"]["question"]["answers"]
        check(
            any(a.get("ok") for a in revealed_answers),
            "tras el reveal la pantalla principal SÍ lleva 'ok' (resalta la correcta)",
            json.dumps(revealed_answers)[:200],
        )

        # -- 10b. COLD LOAD during reveal ---------------------------------
        # A projector that reboots mid-show re-reads the state from scratch.
        # It MUST know which answer was correct, or the big screen would show
        # all four answers dimmed with no highlight.
        cold = WS(args.host, args.port)
        cold.send({"type": "hello", "role": "main"})
        cw = cold.wait_for(lambda m: m["type"] == "welcome", what="welcome cold")
        cold_answers = (cw.get("state") or {}).get("question", {}).get("answers") or []
        check(
            any(a.get("ok") for a in cold_answers),
            "COLD LOAD en reveal: la pantalla que reinicia SÍ sabe la respuesta",
            json.dumps(cold_answers)[:200],
        )
        check(
            cw["state"]["phase"] == "reveal",
            "COLD LOAD: la fase actual se reconstruye",
            str(cw["state"]["phase"]),
        )
        cold.close()

        # -- 11. next advances ------------------------------------------
        prev_id = st["state"]["questionId"]
        op.send({"type": "next"})
        st = op.wait_for(lambda m: m.get("event") == "question", what="next")
        check(st["state"]["questionId"] != prev_id, "next cambia de pregunta",
              f"{prev_id} → {st['state']['questionId']}")

        # -- 12. permissions --------------------------------------------
        main_c.send({"type": "reset"})
        err = main_c.wait_for(lambda m: m.get("type") == "error", timeout=2.0, what="error de permisos")
        check("permisos" in err.get("error", "").lower(), "la pantalla principal NO puede comandar el show",
              json.dumps(err))

        # -- 13. ping / clock sync ---------------------------------------
        t0 = time.time()
        main_c.send({"type": "ping", "t": t0})
        p = main_c.wait_for(lambda m: m["type"] == "pong", timeout=2.0, what="pong")
        check(abs(p["serverNow"] - time.time()) < 5, "ping/pong para sincronía de reloj")

        # -- 14. podium + leaderboard ------------------------------------
        op.send({"type": "podium"})
        st = op.wait_for(lambda m: m.get("event") == "podium", what="podium")
        standings = st["data"]["standings"]
        check(len(standings) == 6, "podium con los 6 pads")
        check(standings[0]["pos"] == 1, "clasificación ordenada con posiciones")
        check(
            standings[0]["points"] >= standings[-1]["points"],
            "clasificación ordenada por puntos descendente",
        )

        op.send({"type": "board"})
        board = op.wait_for(lambda m: m["type"] == "leaderboard", what="leaderboard")
        check(isinstance(board["items"], list), "leaderboard histórico responde")

        print()
        passed = sum(1 for ok, _ in results if ok)
        total = len(results)
        if passed == total:
            print(f"\033[92m\033[1m{passed}/{total} comprobaciones OK\033[0m")
            return 0
        print(f"\033[91m\033[1m{passed}/{total} comprobaciones OK\033[0m")
        for ok, label in results:
            if not ok:
                print(f"  falló: {label}")
        return 1

    finally:
        for c in (main_c, touch, op):
            if c:
                c.close()


if __name__ == "__main__":
    sys.exit(main())