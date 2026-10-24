# Banorte-F1 · Sistema de trivia para evento

Trivia de Fórmula 1 para activaciones de marca. **2 pantallas sincronizadas por
WebSocket** en PHP puro — sin Composer, sin npm, sin framework.

- **Pantalla principal** (`public/main.html`) → proyector, con **green screen**
  listo para chroma key.
- **Pantalla secundaria** (`public/console.html`) → consola táctil del
  presentador, con pads que destellan.

---

## Arquitectura

```
┌──────────────────────┐        ┌────────────────────────────┐
│  Pantalla principal  │        │  Consola táctil            │
│  (proyector)         │        │  (tablet del presentador)  │
│  role: "main"        │        │  role: "op"                │
└──────────┬───────────┘        └──────────┬──────────────────┘
           │        WebSocket (wss://)      │
           └──────────────┬─────────────────┘
                          ▼
            ┌─────────────────────────────┐
            │  server/server.php          │
            │  • WsServer  (RFC 6455)     │
            │  • GameEngine (state machine)│
            │  • QuestionBank (SQLite)    │
            └──────────────┬──────────────┘
                           ▼
                   storage/banorte.sqlite
```

### Por qué el WebSocket va en un proceso PHP aparte

`php-fpm` no puede mantener conexiones persistentes. El servidor de WebSocket
corre como proceso supervisado aparte (`php server/server.php`) y Traefik
enruta `/ws` hacia ese puerto, mientras `/` va a nginx + php-fpm.

### Por qué single-process (sin `pcntl_fork`)

En una carrera de taps el **orden de llegada decide el ganador**. Con un solo
proceso, el orden que el motor ve es exactamente el orden del cable — no hay
carrera entre procesos ni estado compartido que corromper.

---

## Reglas del juego

| Regla | Detalle |
|---|---|
| **1 respuesta por pad** | El primer tap de un pad bloquea su respuesta; los siguientes se rechazan (`tap_rejected`). |
| **Ventana de respuesta** | Configurable por pregunta (10–18 s). Al expirar, el servidor revela solo. |
| **Puntos** | `base (1000) + bonus de velocidad (hasta 500) + racha (250 × racha)` |
| **Velocidad** | El bonus de velocidad es proporcional al tiempo restante. |
| **Racha** | Cada acierto consecutivo suma; un fallo la reinicia. |
| **Fases** | `idle → intro → question → reveal → podium` |

### Revisión anti-trampas

La **pantalla principal nunca recibe el campo `ok`** de las respuestas — ni
siquiera durante el reveal. Ese campo sólo viaja en el payload `console`, que
sólo recibe el rol `op`. Un jugador con un móvil y devtools no puede leer la
respuesta correcta antes de tiempo.

---

## Puesta en marcha (local)

```bash
# 1. Servidor WebSocket + juego (puente 8787)
php server/server.php

# 2. API REST (puente 8091)
php -S 127.0.0.1:8091 -t .

# 3. Pantallas estáticas (puente 8092)
python3 -m http.server 8092 --directory public --bind 127.0.0.1
```

Abrir:
- Pantalla principal → `http://127.0.0.1:8092/main.html`
- Consola táctil → `http://127.0.0.1:8092/console.html`

### Configuración por variables de entorno

| Variable | Default | Descripción |
|---|---|---|
| `BF1_HOST` | `0.0.0.0` | Host del WebSocket |
| `BF1_PORT` | `8787` | Puerto del WebSocket |
| `BF1_DB` | `storage/banorte.sqlite` | Ruta de la base SQLite |
| `BF1_HZ` | `20` | Frecuencia del tick (auto-avance) |

---

## Pruebas

```bash
python3 tools/e2e.py --port 8787
```

Cliente WebSocket escrito con **stdlib de Python** (handshake + framing
RFC 6455, sin `pip install`). Verifica 30 comprobaciones:

- handshake × 3 clientes simultáneos
- roles `main` / `touch` / `op`
- el teaser **no** filtra la respuesta correcta
- el estado público **no** lleva el campo `ok` ni en reveal
- **arbitraje**: 2º tap del mismo pad → `tap_rejected`
- pads independientes pueden responder
- scoring, reveal, podio, leaderboard
- la pantalla principal **no puede** comandar el show
- ping/pong para sincronía de reloj

---

## API REST

Base: `/api`

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/questions.php?limit=100&q=` | Listado paginado del banco |
| `GET` | `/api/questions.php?action=categories` | Categorías con conteo |
| `GET` | `/api/questions.php?action=one&id=N` | Una pregunta |
| `POST` | `/api/questions.php` | Crear pregunta |
| `PUT` | `/api/questions.php?id=N` | Editar pregunta |
| `DELETE` | `/api/questions.php?id=N` | Eliminar |
| `POST` | `/api/seed.php` | Re-sembrar banco (idempotente salvo `{"wipe":true}`) |
| `GET` | `/api/state.php?action=leaderboard` | Ganadores históricos |

---

## Banco de preguntas

`server/lib/bank_f1.json` es la fuente de verdad del contenido semilla:
**22 preguntas** en 5 categorías (`pilotos`, `equipos`, `circuitos`, `datos`,
`tecnica`), con `fact` (el dato curioso que se muestra al revelar) y
dificultad 1–3.

Se carga solo en el primer arranque. Después se edita desde la pestaña
**Banco** de la consola táctil, sin redeploy.

---

## Paleta AGUITECH

```
navy #04081c   cyan #00f3ff   cyan-2 #0084ff   gold #ffc400   red #e2231a
```

Tipografías: **Inter** (texto) + **JetBrains Mono** (números/temporizador).

---

## Estructura

```
banorte-f1/
├── server/
│   ├── server.php              # entrypoint WS + routing de verbs
│   └── lib/
│       ├── Frame.php           # codec RFC 6455 (encode/decode)
│       ├── WsServer.php        # event loop con stream_select()
│       ├── WsConnection.php    # conexión + buffer de salida
│       ├── GameState.php       # estado del show + revisión monótona
│       ├── GameEngine.php      # state machine + scoring + arbitraje
│       ├── QuestionBank.php    # SQLite (WAL)
│       └── bank_f1.json        # semilla de 22 preguntas
├── api/                        # API REST (PHP)
│   ├── _helpers.php            # CORS + JSON helpers
│   ├── questions.php
│   ├── state.php
│   └── seed.php
├── public/                     # las 2 pantallas
│   ├── main.html               # pantalla principal (green screen)
│   ├── console.html            # consola táctil
│   └── assets/
│       ├── base.css            # tokens compartidos
│       ├── socket.js           # cliente WS + Countdown
│       ├── main.css / main.js
│       └── console.css / console.js
├── tools/
│   └── e2e.py                  # suite E2E (stdlib)
└── storage/                    # SQLite runtime (gitignored)
```

---

## Notas de despliegue

- **Traefik** enruta `/` → nginx/php-fpm y `/ws` → proceso WebSocket.
- **EasyPanel regenera `main.yaml` cada ~1h**: cualquier router editado a mano
  en el YAML se pierde. Los dominios deben entrar por la UI de EasyPanel.
- El alias `*.985rbl.easypanel.host` tiene **wildcard DNS** ya resuelto
  (→ 31.97.213.7). Los dominios custom (`aguitech.com`, `codimexa.com`)
  apuntan a Hostgator y **nunca llegan a este VPS**.

---

**AGUITECH** — Ingeniería + Diseño + Sistemas
