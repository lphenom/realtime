<?php
declare(strict_types=1);
namespace LPhenom\Realtime\Worker;
use LPhenom\Queue\Job;
use LPhenom\Queue\JobHandlerInterface;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;
/**
 * Queue worker handler for realtime.publish jobs.
 *
 * Registered with lphenom/queue Worker to process jobs enqueued
 * by QueuedPublishBus::publish().
 *
 * Usage (in your bootstrap/worker entrypoint):
 *
 *   $worker = new \LPhenom\Queue\Worker($queue);
 *   $worker->register(QueuedPublishBus::JOB_NAME, new PublishWorker($bus));
 *   $worker->run();
 *
 * KPHP-compatible:
 *   - Implements JobHandlerInterface (no callable)
 *   - Manual json_decode with is_array() narrowing
 *   - Explicit type casts from mixed
 *
 * @lphenom-build shared,kphp
 */
final class PublishWorker implements JobHandlerInterface
{
    /** @var RealtimeBusInterface */
    private RealtimeBusInterface $bus;
    public function __construct(RealtimeBusInterface $bus)
    {
        $this->bus = $bus;
    }
    /**
     * Decode the job payload and call bus->publish().
     *
     * On invalid JSON or missing topic: silently skips (job is ack-ed by Worker).
     * On DateTimeImmutable parse error: falls back to current time.
     */
    public function handle(Job $job): void
    {
        $decoded = json_decode($job->getPayloadJson(), true);
        $data    = [];
        if (is_array($decoded)) {
            $data = $decoded;
        }
        $topicRaw = $data['topic'] ?? null;
        $topic    = $topicRaw !== null ? (string) $topicRaw : '';
        if ($topic === '') {
            return;
        }
        $idRaw = $data['id'] ?? null;
        $id    = $idRaw !== null ? (int) $idRaw : 0;
        $payloadRaw  = $data['payload_json'] ?? null;
        $payloadJson = $payloadRaw !== null ? (string) $payloadRaw : '{}';
        $dateRaw  = $data['created_at'] ?? null;
        $dateStr  = $dateRaw !== null ? (string) $dateRaw : '';
        $createdAt = new \DateTimeImmutable();
        if ($dateStr !== '') {
            $createdAt = new \DateTimeImmutable($dateStr);
        }
        $this->bus->publish($topic, new Message($id, $topic, $payloadJson, $createdAt));
    }
}
