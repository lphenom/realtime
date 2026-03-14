<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Bus;

use LPhenom\Db\Contract\ConnectionInterface;
use LPhenom\Db\Param\Param;
use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;

/**
 * DB-backed realtime bus for shared hosting.
 *
 * Stores events in the `realtime_events` table.
 * Use for long-polling: call readSince() periodically from your HTTP handler.
 *
 * Create the table first:
 *   $migration = new \LPhenom\Realtime\Migration\CreateRealtimeEventsTable();
 *   $migration->up($connection);
 *
 * KPHP-compatible:
 *   - Uses ConnectionInterface from lphenom/db (no raw PDO)
 *   - Uses Param::str() / Param::int() for safe parameter binding
 *   - No callable, no reflection
 *   - Named SQL parameters (:name style)
 *
 * @lphenom-build shared,kphp
 */
final class DbEventStoreBus implements RealtimeBusInterface
{
    /** @var ConnectionInterface */
    private ConnectionInterface $connection;
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }
    /**
     * Persist a message to realtime_events.
     *
     * The id in $msg is ignored — the DB assigns the AUTO_INCREMENT id.
     * The topic parameter overrides $msg->getTopic() to allow re-routing.
     */
    public function publish(string $topic, Message $msg): void
    {
        $this->connection->execute(
            'INSERT INTO realtime_events (topic, payload_json, created_at)'
            . ' VALUES (:topic, :payload_json, :created_at)',
            [
                ':topic'        => Param::str($topic),
                ':payload_json' => Param::str($msg->getPayloadJson()),
                ':created_at'   => Param::str($msg->getCreatedAt()->format('Y-m-d H:i:s'))
            ]
        );
    }
    /**
     * Fetch messages with id > sinceId, ordered by id ASC.
     *
     * @return Message[]
     */
    public function readSince(string $topic, int $sinceId, int $limit = 100): array
    {
        $result = $this->connection->query(
            'SELECT id, topic, payload_json, created_at'
            . ' FROM realtime_events'
            . ' WHERE topic = :topic AND id > :since_id'
            . ' ORDER BY id ASC'
            . ' LIMIT :lim',
            [
                ':topic'    => Param::str($topic),
                ':since_id' => Param::int($sinceId),
                ':lim'      => Param::int($limit)
            ]
        );
        $rows     = $result->fetchAll();
        $messages = [];
        foreach ($rows as $row) {
            $idRaw  = $row['id'] ?? null;
            $id     = $idRaw !== null ? (int) $idRaw : 0;
            $topicRaw = $row['topic'] ?? null;
            $rowTopic = $topicRaw !== null ? (string) $topicRaw : $topic;
            $payloadRaw = $row['payload_json'] ?? null;
            $payload    = $payloadRaw !== null ? (string) $payloadRaw : '{}';
            $dateRaw   = $row['created_at'] ?? null;
            $dateStr   = $dateRaw !== null ? (string) $dateRaw : '';
            $createdAt = $dateStr !== '' ? new \DateTimeImmutable($dateStr) : new \DateTimeImmutable();
            $messages[] = new Message($id, $rowTopic, $payload, $createdAt);
        }
        return $messages;
    }
}
