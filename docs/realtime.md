# lphenom/realtime — WebSocket vs Long-Polling guide
This document explains how to write domain code that works identically in both
**shared hosting (long-polling)** and **WebSocket (real-time)** modes without duplication.
---
## The unified interface
All realtime implementations share one interface:
```php
interface RealtimeBusInterface
{
    public function publish(string $topic, Message $msg): void;
    /** @return Message[] */
    public function readSince(string $topic, int $sinceId, int $limit): array;
}
```
Your **business logic** calls `publish()` to emit events and `readSince()` to fetch history.
The implementation (DB-only or DB+Redis) is injected via DI — business code never changes.
---
## Implementations at a glance
| Class | Transport | Best for |
|---|---|---|
| `DbEventStoreBus` | MySQL only | Shared hosting, long-polling |
| `WebSocketBus` | MySQL + Redis pub/sub | VPS/cloud, WebSocket clients |
| `QueuedPublishBus` | decorator + any queue | High-load async publish |
---
## Mode 1: Shared hosting / Long-Polling
### Setup
```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Migration\CreateRealtimeEventsTable;
$connection = new PdoMySqlConnection('mysql:host=db;dbname=app', 'user', 'pass');
// Run migration once (or use SchemaMigrations)
$migration = new CreateRealtimeEventsTable();
$migration->up($connection);
$bus = new DbEventStoreBus($connection);
```
### Publishing
```php
use LPhenom\Realtime\Message;
// id=0 means "auto-assign" — the DB assigns the real id on INSERT
$bus->publish('chat', new Message(0, 'chat', '{"text":"Hello!"}', new \DateTimeImmutable()));
```
### Long-polling endpoint
```php
use LPhenom\Http\Request;
use LPhenom\Http\Response;
use LPhenom\Realtime\Http\PollHandler;
// Register with your router:
$router->get('/realtime/poll', new PollHandler($bus));
// Client calls:
// GET /realtime/poll?topic=chat&since=0&limit=50
// Response: {"messages":[{"id":1,"topic":"chat","payload":"{...}","ts":"..."}],"last_id":1}
```
The client:
1. Starts with `since=0`
2. Receives messages and stores the last `id`
3. Next request: `since={last_id}` — only new messages are returned
---
## Mode 2: WebSocket / Real-time
### Setup
```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Redis\Client\PhpRedisClient;
use LPhenom\Redis\Connection\RedisConnectionConfig;
use LPhenom\Redis\Connection\RedisConnector;
use LPhenom\Redis\PubSub\RedisPublisher;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Bus\WebSocketBus;
$connection = new PdoMySqlConnection('mysql:host=db;dbname=app', 'user', 'pass');
$store      = new DbEventStoreBus($connection);
$config    = new RedisConnectionConfig('redis', 6379);
$redisConn = RedisConnector::connect($config);
$client    = new PhpRedisClient($redisConn);
$publisher = new RedisPublisher($client);
$bus = new WebSocketBus($store, $publisher);
```
### Publishing
Same as Mode 1 — the API is identical:
```php
$bus->publish('chat', new Message(0, 'chat', '{"text":"Hi!"}', new \DateTimeImmutable()));
// → writes to DB (history) AND publishes to Redis channel "realtime:chat" (live delivery)
```
### WebSocket server subscription
Your WebSocket server process subscribes to Redis channels and forwards to clients:
```php
use LPhenom\Redis\PubSub\MessageHandlerInterface;
use LPhenom\Redis\PubSub\RedisSubscriber;
final class WsForwarder implements MessageHandlerInterface
{
    public function handle(string $channel, string $message): void
    {
        // Forward $message to all WebSocket clients subscribed to $channel
        // e.g.: WebSocketServer::broadcast($channel, $message);
    }
}
// Blocking loop — run in a separate process / KPHP coroutine
$subscriber = new RedisSubscriber($dedicatedRedisConnection);
$subscriber->subscribe('realtime:chat', new WsForwarder());
```
### Client reconnect / history replay
When a WebSocket client reconnects, it needs to backfill missed messages.
Use `readSince()` — it reads from DB regardless of the bus type:
```php
// Client sends: {"action":"subscribe","topic":"chat","since":42}
$missed = $bus->readSince('chat', $lastSeenId, 100);
foreach ($missed as $msg) {
    $ws->send($msg->getPayloadJson());
}
```
---
## Mode 3: Async / High-load (QueuedPublishBus)
When `publish()` must not block the HTTP response, wrap any bus with `QueuedPublishBus`:
```php
use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Worker\PublishWorker;
use LPhenom\Queue\Worker;
// Publishing enqueues a job instead of writing to DB directly
$asyncBus = new QueuedPublishBus($bus, $redisQueue);
$asyncBus->publish('chat', $msg); // returns immediately
// In your background worker process:
$worker = new Worker($redisQueue);
$worker->register(QueuedPublishBus::JOB_NAME, new PublishWorker($bus));
$worker->run(); // blocking — processes jobs from queue
```
---
## Writing KPHP-compatible domain code
Use the interface in your domain classes — KPHP compiles them without changes:
```php
final class ChatService
{
    /** @var RealtimeBusInterface */
    private RealtimeBusInterface $bus;
    public function __construct(RealtimeBusInterface $bus)
    {
        $this->bus = $bus;
    }
    public function sendMessage(string $room, string $text): void
    {
        $json = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $this->bus->publish($room, new Message(0, $room, $json, new \DateTimeImmutable()));
    }
    /** @return Message[] */
    public function getHistory(string $room, int $sinceId): array
    {
        return $this->bus->readSince($room, $sinceId, 100);
    }
}
```
Swap `DbEventStoreBus` for `WebSocketBus` at bootstrap — `ChatService` stays identical.
---
## Database schema
The `CreateRealtimeEventsTable` migration creates:
```sql
CREATE TABLE IF NOT EXISTS realtime_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  topic        VARCHAR(255)    NOT NULL,
  payload_json LONGTEXT        NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_topic_id (topic, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```
The compound index `(topic, id)` makes `readSince()` queries O(log n) even with millions of rows.
Run migration:
```php
$migration = new \LPhenom\Realtime\Migration\CreateRealtimeEventsTable();
$migration->up($connection);
```
---
## KPHP compatibility
| Component | KPHP | Notes |
|---|---|---|
| `Message` DTO | ✅ | No readonly, no constructor promotion |
| `RealtimeBusInterface` | ✅ | No callable types |
| `DbEventStoreBus` | ✅ | Uses `Param::str/int` — no raw PDO |
| `WebSocketBus` | ✅ | Uses `RedisPublisher` (KPHP-compatible) |
| `QueuedPublishBus` | ✅ | Uses `Job` and `QueueInterface` |
| `PublishWorker` | ✅ | Implements `JobHandlerInterface` (no callable) |
| `CreateRealtimeEventsTable` | ✅ | Uses `ConnectionInterface` |
| `PollHandler` | ❌ shared only | `lphenom/http` has PHP 8.0+ trailing commas |
For KPHP builds, use `DbEventStoreBus` or `WebSocketBus` directly.
The `PollHandler` HTTP adapter is for PHP-only deployments.
> See `build/kphp-entrypoint.php` for the explicit require_once order.
> Run `make kphp-check` to verify KPHP compilation passes.
