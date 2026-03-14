<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Bus;

use LPhenom\Queue\Job;
use LPhenom\Queue\QueueInterface;
use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;

/**
 * Async publish decorator — defers publish() to a background queue worker.
 *
 * Instead of writing to DB/Redis in the HTTP request cycle, publish()
 * enqueues a job. A worker running PublishWorker processes the queue
 * and calls the inner bus's publish().
 *
 * readSince() is always delegated directly to the inner bus (not queued).
 *
 * KPHP-compatible:
 *   - Uses QueueInterface and Job from lphenom/queue (no callable)
 *   - No reflection, no closures
 *   - JSON payload serialization without JSON_THROW_ON_ERROR
 *
 * @lphenom-build shared,kphp
 */
final class QueuedPublishBus implements RealtimeBusInterface
{
    public const JOB_NAME = 'realtime.publish';
    /** @var RealtimeBusInterface */
    private RealtimeBusInterface $inner;
    /** @var QueueInterface */
    private QueueInterface $queue;
    public function __construct(RealtimeBusInterface $inner, QueueInterface $queue)
    {
        $this->inner = $inner;
        $this->queue = $queue;
    }
    /**
     * Enqueue a publish job instead of writing directly.
     *
     * A worker running PublishWorker must process the queue.
     * @see \LPhenom\Realtime\Worker\PublishWorker
     */
    public function publish(string $topic, Message $msg): void
    {
        $payloadArr = [
            'topic'        => $topic,
            'id'           => $msg->getId(),
            'payload_json' => $msg->getPayloadJson(),
            'created_at'   => $msg->getCreatedAt()->format('Y-m-d H:i:s')
        ];
        $json = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $this->queue->push(Job::create(self::JOB_NAME, $json));
    }
    /**
     * Read directly from inner bus (not queued).
     *
     * @return Message[]
     */
    public function readSince(string $topic, int $sinceId, int $limit = 100): array
    {
        return $this->inner->readSince($topic, $sinceId, $limit);
    }
}
