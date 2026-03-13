<?php
declare(strict_types=1);
namespace LPhenom\Realtime;
/**
 * Unified realtime event bus interface.
 *
 * Implementations:
 *   - DbEventStoreBus   — DB-only, shared hosting, long-polling
 *   - WebSocketBus      — DB persistence + Redis pub/sub fanout (WebSocket)
 *   - QueuedPublishBus  — async decorator, defers publish() to a queue worker
 *
 * Both publish() and readSince() have the same semantics regardless
 * of the underlying transport — business code never needs to change.
 *
 * KPHP-compatible:
 *   - No callable types
 *   - Return type array is annotated in PHPDoc
 *
 * @lphenom-build shared,kphp
 */
interface RealtimeBusInterface
{
    /**
     * Publish a message to the given topic.
     *
     * For DB implementations: writes a row to realtime_events.
     * For Redis implementations: also fans out to pub/sub channel.
     *
     * @param string  $topic channel / topic name
     * @param Message $msg   message to publish (id may be 0 for new messages)
     */
    public function publish(string $topic, Message $msg): void;
    /**
     * Read messages newer than sinceId (for long-polling / history replay).
     *
     * Returns messages ordered by id ASC.
     * Clients store the last received id and pass it as sinceId on the next poll.
     *
     * @param  string    $topic   topic name
     * @param  int       $sinceId only return messages with id > sinceId (0 = all)
     * @param  int       $limit   maximum number of messages to return
     * @return Message[]
     */
    public function readSince(string $topic, int $sinceId, int $limit): array;
}
