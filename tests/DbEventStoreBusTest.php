<?php
declare(strict_types=1);
namespace LPhenom\Realtime\Tests;
use LPhenom\Db\Contract\ConnectionInterface;
use LPhenom\Db\Contract\ResultInterface;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Message;
use PHPUnit\Framework\TestCase;
/**
 * @covers \LPhenom\Realtime\Bus\DbEventStoreBus
 */
final class DbEventStoreBusTest extends TestCase
{
    public function testPublishExecutesInsert(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::stringContains('INSERT INTO realtime_events'),
                self::arrayHasKey(':topic')
            )
            ->willReturn(1);
        $bus = new DbEventStoreBus($connection);
        $msg = new Message(0, 'chat', '{"text":"hello"}', new \DateTimeImmutable());
        $bus->publish('chat', $msg);
    }
    public function testPublishPassesCorrectTopic(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('execute')
            ->with(
                self::anything(),
                self::callback(function (array $params): bool {
                    return isset($params[':topic'])
                        && $params[':topic']->value === 'events';
                })
            )
            ->willReturn(1);
        $bus = new DbEventStoreBus($connection);
        $msg = new Message(0, 'events', '{}', new \DateTimeImmutable());
        $bus->publish('events', $msg);
    }
    public function testReadSinceReturnsMappedMessages(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $result->expects(self::once())
            ->method('fetchAll')
            ->willReturn([
                [
                    'id'           => '1',
                    'topic'        => 'chat',
                    'payload_json' => '{"text":"hi"}',
                    'created_at'   => '2026-01-01 00:00:00'
                ],
                [
                    'id'           => '2',
                    'topic'        => 'chat',
                    'payload_json' => '{"text":"bye"}',
                    'created_at'   => '2026-01-01 00:01:00'
                ]
            ]);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::stringContains('SELECT'),
                self::arrayHasKey(':topic')
            )
            ->willReturn($result);
        $bus      = new DbEventStoreBus($connection);
        $messages = $bus->readSince('chat', 0, 10);
        self::assertCount(2, $messages);
        self::assertSame(1, $messages[0]->getId());
        self::assertSame('chat', $messages[0]->getTopic());
        self::assertSame('{"text":"hi"}', $messages[0]->getPayloadJson());
        self::assertSame(2, $messages[1]->getId());
    }
    public function testReadSinceReturnsEmptyArrayWhenNoResults(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $result->method('fetchAll')->willReturn([]);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('query')->willReturn($result);
        $bus      = new DbEventStoreBus($connection);
        $messages = $bus->readSince('chat', 999, 10);
        self::assertSame([], $messages);
    }
    public function testReadSincePassesSinceIdParam(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $result->method('fetchAll')->willReturn([]);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(function (array $params): bool {
                    return isset($params[':since_id'])
                        && $params[':since_id']->value === '42';
                })
            )
            ->willReturn($result);
        $bus = new DbEventStoreBus($connection);
        $bus->readSince('chat', 42, 10);
    }
}
