<?php
declare(strict_types=1);

namespace LPhenom\Realtime\Tests;

use LPhenom\Realtime\Message;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LPhenom\Realtime\Message
 */
final class MessageTest extends TestCase
{
    public function testGetters(): void
    {
        $createdAt = new \DateTimeImmutable('2026-03-13 10:00:00');
        $msg       = new Message(42, 'chat', '{"text":"hello"}', $createdAt);
        self::assertSame(42, $msg->getId());
        self::assertSame('chat', $msg->getTopic());
        self::assertSame('{"text":"hello"}', $msg->getPayloadJson());
        self::assertSame($createdAt, $msg->getCreatedAt());
    }
    public function testZeroIdIsAllowedForNewMessages(): void
    {
        $msg = new Message(0, 'events', '{}', new \DateTimeImmutable());
        self::assertSame(0, $msg->getId());
    }
    public function testEmptyPayloadJson(): void
    {
        $msg = new Message(1, 'test', '', new \DateTimeImmutable());
        self::assertSame('', $msg->getPayloadJson());
    }
    public function testDifferentTopics(): void
    {
        $msg = new Message(1, 'user.created', '{"id":5}', new \DateTimeImmutable());
        self::assertSame('user.created', $msg->getTopic());
    }
}
