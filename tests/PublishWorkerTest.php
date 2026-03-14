<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Tests;

use LPhenom\Queue\Job;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;
use LPhenom\Realtime\Worker\PublishWorker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LPhenom\Realtime\Worker\PublishWorker
 */
final class PublishWorkerTest extends TestCase
{
    public function testHandlePublishesMessageToBus(): void
    {
        $bus = $this->createMock(RealtimeBusInterface::class);
        $bus->expects(self::once())
            ->method('publish')
            ->with('chat', self::isInstanceOf(Message::class));
        $worker = new PublishWorker($bus);
        $payloadJson = json_encode([
            'topic'        => 'chat',
            'id'           => 1,
            'payload_json' => '{"text":"hello"}',
            'created_at'   => '2026-01-01 00:00:00'
        ]);
        $payloadJson = $payloadJson !== false ? $payloadJson : '{}';
        $job = Job::create(QueuedPublishBus::JOB_NAME, $payloadJson);
        $worker->handle($job);
    }
    public function testHandleIgnoresInvalidJson(): void
    {
        $bus = $this->createMock(RealtimeBusInterface::class);
        $bus->expects(self::never())->method('publish');
        $worker = new PublishWorker($bus);
        $job    = Job::create(QueuedPublishBus::JOB_NAME, 'not-valid-json');
        $worker->handle($job);
    }
    public function testHandleIgnoresEmptyTopic(): void
    {
        $bus = $this->createMock(RealtimeBusInterface::class);
        $bus->expects(self::never())->method('publish');
        $worker = new PublishWorker($bus);
        $payloadJson = json_encode([
            'topic'        => '',
            'id'           => 1,
            'payload_json' => '{}',
            'created_at'   => '2026-01-01 00:00:00'
        ]);
        $payloadJson = $payloadJson !== false ? $payloadJson : '{}';
        $job = Job::create(QueuedPublishBus::JOB_NAME, $payloadJson);
        $worker->handle($job);
    }
    public function testHandlePassesCorrectTopicTobus(): void
    {
        $capturedTopic = '';
        $bus           = $this->createMock(RealtimeBusInterface::class);
        $bus->expects(self::once())
            ->method('publish')
            ->with(
                self::callback(function (string $topic) use (&$capturedTopic): bool {
                    $capturedTopic = $topic;
                    return true;
                }),
                self::anything()
            );
        $worker = new PublishWorker($bus);
        $payloadJson = json_encode([
            'topic'        => 'user.events',
            'id'           => 5,
            'payload_json' => '{"event":"login"}',
            'created_at'   => '2026-03-13 10:00:00'
        ]);
        $payloadJson = $payloadJson !== false ? $payloadJson : '{}';
        $job = Job::create(QueuedPublishBus::JOB_NAME, $payloadJson);
        $worker->handle($job);
        self::assertSame('user.events', $capturedTopic);
    }
    public function testHandleUsesCurrentTimeOnMissingDate(): void
    {
        $bus = $this->createMock(RealtimeBusInterface::class);
        $bus->expects(self::once())
            ->method('publish')
            ->with('chat', self::isInstanceOf(Message::class));
        $worker = new PublishWorker($bus);
        $payloadJson = json_encode([
            'topic'        => 'chat',
            'id'           => 1,
            'payload_json' => '{}'
        ]);
        $payloadJson = $payloadJson !== false ? $payloadJson : '{}';
        $job = Job::create(QueuedPublishBus::JOB_NAME, $payloadJson);
        $worker->handle($job);
    }
}
