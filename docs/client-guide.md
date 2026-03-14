# Client Integration Guide

How to connect to **lphenom/realtime** from frontend apps.

Supported modes:
- **Long-Polling** — works everywhere, shared hosting, no WebSocket needed
- **WebSocket** — real-time push, requires WS-capable server

Both modes use the same message format. You can switch between them by swapping a single client class.

---

## Message format

Every message from the server looks like:

```json
{
  "id":      42,
  "topic":   "chat",
  "payload": "{\"text\":\"Hello!\",\"user\":\"alice\"}",
  "ts":      "2026-03-14 12:00:01"
}
```

`payload` is a JSON string — parse it with `JSON.parse(msg.payload)`.

For WebSocket events the envelope is:

```json
{
  "event":   "message",
  "topic":   "chat",
  "payload": "{\"text\":\"Hello!\",\"user\":\"alice\"}"
}
```

---

## Pure JavaScript

### Long-Polling client

```js
// realtime-poll.js
class RealtimePollClient {
  /**
   * @param {string} baseUrl  e.g. "https://example.com"
   * @param {string} topic    e.g. "chat"
   * @param {function} onMessage  called with parsed payload object per message
   * @param {object}  [options]
   * @param {number}  [options.interval=3000]   poll interval ms
   * @param {number}  [options.limit=50]        messages per request
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
            // invalid payload JSON — skip
          }
        }
        if (data.last_id > this.lastId) {
          this.lastId = data.last_id;
        }
      }
    } catch (err) {
      console.warn('[RealtimePoll] fetch error:', err);
    }

    this._timer = setTimeout(() => this._poll(), this.interval);
  }
}

// ── Usage ────────────────────────────────────────────────────────────
const client = new RealtimePollClient(
  'https://example.com',
  'chat',
  ({ payload }) => {
    console.log('New message:', payload.text, 'from', payload.user);
    appendMessage(payload);
  },
  { interval: 2000 }
);

client.start();

// Stop polling when leaving the page
window.addEventListener('beforeunload', () => client.stop());
```

### WebSocket client

```js
// realtime-ws.js
class RealtimeWsClient {
  /**
   * @param {string}   wsUrl      e.g. "wss://example.com/realtime/ws"
   * @param {string}   topic      e.g. "chat"
   * @param {function} onMessage  called with parsed payload per message
   * @param {object}   [options]
   * @param {number}   [options.reconnectDelay=3000]
   * @param {number}   [options.since=0]  replay history since this id
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
   * Send a message TO the server (if your server supports receive direction).
   * Most realtime servers are receive-only on WS; publishing goes via HTTP POST.
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
      console.log('[RealtimeWS] connected');
      // Subscribe to topic and request history replay since lastId
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
          console.log('[RealtimeWS] subscribed to', envelope.topic);
          return;
        }

        if (envelope.event === 'message') {
          const payload = JSON.parse(envelope.payload);
          if (envelope.id > this.lastId) this.lastId = envelope.id;
          this.onMessage({ topic: envelope.topic, payload });
        }
      } catch (err) {
        console.warn('[RealtimeWS] parse error:', err);
      }
    });

    ws.addEventListener('close', () => {
      if (!this._stopped) {
        console.log(`[RealtimeWS] disconnected, reconnecting in ${this.reconnectDelay}ms`);
        setTimeout(() => this._open(), this.reconnectDelay);
      }
    });

    ws.addEventListener('error', (err) => {
      console.error('[RealtimeWS] error:', err);
    });
  }
}

// ── Usage ────────────────────────────────────────────────────────────
const ws = new RealtimeWsClient(
  'wss://example.com/realtime/ws',
  'chat',
  ({ payload }) => {
    console.log('New message:', payload.text, 'from', payload.user);
    appendMessage(payload);
  },
  { since: 0, reconnectDelay: 3000 }
);

ws.connect();
```

### Publishing a message (HTTP POST)

Sending messages is done via regular HTTP POST — not WebSocket:

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

// Usage:
await publishMessage('chat', { text: 'Hello!', user: 'alice' });
```

On the PHP side, your controller calls `$bus->publish()`.

---

## Vue 3 (Composition API)

### Composable for Long-Polling

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
    <div class="status">{{ connected ? '🟢 Connected' : '🔴 Reconnecting…' }}</div>
    <ul class="messages">
      <li v-for="msg in messages" :key="msg.id">
        <strong>{{ msg.user ?? 'Anonymous' }}</strong>: {{ msg.text }}
        <small>{{ msg.ts }}</small>
      </li>
    </ul>
    <form @submit.prevent="sendMessage">
      <input v-model="newText" placeholder="Type a message…" />
      <button type="submit">Send</button>
    </form>
  </div>
</template>
```

### Composable for WebSocket

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
      } catch { /* ignore */ }
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
    <span>{{ connected ? '🟢 Live' : '🔴 Reconnecting…' }}</span>
    <ul>
      <li v-for="msg in messages" :key="msg.id">
        <b>{{ msg.user }}</b>: {{ msg.text }}
      </li>
    </ul>
    <input v-model="newText" @keyup.enter="sendMessage" placeholder="Message…" />
  </div>
</template>
```

---

## React (hooks)

### Hook for Long-Polling

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
        const data = await res.json() as { messages: Array<{id: number; topic: string; ts: string; payload: string}>; last_id: number }
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
      <p>{connected ? '🟢 Connected' : '🔴 Reconnecting…'}</p>
      <ul>
        {messages.map(m => (
          <li key={m.id}>
            <strong>{m.payload.user}</strong>: {m.payload.text}
            <small> {m.ts}</small>
          </li>
        ))}
      </ul>
      <form onSubmit={send}>
        <input value={text} onChange={e => setText(e.target.value)} placeholder="Message…" />
        <button type="submit">Send</button>
      </form>
    </div>
  )
}
```

### Hook for WebSocket

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
        } catch { /* skip malformed */ }
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
      <span>{connected ? '🟢 Live' : '🔴 Reconnecting…'}</span>
      <ul>
        {messages.map(m => (
          <li key={m.id}>
            <b>{m.payload.user}</b>: {m.payload.text}
          </li>
        ))}
      </ul>
      <form onSubmit={send}>
        <input value={text} onChange={e => setText(e.target.value)} />
        <button type="submit">Send</button>
      </form>
    </div>
  )
}
```

---

## Switching between Long-Polling and WebSocket

The only difference between modes is **which client class you use**. Your component code stays the same. Use an environment variable to switch:

```js
// poll.js — Universal adapter (pure JS)
function createRealtimeClient(topic, onMessage) {
  const wsUrl = window.REALTIME_WS_URL  // undefined on shared hosting

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
// Vue: set in env
// VITE_REALTIME_MODE=ws     (or poll)
// VITE_REALTIME_WS_URL=wss://example.com/realtime/ws
// VITE_REALTIME_API_URL=https://example.com
```

---

## Demo: Complete HTML page (no build tool)

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>lphenom/realtime demo</title>
  <style>
    body    { font-family: sans-serif; max-width: 600px; margin: 40px auto; }
    #msgs   { border: 1px solid #ccc; min-height: 200px; padding: 10px; overflow-y: auto; }
    #status { font-size: 12px; color: #888; margin-bottom: 8px; }
    form    { display: flex; gap: 8px; margin-top: 8px; }
    input   { flex: 1; padding: 6px; }
  </style>
</head>
<body>

<h2>Chat (topic: <em>demo</em>)</h2>
<div id="status">Connecting…</div>
<div id="msgs"></div>
<form id="form">
  <input id="text" placeholder="Type a message…" autocomplete="off" />
  <button type="submit">Send</button>
</form>

<script>
// ── Config ──────────────────────────────────────────────────────────
const API_URL   = 'https://example.com';          // change me
const WS_URL    = 'wss://example.com/realtime/ws'; // comment out to use long-polling
const TOPIC     = 'demo';
const USE_WS    = typeof WS_URL !== 'undefined';

// ── UI helpers ──────────────────────────────────────────────────────
function setStatus(text) {
  document.getElementById('status').textContent = text;
}

function appendMessage(payload) {
  const li = document.createElement('div');
  li.textContent = `${payload.user ?? 'anon'}: ${payload.text}`;
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
    setStatus('🟢 Connected (polling)');
    for (const msg of (data.messages ?? [])) {
      appendMessage(JSON.parse(msg.payload));
    }
    if (data.last_id > lastId) lastId = data.last_id;
  } catch {
    setStatus('🔴 Reconnecting…');
  }
  pollTimer = setTimeout(poll, 2000);
}

// ── WebSocket client ─────────────────────────────────────────────────
function connectWs() {
  const ws = new WebSocket(WS_URL);

  ws.addEventListener('open', () => {
    setStatus('🟢 Connected (WebSocket)');
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
    setStatus('🔴 Disconnected, reconnecting…');
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

## Summary: modes comparison

| Feature | Long-Polling | WebSocket |
|---------|-------------|-----------|
| Latency | 0 – interval ms | < 50 ms |
| Infrastructure | PHP-FPM only | WS process + Redis |
| Works on shared hosting | ✅ | ❌ |
| Scales with load balancer | ✅ (stateless) | ⚠️ sticky sessions or Redis fanout |
| History replay on reconnect | ✅ built-in | ✅ via `since` parameter |
| PHP code changes needed | none | none |

> See [`server-setup.md`](./server-setup.md) for server infrastructure details.

