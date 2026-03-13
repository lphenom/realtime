<?php
declare(strict_types=1);
namespace LPhenom\Realtime\Tests;
use LPhenom\Redis\Client\RedisClientInterface;
use LPhenom\Redis\PubSub\RedisPublisher;
use LPhenom\Realtime\Bus\WebSocketBus;
use LPhenom\Realtime\Message;
use LPhenom\Realtime\RealtimeBusInterface;
use PHPUnit\Framework\TestCase;
/**
 * @covers \LPhenom\Realtime\Bus\WebSocketBus
 */
final class WebSocketBusTest extends TestCase
{
    public function testPublishCallsStoreAndRedis(): void
    {
        $store = $this->createMock(RealtimeBusInterface::class);
        $store->expects(self::once())
            ->method('publish')
            ->with('chat', self::isInstanceOf(Message::class));
        $client = $this->createMock(RedisClientInterface::class);
        $client->expects(self::once())
            ->method('publish')
            ->with('realtime:chat', '{"text":"hello"}');
        $publisher = new RedisPublisher($client);
        $bus       = new WebSocketBus($store, $publisher);
        $msg = new Message(0, 'chat', '{"text":"hello"}', new \DateTimeImmutable());
        $bus->publish('chat', $msg);
    }
    public function testPublishUsesCorrectRedisChannel(): void
    {
        $store  = $this->createMock(RealtimeBusInterface::class);
        $client = $this->createMock(RedisClientInterface::class);
        $client->expects(self::once())
            ->method('publish')
            ->with('realtime:events', self::anything());
        $publisher = new RedisPublisher($client);
        $bus       = new WebSocketBus($store, $publisher);
        $msg = new Message(0, 'events', '{}', new \DateTimeImmutable());
        $bus->publish('events', $msg);
    }
    public function testReadSinceDelegatesToStore(): void
    {
        $expected = [
            new Message(1, 'chat', '{}', new \DateTimeImmutable()),
            new Message(2, 'chat', '{}', new \DateTimeImmutable())
        ];
        $store = $this->createMock(RealtimeBusInterface::class);
        $store->expects(self::once())
            ->method('readSince')
            ->with('chat', 5, 20)
            ->willReturn($expected);
        $client    = $this->createMock(RedisClientInterface::class);
        $publisher = new RedisPublisher($client);
        $bus       = new WebSocketBus($store, $publisher);
        $result = $bus->readSince('chat', 5, 20);
        self::assertCount(2, $result);
        self::assertSame($expected, $result);
    }
}
