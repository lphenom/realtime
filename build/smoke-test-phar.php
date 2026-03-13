#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * PHAR smoke-test: load the PHAR and verify autoloading + core functionality.
 *
 * Usage: php build/smoke-test-phar.php /path/to/lphenom-realtime.phar
 */
$pharFile = isset($argv[1]) ? (string) $argv[1] : dirname(__DIR__) . '/lphenom-realtime.phar';
if (!file_exists($pharFile)) {
    fwrite(STDERR, 'PHAR not found: ' . $pharFile . PHP_EOL);
    exit(1);
}
require $pharFile;
// -----------------------------------------------------------------
// Test 1: Message DTO
// -----------------------------------------------------------------
$msg = new \LPhenom\Realtime\Message(42, 'chat', '{"text":"hello"}', new \DateTimeImmutable('2026-01-01 00:00:00'));
if ($msg->getId() !== 42) {
    fwrite(STDERR, 'FAIL: Message::getId()' . PHP_EOL);
    exit(1);
}
if ($msg->getTopic() !== 'chat') {
    fwrite(STDERR, 'FAIL: Message::getTopic()' . PHP_EOL);
    exit(1);
}
if ($msg->getPayloadJson() !== '{"text":"hello"}') {
    fwrite(STDERR, 'FAIL: Message::getPayloadJson()' . PHP_EOL);
    exit(1);
}
echo 'smoke-test: Message ok' . PHP_EOL;
// -----------------------------------------------------------------
// Test 2: RealtimeBusInterface via InMemoryBus (defined inline)
// -----------------------------------------------------------------
final class InMemoryBus implements \LPhenom\Realtime\RealtimeBusInterface
{
    /** @var array<int, \LPhenom\Realtime\Message> */
    private array $messages = [];
    /** @var int */
    private static int $seq = 1;
    public function publish(string $topic, \LPhenom\Realtime\Message $msg): void
    {
        $id = self::$seq;
        self::$seq++;
        $this->messages[] = new \LPhenom\Realtime\Message(
            $id,
            $topic,
            $msg->getPayloadJson(),
            $msg->getCreatedAt()
        );
    }
    public function readSince(string $topic, int $sinceId, int $limit): array
    {
        $result = [];
        foreach ($this->messages as $m) {
            if ($m->getTopic() === $topic && $m->getId() > $sinceId) {
                $result[] = $m;
            }
        }
        return $result;
    }
}
$bus = new InMemoryBus();
$bus->publish('events', new \LPhenom\Realtime\Message(0, 'events', '{"a":1}', new \DateTimeImmutable()));
$bus->publish('events', new \LPhenom\Realtime\Message(0, 'events', '{"a":2}', new \DateTimeImmutable()));
$bus->publish('chat', new \LPhenom\Realtime\Message(0, 'chat', '{"b":3}', new \DateTimeImmutable()));
$eventMsgs = $bus->readSince('events', 0, 10);
if (count($eventMsgs) !== 2) {
    fwrite(STDERR, 'FAIL: readSince count expected 2, got ' . count($eventMsgs) . PHP_EOL);
    exit(1);
}
$since1 = $bus->readSince('events', 1, 10);
if (count($since1) !== 1) {
    fwrite(STDERR, 'FAIL: readSince sinceId=1 expected 1, got ' . count($since1) . PHP_EOL);
    exit(1);
}
echo 'smoke-test: RealtimeBusInterface ok' . PHP_EOL;
// -----------------------------------------------------------------
// Test 3: Migration class loads correctly
// -----------------------------------------------------------------
$migration = new \LPhenom\Realtime\Migration\CreateRealtimeEventsTable();
if ($migration->getVersion() !== '20260313000001') {
    fwrite(STDERR, 'FAIL: migration version' . PHP_EOL);
    exit(1);
}
echo 'smoke-test: Migration ok' . PHP_EOL;
echo '=== PHAR smoke-test: OK ===' . PHP_EOL;
