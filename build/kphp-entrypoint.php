<?php
declare(strict_types=1);
/**
 * lphenom/realtime — KPHP compilation entrypoint.
 *
 * Explicit require_once in dependency order (KPHP does not support PSR-4 autoloading).
 * Only KPHP-compatible (@lphenom-build shared,kphp) files are included.
 *
 * Order: base types → interfaces → concrete classes → realtime src
 */
// ============================================================
// lphenom/db — KPHP-compatible files only
// ============================================================
require_once __DIR__ . '/../vendor/lphenom/db/src/Param/Param.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Param/ParamBinder.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/ResultInterface.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/TransactionCallbackInterface.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Contract/ConnectionInterface.php';
require_once __DIR__ . '/../vendor/lphenom/db/src/Migration/MigrationInterface.php';
// ============================================================
// lphenom/redis — KPHP-compatible files only (no ext-redis files)
// ============================================================
require_once __DIR__ . '/../vendor/lphenom/redis/src/Pipeline/RedisPipelineDriverInterface.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Pipeline/RedisPipeline.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/Client/RedisClientInterface.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/PubSub/MessageHandlerInterface.php';
require_once __DIR__ . '/../vendor/lphenom/redis/src/PubSub/RedisPublisher.php';
// ============================================================
// lphenom/queue — KPHP-compatible files only
// ============================================================
require_once __DIR__ . '/../vendor/lphenom/queue/src/Job.php';
require_once __DIR__ . '/../vendor/lphenom/queue/src/QueueInterface.php';
require_once __DIR__ . '/../vendor/lphenom/queue/src/JobHandlerInterface.php';
// ============================================================
// lphenom/realtime — src/ (KPHP-compatible files)
// Note: src/Http/PollHandler.php is excluded (@lphenom-build shared)
// ============================================================
require_once __DIR__ . '/../src/Exception/RealtimeException.php';
require_once __DIR__ . '/../src/Message.php';
require_once __DIR__ . '/../src/RealtimeBusInterface.php';
require_once __DIR__ . '/../src/Migration/CreateRealtimeEventsTable.php';
require_once __DIR__ . '/../src/Bus/DbEventStoreBus.php';
require_once __DIR__ . '/../src/Bus/WebSocketBus.php';
require_once __DIR__ . '/../src/Bus/QueuedPublishBus.php';
require_once __DIR__ . '/../src/Worker/PublishWorker.php';
// ============================================================
// Minimal runtime check (KPHP CLI binary output)
// ============================================================
$msg = new \LPhenom\Realtime\Message(1, 'kphp-test', '{"check":true}', new \DateTimeImmutable());
$id    = $msg->getId();
$topic = $msg->getTopic();
echo 'id: ' . $id . "\n";
echo 'topic: ' . $topic . "\n";
echo 'lphenom/realtime kphp-check: ok' . "\n";
