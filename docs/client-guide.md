# Руководство по клиентской интеграции

Инструкция по подключению **lphenom/realtime** во фронтенд-приложениях.

Поддерживаемые режимы:
- **Long-Polling** — работает везде, подходит для shared hosting, WebSocket не нужен
- **WebSocket** — доставка в реальном времени, требует WS-совместимого сервера

Оба режима используют одинаковый формат сообщений. Переключение между режимами — замена одного клиентского класса.

---

## Формат сообщения

Каждое сообщение от сервера выглядит так:

```json
{
  "id":      42,
  "topic":   "chat",
  "payload": "{\"text\":\"Привет!\",\"user\":\"alice\"}",
  "ts":      "2026-03-14 12:00:01"
}
```

`payload` — JSON-строка, её нужно распарсить через `JSON.parse(msg.payload)`.

Для WebSocket-событий конверт выглядит иначе:

```json
{
  "event":   "message",
  "topic":   "chat",
  "payload": "{\"text\":\"Привет!\",\"user\":\"alice\"}"
}
```

---

## Pure JavaScript

### Long-Polling клиент

```js
// realtime-poll.js
class RealtimePollClient {
  /**
   * @param {string} baseUrl  например "https://example.com"
   * @param {string} topic    например "chat"
   * @param {function} onMessage  вызывается с разобранным объектом payload
   * @param {object}  [options]
   * @param {number}  [options.interval=3000]   интервал опроса в мс
   * @param {number}  [options.limit=50]        сообщений за запрос
   */
  constructor(baseUrl, topic, onMessage, options = {}) {
    this.baseUrl   = baseUrl;
    this.topic     = topic;
    this.onMessage = onMessage;
    this.interval  = options.interval ?? 3000;
    this.limit     = options.limit    ?? 50;
    this.lastId    = 0;
    this._timer    = null;
    this._running  = false;
  }

  start() {
    if (this._running) return;
    this._running = true;
    this._poll();
  }

  stop() {
    this._running = false;
    clearTimeout(this._timer);
  }

  async _poll() {
    if (!this._running) return;

    try {
      const url = `${this.baseUrl}/realtime/poll`
        + `?topic=${encodeURIComponent(this.topic)}`
        + `&since=${this.lastId}`
        + `&limit=${this.limit}`;

      const res  = await fetch(url, { credentials: 'include' });
      const data = await res.json();

      if (Array.isArray(data.messages)) {
        for (const msg of data.messages) {
          try {
            const payload = JSON.parse(msg.payload);
            this.onMessage({ id: msg.id, topic: msg.topic, payload, ts: msg.ts });
          } catch (_) {
            // некорректный JSON payload — пропускаем
          }
        }
        if (data.last_id > this.lastId) {
          this.lastId = data.last_id;
        }
      }
    } catch (err) {
      console.warn('[RealtimePoll] ошибка fetch:', err);
    }

    this._timer = setTimeout(() => this._poll(), this.interval);
  }
}

// ── Использование ────────────────────────────────────────────────────
const client = new RealtimePollClient(
  'https://example.com',
  'chat',
  ({ payload }) => {
    console.log('Новое сообщение:', payload.text, 'от', payload.user);
    appendMessage(payload);
  },
  { interval: 2000 }
);

client.start();

// Остановить опрос при уходе со страницы
window.addEventListener('beforeunload', () => client.stop());
```

### WebSocket клиент

```js
// realtime-ws.js
class RealtimeWsClient {
  /**
   * @param {string}   wsUrl      например "wss://example.com/realtime/ws"
   * @param {string}   topic      например "chat"
   * @param {function} onMessage  вызывается с разобранным payload
   * @param {object}   [options]
   * @param {number}   [options.reconnectDelay=3000]
   * @param {number}   [options.since=0]  воспроизвести историю начиная с этого id
   */
  constructor(wsUrl, topic, onMessage, options = {}) {
    this.wsUrl          = wsUrl;
    this.topic          = topic;
    this.onMessage      = onMessage;
    this.reconnectDelay = options.reconnectDelay ?? 3000;
    this.lastId         = options.since ?? 0;
    this._ws            = null;
    this._stopped       = false;
  }

  connect() {
    this._stopped = false;
    this._open();
  }

  disconnect() {
    this._stopped = true;
    if (this._ws) this._ws.close();
  }

  /**
   * Отправить сообщение НА сервер (если сервер поддерживает входящие).
   * Большинство realtime-серверов принимают только подписки;
   * публикация идёт через HTTP POST.
   */
  sendRaw(data) {
    if (this._ws && this._ws.readyState === WebSocket.OPEN) {
      this._ws.send(JSON.stringify(data));
    }
  }

  _open() {
    const ws = new WebSocket(this.wsUrl);
    this._ws  = ws;

    ws.addEventListener('open', () => {
      console.log('[RealtimeWS] подключено');
      ws.send(JSON.stringify({
        action: 'subscribe',
        topic:  this.topic,
        since:  this.lastId,
      }));
    });

    ws.addEventListener('message', (event) => {
      try {
        const envelope = JSON.parse(event.data);

        if (envelope.event === 'subscribed') {
          console.log('[RealtimeWS] подписан на', envelope.topic);
          return;
        }

        if (envelope.event === 'message') {
          const payload = JSON.parse(envelope.payload);
          if (envelope.id > this.lastId) this.lastId = envelope.id;
          this.onMessage({ topic: envelope.topic, payload });
        }
      } catch (err) {
        console.warn('[RealtimeWS] ошибка парсинга:', err);
      }
    });

    ws.addEventListener('close', () => {
      if (!this._stopped) {
        console.log(`[RealtimeWS] отключено, переподключение через ${this.reconnectDelay}мс`);
        setTimeout(() => this._open(), this.reconnectDelay);
      }
    });

    ws.addEventListener('error', (err) => {
      console.error('[RealtimeWS] ошибка:', err);
    });
  }
}

// ── Использование ────────────────────────────────────────────────────
const ws = new RealtimeWsClient(
  'wss://example.com/realtime/ws',
  'chat',
  ({ payload }) => {
    console.log('Новое сообщение:', payload.text, 'от', payload.user);
    appendMessage(payload);
  },
  { since: 0, reconnectDelay: 3000 }
);

ws.connect();
```

### Публикация сообщения (HTTP POST)

Отправка сообщений выполняется через обычный HTTP POST — не WebSocket:

```js
async function publishMessage(topic, payload) {
  const res = await fetch('/api/messages', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ topic, payload }),
  });
  return res.json();
}

// Использование:
await publishMessage('chat', { text: 'Привет!', user: 'alice' });
```

На стороне PHP контроллер вызывает `$bus->publish()`.

---

## Vue 3 (Composition API)

### Composable для Long-Polling

```js
// composables/useRealtimePoll.js
import { ref, onMounted, onUnmounted } from 'vue'

export function useRealtimePoll(topic, options = {}) {
  const messages  = ref([])
  const connected = ref(false)
  let lastId      = 0
  let timer       = null
  const interval  = options.interval ?? 3000
  const baseUrl   = options.baseUrl  ?? ''

  async function poll() {
    try {
      const url = `${baseUrl}/realtime/poll`
        + `?topic=${encodeURIComponent(topic)}`
        + `&since=${lastId}&limit=50`

      const res  = await fetch(url, { credentials: 'include' })
      const data = await res.json()
      connected.value = true

      if (Array.isArray(data.messages)) {
        for (const msg of data.messages) {
          const payload = JSON.parse(msg.payload)
          messages.value.push({ id: msg.id, ts: msg.ts, ...payload })
        }
        if (data.last_id > lastId) lastId = data.last_id
      }
    } catch {
      connected.value = false
    } finally {
      timer = setTimeout(poll, interval)
    }
  }

  onMounted(() => poll())
  onUnmounted(() => clearTimeout(timer))

  return { messages, connected }
}
```

```vue
<!-- ChatRoom.vue — Long-Polling -->
<script setup>
import { ref } from 'vue'
import { useRealtimePoll } from '@/composables/useRealtimePoll'

const props = defineProps({ room: String })

const { messages, connected } = useRealtimePoll(props.room, {
  interval: 2000,
  baseUrl: import.meta.env.VITE_API_URL,
})

const newText = ref('')

async function sendMessage() {
  if (!newText.value.trim()) return
  await fetch('/api/messages', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ topic: props.room, payload: { text: newText.value } }),
  })
  newText.value = ''
}
</script>

<template>
  <div class="chat">
    <div class="status">{{ connected ? '🟢 Подключено' : '🔴 Переподключение…' }}</div>
    <ul class="messages">
      <li v-for="msg in messages" :key="msg.id">
        <strong>{{ msg.user ?? 'Аноним' }}</strong>: {{ msg.text }}
        <small>{{ msg.ts }}</small>
      </li>
    </ul>
    <form @submit.prevent="sendMessage">
      <input v-model="newText" placeholder="Введите сообщение…" />
      <button type="submit">Отправить</button>
    </form>
  </div>
</template>
```

### Composable для WebSocket

```js
// composables/useRealtimeWs.js
import { ref, onMounted, onUnmounted } from 'vue'

export function useRealtimeWs(topic, options = {}) {
  const messages  = ref([])
  const connected = ref(false)
  const wsUrl     = options.wsUrl ?? `wss://${location.host}/realtime/ws`
  let ws          = null
  let lastId      = options.since ?? 0
  let stopped     = false

  function open() {
    ws = new WebSocket(wsUrl)

    ws.addEventListener('open', () => {
      connected.value = true
      ws.send(JSON.stringify({ action: 'subscribe', topic, since: lastId }))
    })

    ws.addEventListener('message', (event) => {
      try {
        const envelope = JSON.parse(event.data)
        if (envelope.event !== 'message') return
        const payload = JSON.parse(envelope.payload)
        if (envelope.id > lastId) lastId = envelope.id
        messages.value.push({ id: envelope.id, ...payload })
      } catch { /* игнорируем */ }
    })

    ws.addEventListener('close', () => {
      connected.value = false
      if (!stopped) setTimeout(open, 3000)
    })

    ws.addEventListener('error', () => ws.close())
  }

  onMounted(() => open())
  onUnmounted(() => {
    stopped = true
    ws?.close()
  })

  return { messages, connected }
}
```

```vue
<!-- ChatRoom.vue — WebSocket -->
<script setup>
import { ref } from 'vue'
import { useRealtimeWs } from '@/composables/useRealtimeWs'

const props = defineProps({ room: String })

const { messages, connected } = useRealtimeWs(props.room, {
  wsUrl: `wss://${location.host}/realtime/ws`,
  since: 0,
})

const newText = ref('')

async function sendMessage() {
  if (!newText.value.trim()) return
  await fetch('/api/messages', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ topic: props.room, payload: { text: newText.value } }),
  })
  newText.value = ''
}
</script>

<template>
  <div class="chat">
    <span>{{ connected ? '🟢 Live' : '🔴 Переподключение…' }}</span>
    <ul>
      <li v-for="msg in messages" :key="msg.id">
        <b>{{ msg.user }}</b>: {{ msg.text }}
      </li>
    </ul>
    <input v-model="newText" @keyup.enter="sendMessage" placeholder="Сообщение…" />
  </div>
</template>
```

---

## React (хуки)

### Хук для Long-Polling

```tsx
// hooks/useRealtimePoll.ts
import { useEffect, useRef, useState } from 'react'

interface RealtimeMessage<T = Record<string, unknown>> {
  id:      number
  topic:   string
  ts:      string
  payload: T
}

interface Options {
  interval?: number
  baseUrl?:  string
  since?:    number
}

export function useRealtimePoll<T = Record<string, unknown>>(
  topic:   string,
  options: Options = {}
) {
  const [messages,  setMessages]  = useState<RealtimeMessage<T>[]>([])
  const [connected, setConnected] = useState(false)
  const lastIdRef  = useRef(options.since ?? 0)
  const timerRef   = useRef<ReturnType<typeof setTimeout>>()
  const interval   = options.interval ?? 3000
  const baseUrl    = options.baseUrl  ?? ''

  useEffect(() => {
    let cancelled = false

    async function poll() {
      if (cancelled) return
      try {
        const url = `${baseUrl}/realtime/poll`
          + `?topic=${encodeURIComponent(topic)}`
          + `&since=${lastIdRef.current}&limit=50`

        const res  = await fetch(url, { credentials: 'include' })
        const data = await res.json() as {
          messages: Array<{id: number; topic: string; ts: string; payload: string}>;
          last_id: number
        }
        if (cancelled) return

        setConnected(true)

        if (Array.isArray(data.messages) && data.messages.length > 0) {
          const parsed = data.messages.map(msg => ({
            id:      msg.id,
            topic:   msg.topic,
            ts:      msg.ts,
            payload: JSON.parse(msg.payload) as T,
          }))
          setMessages(prev => [...prev, ...parsed])
          lastIdRef.current = data.last_id
        }
      } catch {
        if (!cancelled) setConnected(false)
      } finally {
        if (!cancelled) timerRef.current = setTimeout(poll, interval)
      }
    }

    poll()
    return () => {
      cancelled = true
      clearTimeout(timerRef.current)
    }
  }, [topic, baseUrl, interval])

  return { messages, connected }
}
```

```tsx
// ChatRoom.tsx — Long-Polling
import React, { useState } from 'react'
import { useRealtimePoll } from '../hooks/useRealtimePoll'

interface ChatPayload {
  text: string
  user: string
}

export function ChatRoom({ room }: { room: string }) {
  const { messages, connected } = useRealtimePoll<ChatPayload>(room, {
    interval: 2000,
    baseUrl:  import.meta.env.VITE_API_URL,
  })
  const [text, setText] = useState('')

  async function send(e: React.FormEvent) {
    e.preventDefault()
    if (!text.trim()) return
    await fetch('/api/messages', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ topic: room, payload: { text, user: 'me' } }),
    })
    setText('')
  }

  return (
    <div>
      <p>{connected ? '🟢 Подключено' : '🔴 Переподключение…'}</p>
      <ul>
        {messages.map(m => (
          <li key={m.id}>
            <strong>{m.payload.user}</strong>: {m.payload.text}
            <small> {m.ts}</small>
          </li>
        ))}
      </ul>
      <form onSubmit={send}>
        <input value={text} onChange={e => setText(e.target.value)} placeholder="Сообщение…" />
        <button type="submit">Отправить</button>
      </form>
    </div>
  )
}
```

### Хук для WebSocket

```tsx
// hooks/useRealtimeWs.ts
import { useEffect, useRef, useState } from 'react'

interface RealtimeMessage<T = Record<string, unknown>> {
  id:      number
  topic:   string
  payload: T
}

interface Options {
  wsUrl?:          string
  since?:          number
  reconnectDelay?: number
}

export function useRealtimeWs<T = Record<string, unknown>>(
  topic:   string,
  options: Options = {}
) {
  const [messages,  setMessages]  = useState<RealtimeMessage<T>[]>([])
  const [connected, setConnected] = useState(false)
  const wsRef      = useRef<WebSocket | null>(null)
  const lastIdRef  = useRef(options.since ?? 0)
  const stoppedRef = useRef(false)
  const wsUrl           = options.wsUrl          ?? `wss://${window.location.host}/realtime/ws`
  const reconnectDelay  = options.reconnectDelay ?? 3000

  useEffect(() => {
    stoppedRef.current = false

    function open() {
      if (stoppedRef.current) return
      const ws = new WebSocket(wsUrl)
      wsRef.current = ws

      ws.addEventListener('open', () => {
        setConnected(true)
        ws.send(JSON.stringify({ action: 'subscribe', topic, since: lastIdRef.current }))
      })

      ws.addEventListener('message', (event: MessageEvent) => {
        try {
          const envelope = JSON.parse(event.data as string)
          if (envelope.event !== 'message') return
          const payload = JSON.parse(envelope.payload as string) as T
          if (envelope.id > lastIdRef.current) lastIdRef.current = envelope.id
          setMessages(prev => [...prev, { id: envelope.id, topic, payload }])
        } catch { /* пропускаем некорректные */ }
      })

      ws.addEventListener('close', () => {
        setConnected(false)
        if (!stoppedRef.current) setTimeout(open, reconnectDelay)
      })

      ws.addEventListener('error', () => ws.close())
    }

    open()
    return () => {
      stoppedRef.current = true
      wsRef.current?.close()
    }
  }, [topic, wsUrl, reconnectDelay])

  return { messages, connected }
}
```

```tsx
// ChatRoom.tsx — WebSocket
import React, { useState } from 'react'
import { useRealtimeWs } from '../hooks/useRealtimeWs'

interface ChatPayload {
  text: string
  user: string
}

export function ChatRoom({ room }: { room: string }) {
  const { messages, connected } = useRealtimeWs<ChatPayload>(room, {
    wsUrl: `wss://${window.location.host}/realtime/ws`,
    since: 0,
  })
  const [text, setText] = useState('')

  async function send(e: React.FormEvent) {
    e.preventDefault()
    if (!text.trim()) return
    await fetch('/api/messages', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ topic: room, payload: { text, user: 'me' } }),
    })
    setText('')
  }

  return (
    <div>
      <span>{connected ? '🟢 Live' : '🔴 Переподключение…'}</span>
      <ul>
        {messages.map(m => (
          <li key={m.id}>
            <b>{m.payload.user}</b>: {m.payload.text}
          </li>
        ))}
      </ul>
      <form onSubmit={send}>
        <input value={text} onChange={e => setText(e.target.value)} />
        <button type="submit">Отправить</button>
      </form>
    </div>
  )
}
```

---

## Переключение между Long-Polling и WebSocket

Единственное отличие — **какой клиентский класс используется**. Код компонента не меняется. Используйте переменную окружения для переключения:

```js
// Универсальный адаптер (pure JS)
function createRealtimeClient(topic, onMessage) {
  const wsUrl = window.REALTIME_WS_URL  // undefined на shared hosting

  if (wsUrl) {
    const client = new RealtimeWsClient(wsUrl, topic, onMessage)
    client.connect()
    return client
  }

  const client = new RealtimePollClient(window.REALTIME_API_URL ?? '', topic, onMessage)
  client.start()
  return client
}
```

```js
// Vue: задаётся в .env
// VITE_REALTIME_MODE=ws     (или poll)
// VITE_REALTIME_WS_URL=wss://example.com/realtime/ws
// VITE_REALTIME_API_URL=https://example.com
```

---

## Демо: полная HTML-страница (без системы сборки)

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>lphenom/realtime демо</title>
  <style>
    body    { font-family: sans-serif; max-width: 600px; margin: 40px auto; }
    #msgs   { border: 1px solid #ccc; min-height: 200px; padding: 10px; overflow-y: auto; }
    #status { font-size: 12px; color: #888; margin-bottom: 8px; }
    form    { display: flex; gap: 8px; margin-top: 8px; }
    input   { flex: 1; padding: 6px; }
  </style>
</head>
<body>

<h2>Чат (топик: <em>demo</em>)</h2>
<div id="status">Подключение…</div>
<div id="msgs"></div>
<form id="form">
  <input id="text" placeholder="Введите сообщение…" autocomplete="off" />
  <button type="submit">Отправить</button>
</form>

<script>
// ── Config ──────────────────────────────────────────────────────────
const API_URL   = 'https://example.com';          // изменить меня
const WS_URL    = 'wss://example.com/realtime/ws'; // закомментировать для long-polling
const TOPIC     = 'demo';
const USE_WS    = typeof WS_URL !== 'undefined';

// ── UI helpers ──────────────────────────────────────────────────────
function setStatus(text) {
  document.getElementById('status').textContent = text;
}

function appendMessage(payload) {
  const li = document.createElement('div');
  li.textContent = `${payload.user ?? 'аноним'}: ${payload.text}`;
  document.getElementById('msgs').appendChild(li);
}

// ── Long-Polling client ──────────────────────────────────────────────
let lastId = 0;
let pollTimer = null;

async function poll() {
  try {
    const url = `${API_URL}/realtime/poll?topic=${TOPIC}&since=${lastId}&limit=50`;
    const res  = await fetch(url, { credentials: 'include' });
    const data = await res.json();
    setStatus('🟢 Подключено (polling)');
    for (const msg of (data.messages ?? [])) {
      appendMessage(JSON.parse(msg.payload));
    }
    if (data.last_id > lastId) lastId = data.last_id;
  } catch {
    setStatus('🔴 Переподключение…');
  }
  pollTimer = setTimeout(poll, 2000);
}

// ── WebSocket client ─────────────────────────────────────────────────
function connectWs() {
  const ws = new WebSocket(WS_URL);

  ws.addEventListener('open', () => {
    setStatus('🟢 Подключено (WebSocket)');
    ws.send(JSON.stringify({ action: 'subscribe', topic: TOPIC, since: lastId }));
  });

  ws.addEventListener('message', (event) => {
    try {
      const env = JSON.parse(event.data);
      if (env.event !== 'message') return;
      const payload = JSON.parse(env.payload);
      if (env.id > lastId) lastId = env.id;
      appendMessage(payload);
    } catch (_) {}
  });

  ws.addEventListener('close', () => {
    setStatus('🔴 Отключено, переподключение…');
    setTimeout(connectWs, 3000);
  });

  ws.addEventListener('error', () => ws.close());
}

// ── Start ────────────────────────────────────────────────────────────
if (USE_WS) {
  connectWs();
} else {
  poll();
}

// ── Send ─────────────────────────────────────────────────────────────
document.getElementById('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const input = document.getElementById('text');
  const text  = input.value.trim();
  if (!text) return;
  input.value = '';

  await fetch(`${API_URL}/api/messages`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ topic: TOPIC, payload: { text, user: 'browser' } }),
  });
});
</script>

</body>
</html>
```

---

## Сравнение режимов

| Характеристика | Long-Polling | WebSocket |
|---|---|---|
| Задержка | 0 – интервал мс | < 50 мс |
| Инфраструктура | только PHP-FPM | WS-процесс + Redis |
| Работает на shared hosting | ✅ | ❌ |
| Масштабирование с балансировщиком | ✅ (stateless) | ⚠️ sticky sessions или Redis fanout |
| Воспроизведение истории при переподключении | ✅ встроено | ✅ через параметр `since` |
| Изменения PHP-кода | нет | нет |

> Подробнее об инфраструктуре сервера — в [`server-setup.md`](./server-setup.md).
