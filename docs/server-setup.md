# Настройка сервера

Этот документ объясняет развёртывание **lphenom/realtime** в двух режимах:

- **Long-Polling режим** — shared hosting, Apache/Nginx + PHP-FPM, без дополнительных процессов
- **WebSocket режим** — VPS/облако, KPHP-скомпилированный бинарник или PHP ReactPHP сервер

---

## Обзор архитектуры

```
┌────────────────── Long-Polling (shared hosting) ──────────────────┐
│                                                                     │
│  Клиент ──── HTTP GET /realtime/poll?topic=chat&since=42 ────────► │
│                          PHP (PollHandler)                          │
│                                 │                                   │
│                         MySQL (realtime_events)                     │
│                                 │                                   │
│  Клиент ◄──── {"messages":[...],"last_id":45} ───────────────────  │
└─────────────────────────────────────────────────────────────────────┘

┌────────────────── WebSocket (VPS / KPHP) ──────────────────────────┐
│                                                                     │
│  Publisher ──► WebSocketBus::publish() ──► MySQL + Redis pub/sub   │
│                                                    │                │
│                                          Redis-канал               │
│                                         "realtime:chat"            │
│                                                    │                │
│                                         WS Server процесс          │
│                                      (подписан на Redis)            │
│                                                    │                │
│  WS Клиент ◄────────── push сообщение ─────────────┘               │
│  WS Клиент ◄────────── push сообщение                              │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Режим 1: Long-Polling — shared hosting

### Требования

- PHP >= 8.1
- MySQL 5.7+ / 8.0
- Apache или Nginx + PHP-FPM
- **Дополнительные процессы не нужны**

### Шаг 1: Запуск миграции

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
echo "Миграция выполнена\n";
```

Запуск (один раз):

```bash
php bootstrap/migrate.php
```

### Шаг 2: Регистрация эндпоинта poll

```php
<?php
// public/index.php (или ваш роутер)
require __DIR__ . '/../vendor/autoload.php';

use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Http\PollHandler;
use LPhenom\Http\Request;

$db  = new PdoMySqlConnection('mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4', 'user', 'pass');
$bus = new DbEventStoreBus($db);

// Маршрут: GET /realtime/poll
$request = Request::fromGlobals();
$handler = new PollHandler($bus);
$response = $handler->handle($request);
$response->send();
```

### Шаг 3: Настройка Nginx

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/myapp/public;

    location /realtime/poll {
        # Long-polling: разрешить запросы до 30с
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

### Шаг 4: Настройка Apache

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/myapp/public

    <Directory /var/www/myapp/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

### Публикация на стороне PHP

```php
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Message;

$bus = new DbEventStoreBus($db);

// В любом контроллере / сервисе:
$bus->publish('chat', new Message(
    0,                          // id=0 → авто-назначение БД
    'chat',                     // топик
    json_encode(['text' => 'Привет мир', 'user' => 'alice']),
    new \DateTimeImmutable()
));
```

### Контракт API

```
GET /realtime/poll?topic={topic}&since={lastId}&limit={n}

Ответ 200:
{
  "messages": [
    {
      "id":      5,
      "topic":   "chat",
      "payload": "{\"text\":\"Привет\",\"user\":\"alice\"}",
      "ts":      "2026-03-14 12:00:01"
    }
  ],
  "last_id": 5
}
```

| Параметр | По умолчанию | Описание |
|---|---|---|
| `topic` | обязателен | Имя канала |
| `since` | `0` | Возвращать только сообщения с `id > since` |
| `limit` | `100` | Максимум сообщений за запрос (не более 500) |

---

## Режим 2: WebSocket — VPS / KPHP binary

### Требования

- VPS / облачный сервер
- MySQL 8.0
- Redis 6+ (для pub/sub)
- PHP 8.1 CLI **или** KPHP-скомпилированный бинарник

### Шаг 1: Настройка WebSocketBus

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

### Шаг 2: Написание процесса WebSocket-сервера

WebSocket-сервер должен:
1. Принимать WebSocket-соединения от браузеров
2. Подписываться на Redis-каналы от имени клиентов
3. Пересылать Redis-сообщения нужным WebSocket-клиентам

#### Минимальный пример с Ratchet (PHP)

Установить Ratchet:

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
 * Управляет всеми подключёнными WebSocket-клиентами.
 * Подписки: топик → SplObjectStorage соединений.
 */
final class RealtimeServer implements MessageComponentInterface
{
    /** @var array<string, \SplObjectStorage> */
    private array $topicClients = [];

    public function onOpen(ConnectionInterface $conn): void
    {
        echo "Новое соединение #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // Клиент отправляет: {"action":"subscribe","topic":"chat","since":0}
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
        echo "Соединение #{$conn->resourceId} закрыто\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    /**
     * Вызывается Redis-подписчиком при получении сообщения в "realtime:{topic}".
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

// Redis-подписчик запускается в отдельном процессе (через pcntl_fork)
$pid = pcntl_fork();
if ($pid === 0) {
    // Дочерний: цикл Redis-подписчика
    $redisConn  = RedisConnector::connect(new RedisConnectionConfig('127.0.0.1', 6379));
    $subscriber = new RedisSubscriber($redisConn);

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

// Родительский: WebSocket-сервер
$wsServer = IoServer::factory(
    new HttpServer(new WsServer($server)),
    8080
);
echo "WebSocket сервер слушает ws://0.0.0.0:8080\n";
$wsServer->run();
```

Запуск:

```bash
php ws-server.php
```

#### Запуск как KPHP binary (скомпилированный режим)

```bash
# Компиляция
kphp -d /build/kphp-out -M cli build/kphp-entrypoint.php

# Запуск
/build/kphp-out/cli
```

### Шаг 3: Nginx-прокси для WebSocket

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
        proxy_read_timeout  86400s;   # держать соединение живым
    }
}
```

### Шаг 4: Systemd-сервис для WS-сервера

```ini
# /etc/systemd/system/realtime-ws.service
[Unit]
Description=lphenom/realtime WebSocket сервер
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

## Режим 3: Асинхронный с QueuedPublishBus

Когда `publish()` вызывается из HTTP-обработчика и нежелательно блокировать ответ:

```php
use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Worker\PublishWorker;
use LPhenom\Queue\Worker;

// В HTTP-обработчике — publish мгновенный, просто кладёт в очередь
$asyncBus = new QueuedPublishBus($innerBus, new RedisQueue($redisClient, 'realtime-jobs'));
$asyncBus->publish('chat', $msg);  // выполняется за микросекунды

// В фоновом воркере (отдельный процесс / systemd-сервис):
$worker = new Worker(new RedisQueue($redisClient, 'realtime-jobs'));
$worker->register(QueuedPublishBus::JOB_NAME, new PublishWorker($innerBus));
$worker->run();  // блокирующий цикл
```

---

## Эндпоинт проверки работоспособности

Добавьте простую проверку для мониторинга:

```php
// GET /realtime/health
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'ts' => (new \DateTimeImmutable())->format('c')]);
```

---

## Вопросы безопасности

| Проблема | Рекомендация |
|---|---|
| Имена топиков | Валидировать по белому списку; никогда не передавать сырой пользовательский ввод как топик |
| Аутентификация | Проверять сессию/JWT перед `publish()` и перед WS-действием `subscribe` |
| Ограничение запросов | Ограничивать вызовы `publish()` на пользователя на уровне приложения |
| CORS | Установить `Access-Control-Allow-Origin` на эндпоинте poll |
| Origin WebSocket | Проверять заголовок `Origin` в `onOpen()` |

Пример проверки аутентификации в poll-обработчике:

```php
// Перед обработкой poll-запроса:
if (!$session->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}
```
