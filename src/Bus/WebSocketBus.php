<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Bus;

use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;
use LPhenom\Redis\PubSub\RedisPublisher;

/**
 * WebSocket-capable realtime bus.
 *
 * Combines DB persistence (via DbEventStoreBus) with Redis pub/sub fanout.
 *
 * Flow:
 *   1. publish() → writes to DB (for history/replay via readSince)
 *   2. publish() → publishes to Redis channel "realtime:{topic}" (real-time WS delivery)
 *   3. Your WebSocket server subscribes to "realtime:{topic}" via RedisSubscriber
 *      and forwards incoming messages to connected clients.
 *
 * readSince() reads from DB — used for message history replay when a client reconnects.
 *
 * KPHP-compatible:
 *   - No callable, no reflection
 *   - Uses RedisPublisher from lphenom/redis (wraps RedisClientInterface)
 *   - Depends on RealtimeBusInterface (not concrete DbEventStoreBus) for testability
 *
 * @lphenom-build shared,kphp
 */
final class WebSocketBus implements RealtimeBusInterface
{
    /** @var RealtimeBusInterface */
    private RealtimeBusInterface $store;
    /** @var RedisPublisher */
    private RedisPublisher $publisher;
    /**
     * @param RealtimeBusInterface $store     DB-backed bus for persistence (typically DbEventStoreBus)
     * @param RedisPublisher       $publisher Redis publisher for real-time WS fanout
     */
    public function __construct(RealtimeBusInterface $store, RedisPublisher $publisher)
    {
        $this->store     = $store;
        $this->publisher = $publisher;
    }
    /**
     * Persist to DB and fan out to Redis channel "realtime:{topic}".
     *
     * WebSocket servers subscribe to "realtime:{topic}" to receive messages in real time.
     */
    public function publish(string $topic, Message $msg): void
    {
        $this->store->publish($topic, $msg);
        $this->publisher->publish('realtime:' . $topic, $msg->getPayloadJson());
    }
    /**
     * Read from DB for history replay (on reconnect or first poll).
     *
     * @return Message[]
     */
    public function readSince(string $topic, int $sinceId, int $limit = 100): array
    {
        return $this->store->readSince($topic, $sinceId, $limit);
    }
}
