<?php
declare(strict_types=1);
namespace LPhenom\Realtime\Tests;
use LPhenom\Queue\Job;
use LPhenom\Queue\QueueInterface;
use LPhenom\Realtime\Bus\QueuedPublishBus;
use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;
use PHPUnit\Framework\TestCase;
/**
 * @covers \LPhenom\Realtime\Bus\QueuedPublishBus
 */
final class QueuedPublishBusTest extends TestCase
{
    public function testPublishPushesJobToQueue(): void
    {
        $inner = $this->createMock(RealtimeBusInterface::class);
        $inner->expects(self::never())->method('publish');
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())
            ->method('push')
            ->with(self::isInstanceOf(Job::class));
        $bus = new QueuedPublishBus($inner, $queue);
        $msg = new Message(0, 'chat', '{"text":"async"}', new \DateTimeImmutable());
        $bus->publish('chat', $msg);
    }
    public function testPublishJobHasCorrectName(): void
    {
        $inner = $this->createMock(RealtimeBusInterface::class);
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())
            ->method('push')
            ->with(self::callback(function (Job $job): bool {
                return $job->getName() === QueuedPublishBus::JOB_NAME;
            }));
        $bus = new QueuedPublishBus($inner, $queue);
        $msg = new Message(0, 'events', '{}', new \DateTimeImmutable());
        $bus->publish('events', $msg);
    }
    public function testPublishJobPayloadContainsTopic(): void
    {
        $inner = $this->createMock(RealtimeBusInterface::class);
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())
            ->method('push')
            ->with(self::callback(function (Job $job): bool {
                $decoded = json_decode($job->getPayloadJson(), true);
                if (!is_array($decoded)) {
                    return false;
                }
                return isset($decoded['topic']) && $decoded['topic'] === 'chat';
            }));
        $bus = new QueuedPublishBus($inner, $queue);
        $msg = new Message(0, 'chat', '{}', new \DateTimeImmutable());
        $bus->publish('chat', $msg);
    }
    public function testReadSinceDelegatesToInnerBus(): void
    {
        $expected = [new Message(1, 'chat', '{}', new \DateTimeImmutable())];
        $inner = $this->createMock(RealtimeBusInterface::class);
        $inner->expects(self::once())
            ->method('readSince')
            ->with('chat', 5, 10)
            ->willReturn($expected);
        $queue = $this->createMock(QueueInterface::class);
        $bus    = new QueuedPublishBus($inner, $queue);
        $result = $bus->readSince('chat', 5, 10);
        self::assertSame($expected, $result);
    }
}
