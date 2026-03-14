<?php
declare(strict_types=1);

namespace LPhenom\Realtime;

/**
 * Immutable realtime event message DTO.
 *
 * Represents a single event published to a topic.
 * The id field is 0 when creating a new message for publishing
 * (AUTO_INCREMENT assigns the actual id after DB insert).
 *
 * KPHP-compatible:
 *   - No constructor property promotion
 *   - No readonly properties
 *   - Explicit property declarations
 *
 * @lphenom-build shared,kphp
 */
final class Message
{
    /** @var int */
    private int $id;
    /** @var string */
    private string $topic;
    /** @var string */
    private string $payloadJson;
    /** @var \DateTimeImmutable */
    private \DateTimeImmutable $createdAt;
    public function __construct(
        int $id,
        string $topic,
        string $payloadJson,
        \DateTimeImmutable $createdAt
    ) {
        $this->id          = $id;
        $this->topic       = $topic;
        $this->payloadJson = $payloadJson;
        $this->createdAt   = $createdAt;
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getTopic(): string
    {
        return $this->topic;
    }
    public function getPayloadJson(): string
    {
        return $this->payloadJson;
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
