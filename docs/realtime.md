# Единый API реального времени: WebSocket vs Long-Polling

Этот документ объясняет, как писать доменный код, который одинаково работает в режиме
**shared hosting (long-polling)** и **WebSocket (реальное время)** без дублирования.

---

## Единый интерфейс

Все реализации realtime используют один интерфейс:

```php
interface RealtimeBusInterface
{
    public function publish(string $topic, Message $msg): void;
    /** @return Message[] */
    public function readSince(string $topic, int $sinceId, int $limit): array;
}
```

**Бизнес-логика** вызывает `publish()` для отправки событий и `readSince()` для получения истории.
Реализация (только БД или БД+Redis) подключается через DI — доменный код не меняется.

---

## Реализации

| Класс | Транспорт | Подходит для |
|---|---|---|
| `DbEventStoreBus` | только MySQL | Shared hosting, long-polling |
| `WebSocketBus` | MySQL + Redis pub/sub | VPS/облако, WebSocket-клиенты |
| `QueuedPublishBus` | декоратор + любая очередь | Высоконагруженный асинхронный publish |

---

## Режим 1: Shared hosting / Long-Polling

### Настройка

```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Migration\CreateRealtimeEventsTable;

$connection = new PdoMySqlConnection('mysql:host=db;dbname=app', 'user', 'pass');

// Выполнить миграцию один раз (или использовать SchemaMigrations)
$migration = new CreateRealtimeEventsTable();
$migration->up($connection);

$bus = new DbEventStoreBus($connection);
```

### Публикация

```php
use LPhenom\Realtime\Message;

// id=0 означает "назначить автоматически" — реальный id присваивает БД при INSERT
$bus->publish('chat', new Message(0, 'chat', '{"text":"Привет!"}', new \DateTimeImmutable()));
```

### Эндпоинт long-polling

```php
use LPhenom\Http\Request;
use LPhenom\Http\Response;
use LPhenom\Realtime\Http\PollHandler;

// Регистрация в роутере:
$router->get('/realtime/poll', new PollHandler($bus));

// Клиент вызывает:
// GET /realtime/poll?topic=chat&since=0&limit=50
// Ответ: {"messages":[{"id":1,"topic":"chat","payload":"{...}","ts":"..."}],"last_id":1}
```

Схема работы клиента:
1. Начинает с `since=0`
2. Получает сообщения и сохраняет последний `id`
3. Следующий запрос: `since={last_id}` — возвращаются только новые сообщения

---

## Режим 2: WebSocket / Реальное время

### Настройка

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

### Публикация

Аналогично Режиму 1 — API идентичен:

```php
$bus->publish('chat', new Message(0, 'chat', '{"text":"Привет!"}', new \DateTimeImmutable()));
// → записывает в БД (история) И публикует в Redis-канал "realtime:chat" (живая доставка)
```

### Подписка WebSocket-сервера

Процесс WebSocket-сервера подписывается на Redis-каналы и пересылает сообщения клиентам:

```php
use LPhenom\Redis\PubSub\MessageHandlerInterface;
use LPhenom\Redis\PubSub\RedisSubscriber;

final class WsForwarder implements MessageHandlerInterface
{
    public function handle(string $channel, string $message): void
    {
        // Переслать $message всем WebSocket-клиентам, подписанным на $channel
        // например: WebSocketServer::broadcast($channel, $message);
    }
}

// Блокирующий цикл — запускается в отдельном процессе / KPHP корутине
$subscriber = new RedisSubscriber($dedicatedRedisConnection);
$subscriber->subscribe('realtime:chat', new WsForwarder());
```

### Переподключение клиента / воспроизведение истории

При переподключении WebSocket-клиент должен догнать пропущенные сообщения.
Используйте `readSince()` — он читает из БД независимо от типа шины:

```php
// Клиент отправляет: {"action":"subscribe","topic":"chat","since":42}
$missed = $bus->readSince('chat', $lastSeenId, 100);
foreach ($missed as $msg) {
    $ws->send($msg->getPayloadJson());
}
```

---

## Режим 3: Асинхронный / Высоконагруженный (QueuedPublishBus)

Когда `publish()` не должен блокировать HTTP-ответ, оберните любую шину в `QueuedPublishBus`:

```php
use LPhenom\Queue\Driver\RedisQueue;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Worker\PublishWorker;
use LPhenom\Queue\Worker;

// Публикация ставит задачу в очередь вместо прямой записи в БД
$asyncBus = new QueuedPublishBus($bus, $redisQueue);
$asyncBus->publish('chat', $msg); // возвращается немедленно

// В фоновом процессе-воркере:
$worker = new Worker($redisQueue);
$worker->register(QueuedPublishBus::JOB_NAME, new PublishWorker($bus));
$worker->run(); // блокирующий — обрабатывает задачи из очереди
```

---

## Написание KPHP-совместимого доменного кода

Используйте интерфейс в доменных классах — KPHP компилирует их без изменений:

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

Замените `DbEventStoreBus` на `WebSocketBus` при инициализации — `ChatService` остаётся неизменным.

---

## Схема базы данных

Миграция `CreateRealtimeEventsTable` создаёт:

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

Составной индекс `(topic, id)` обеспечивает O(log n) для запросов `readSince()` даже с миллионами строк.

Запуск миграции:

```php
$migration = new \LPhenom\Realtime\Migration\CreateRealtimeEventsTable();
$migration->up($connection);
```

---

## Совместимость с KPHP

| Компонент | KPHP | Примечания |
|---|---|---|
| `Message` DTO | ✅ | Нет readonly, нет constructor promotion |
| `RealtimeBusInterface` | ✅ | Нет callable-типов |
| `DbEventStoreBus` | ✅ | Использует `Param::str/int` — не сырой PDO |
| `WebSocketBus` | ✅ | Использует `RedisPublisher` (KPHP-совместим) |
| `QueuedPublishBus` | ✅ | Использует `Job` и `QueueInterface` |
| `PublishWorker` | ✅ | Реализует `JobHandlerInterface` (без callable) |
| `CreateRealtimeEventsTable` | ✅ | Использует `ConnectionInterface` |
| `PollHandler` | ❌ только PHP | `lphenom/http` использует trailing commas из PHP 8.0+ |

Для KPHP-сборок используйте `DbEventStoreBus` или `WebSocketBus` напрямую.
HTTP-адаптер `PollHandler` — только для PHP-деплоев.

> Смотрите `build/kphp-entrypoint.php` для явного порядка `require_once`.
> Запустите `make kphp-check` для проверки компиляции KPHP.
