# Server Setup Guide

This document explains how to deploy **lphenom/realtime** in both modes:

- **Long-Polling mode** — shared hosting, Apache/Nginx + PHP-FPM, no extra processes
- **WebSocket mode** — VPS/cloud, KPHP-compiled binary or PHP ReactPHP server

---

## Architecture overview

```
┌────────────────── Long-Polling (shared hosting) ──────────────────┐
│                                                                     │
│  Client ──── HTTP GET /realtime/poll?topic=chat&since=42 ────────► │
│                             PHP (PollHandler)                       │
│                                    │                                │
│                             MySQL (realtime_events)                 │
│                                    │                                │
│  Client ◄──── {"messages":[...],"last_id":45} ───────────────────  │
└─────────────────────────────────────────────────────────────────────┘

┌────────────────── WebSocket (VPS / KPHP) ──────────────────────────┐
│                                                                     │
│  Publisher ──► WebSocketBus::publish() ──► MySQL + Redis pub/sub   │
│                                                    │                │
│                                          Redis channel             │
│                                         "realtime:chat"            │
│                                                    │                │
│                                         WS Server process          │
│                                      (subscribes to Redis)          │
│                                                    │                │
│  WS Client ◄────────── push message ───────────────┘               │
│  WS Client ◄────────── push message                                │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Mode 1: Long-Polling — shared hosting

### Requirements

- PHP >= 8.1
- MySQL 5.7+ / 8.0
- Apache or Nginx + PHP-FPM
- **No additional processes needed**

### Step 1: Run the migration

```php
<?php
// bootstrap/migrate.php
require __DIR__ . '/../vendor/autoload.php';

use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Migration\CreateRealtimeEventsTable;

$db = new PdoMySqlConnection(
    'mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4',
    'db_user',
    'db_pass'
);

$migration = new CreateRealtimeEventsTable();
$migration->up($db);
echo "Migration OK\n";
```

Run once:

```bash
php bootstrap/migrate.php
```

### Step 2: Register the poll endpoint

```php
<?php
// public/index.php (or your router)
require __DIR__ . '/../vendor/autoload.php';

use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Http\PollHandler;
use LPhenom\Http\Request;

$db  = new PdoMySqlConnection('mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4', 'user', 'pass');
$bus = new DbEventStoreBus($db);

// Route: GET /realtime/poll
$request = Request::fromGlobals();
$handler = new PollHandler($bus);
$response = $handler->handle($request);
$response->send();
```

### Step 3: Nginx configuration

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/myapp/public;

    location /realtime/poll {
        # Long-polling: allow up to 30s request
        fastcgi_read_timeout 35s;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        include fastcgi_params;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        include fastcgi_params;
    }
}
```

### Step 4: Apache configuration

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/myapp/public

    <Directory /var/www/myapp/public>
        AllowOverride All
        Require all granted
    </Directory>

    # .htaccess rewrite
</VirtualHost>
```

`.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

### Publishing from PHP (server side)

```php
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Message;

$bus = new DbEventStoreBus($db);

// In any controller / service:
$bus->publish('chat', new Message(
    0,                          // id=0 → auto-assigned by DB
    'chat',                     // topic
    json_encode(['text' => 'Hello world', 'user' => 'alice']),
    new \DateTimeImmutable()
));
```

### API contract

```
GET /realtime/poll?topic={topic}&since={lastId}&limit={n}

Response 200:
{
  "messages": [
    {
      "id":      5,
      "topic":   "chat",
      "payload": "{\"text\":\"Hello\",\"user\":\"alice\"}",
      "ts":      "2026-03-14 12:00:01"
    }
  ],
  "last_id": 5
}
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `topic`   | required | Channel name |
| `since`   | `0`     | Return only messages with `id > since` |
| `limit`   | `100`   | Max messages per request (capped at 500) |

---

## Mode 2: WebSocket — VPS / KPHP binary

### Requirements

- VPS / cloud server
- MySQL 8.0
- Redis 6+ (for pub/sub)
- PHP 8.1 CLI **or** KPHP-compiled binary

### Step 1: Setup WebSocketBus

```php
<?php
// bootstrap/ws-server.php
require __DIR__ . '/../vendor/autoload.php';

use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Redis\Client\PhpRedisClient;
use LPhenom\Redis\Connection\RedisConnectionConfig;
use LPhenom\Redis\Connection\RedisConnector;
use LPhenom\Redis\PubSub\RedisPublisher;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Bus\WebSocketBus;

$db         = new PdoMySqlConnection('mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4', 'user', 'pass');
$store      = new DbEventStoreBus($db);
$redisConn  = RedisConnector::connect(new RedisConnectionConfig('127.0.0.1', 6379));
$redisClient = new PhpRedisClient($redisConn);
$publisher  = new RedisPublisher($redisClient);

$bus = new WebSocketBus($store, $publisher);
```

### Step 2: Write the WebSocket server process

A WebSocket server must:
1. Accept WebSocket connections from browsers
2. Subscribe to Redis channels on behalf of clients
3. Forward Redis messages to the correct WebSocket clients

#### Minimal example using Ratchet (PHP)

Install Ratchet:

```bash
composer require cboden/ratchet
```

```php
<?php
// ws-server.php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use LPhenom\Redis\Client\PhpRedisClient;
use LPhenom\Redis\Connection\RedisConnectionConfig;
use LPhenom\Redis\Connection\RedisConnector;
use LPhenom\Redis\PubSub\MessageHandlerInterface;
use LPhenom\Redis\PubSub\RedisSubscriber;

/**
 * Manages all connected WebSocket clients.
 * Subscriptions: topic → SplObjectStorage of connections.
 */
final class RealtimeServer implements MessageComponentInterface
{
    /** @var array<string, \SplObjectStorage> */
    private array $topicClients = [];

    public function onOpen(ConnectionInterface $conn): void
    {
        echo "New connection #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // Client sends: {"action":"subscribe","topic":"chat","since":0}
        $data = json_decode($msg, true);
        if (!is_array($data)) {
            return;
        }

        $action = isset($data['action']) ? (string) $data['action'] : '';
        $topic  = isset($data['topic'])  ? (string) $data['topic']  : '';

        if ($action === 'subscribe' && $topic !== '') {
            if (!isset($this->topicClients[$topic])) {
                $this->topicClients[$topic] = new \SplObjectStorage();
            }
            $this->topicClients[$topic]->attach($from);
            $from->send(json_encode(['event' => 'subscribed', 'topic' => $topic]));
        }

        if ($action === 'unsubscribe' && $topic !== '') {
            if (isset($this->topicClients[$topic])) {
                $this->topicClients[$topic]->detach($from);
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        foreach ($this->topicClients as $storage) {
            $storage->detach($conn);
        }
        echo "Connection #{$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    /**
     * Called from Redis subscriber when a message arrives on "realtime:{topic}".
     */
    public function broadcastToTopic(string $topic, string $payload): void
    {
        if (!isset($this->topicClients[$topic])) {
            return;
        }
        $envelope = json_encode(['event' => 'message', 'topic' => $topic, 'payload' => $payload]);
        foreach ($this->topicClients[$topic] as $client) {
            $client->send($envelope);
        }
    }
}

$server = new RealtimeServer();

// Redis subscriber runs in a separate thread/process or via event loop integration
// For simplicity: run Redis subscriber in a forked process
$pid = pcntl_fork();
if ($pid === 0) {
    // Child: Redis subscriber loop
    $redisConn  = RedisConnector::connect(new RedisConnectionConfig('127.0.0.1', 6379));
    $subscriber = new RedisSubscriber($redisConn);

    // Subscribe to all realtime channels (pattern)
    // Adjust channels list to match your topics
    $topics = ['chat', 'events', 'notifications'];
    foreach ($topics as $topic) {
        $subscriber->subscribe('realtime:' . $topic, new class($server, $topic) implements MessageHandlerInterface {
            private RealtimeServer $srv;
            private string $topic;
            public function __construct(RealtimeServer $s, string $t) {
                $this->srv   = $s;
                $this->topic = $t;
            }
            public function handle(string $channel, string $message): void {
                $this->srv->broadcastToTopic($this->topic, $message);
            }
        });
    }
    exit(0);
}

// Parent: WebSocket server
$wsServer = IoServer::factory(
    new HttpServer(new WsServer($server)),
    8080
);
echo "WebSocket server listening on ws://0.0.0.0:8080\n";
$wsServer->run();
```

Run:

```bash
php ws-server.php
```

#### Running as a KPHP binary (compiled mode)

```bash
# Compile
kphp -d /build/kphp-out -M cli build/kphp-entrypoint.php

# Run
/build/kphp-out/cli
```

### Step 3: Nginx proxy for WebSocket

```nginx
server {
    listen 80;
    server_name example.com;

    # HTTP API (long-polling fallback)
    location /realtime/poll {
        proxy_pass http://127.0.0.1:8080;
    }

    # WebSocket upgrade
    location /realtime/ws {
        proxy_pass          http://127.0.0.1:8080;
        proxy_http_version  1.1;
        proxy_set_header    Upgrade    $http_upgrade;
        proxy_set_header    Connection "upgrade";
        proxy_set_header    Host       $host;
        proxy_read_timeout  86400s;   # keep connection alive
    }
}
```

### Step 4: Systemd service for WS server

```ini
# /etc/systemd/system/realtime-ws.service
[Unit]
Description=lphenom/realtime WebSocket server
After=network.target redis.service mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/myapp
ExecStart=/usr/bin/php ws-server.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now realtime-ws
systemctl status realtime-ws
```

---

## Mode 3: Async with QueuedPublishBus

When `publish()` is called from an HTTP request handler and you don't want to block:

```php
use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Worker\PublishWorker;
use LPhenom\Queue\Worker;

// In your HTTP handler — publish is instant, just pushes to queue
$asyncBus = new QueuedPublishBus($innerBus, new RedisQueue($redisClient, 'realtime-jobs'));
$asyncBus->publish('chat', $msg);  // returns in microseconds

// In a background worker (separate process / systemd service):
$worker = new Worker(new RedisQueue($redisClient, 'realtime-jobs'));
$worker->register(QueuedPublishBus::JOB_NAME, new PublishWorker($innerBus));
$worker->run();  // blocking loop
```

---

## Health check endpoint

Add a simple health check for monitoring:

```php
// GET /realtime/health
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'ts' => (new \DateTimeImmutable())->format('c')]);
```

---

## Security considerations

| Concern | Recommendation |
|---------|----------------|
| Topic names | Validate against an allowlist; never expose raw user input as topic |
| Authentication | Validate session/JWT before `publish()` and before WS `subscribe` action |
| Rate limiting | Throttle `publish()` per user in your application layer |
| CORS | Set `Access-Control-Allow-Origin` on the poll endpoint |
| WebSocket origin | Check `Origin` header in `onOpen()` |

Example auth check in poll handler:

```php
// Before handling the poll request:
if (!$session->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
```

