<?php
declare(strict_types=1);
namespace LPhenom\Realtime\Migration;
use LPhenom\Db\Contract\ConnectionInterface;
use LPhenom\Db\Migration\MigrationInterface;
/**
 * Migration: create the realtime_events table.
 *
 * Implements lphenom/db MigrationInterface so it can be tracked and run
 * via SchemaMigrations or manually.
 *
 * Schema:
 *   - id          BIGINT UNSIGNED AUTO_INCREMENT — event sequence (used as sinceId cursor)
 *   - topic       VARCHAR(255)     — channel / topic name
 *   - payload_json LONGTEXT        — arbitrary JSON payload
 *   - created_at  DATETIME         — event timestamp (server time)
 *   - INDEX (topic, id)            — fast readSince() queries
 *
 * KPHP-compatible: no reflection, no PDO directly.
 *
 * @lphenom-build shared,kphp
 */
final class CreateRealtimeEventsTable implements MigrationInterface
{
    public function up(ConnectionInterface $conn): void
    {
        $conn->execute(
            'CREATE TABLE IF NOT EXISTS realtime_events ('
            . '  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  topic        VARCHAR(255)    NOT NULL,'
            . '  payload_json LONGTEXT        NOT NULL,'
            . '  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (id),'
            . '  INDEX idx_topic_id (topic, id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            []
        );
    }
    public function down(ConnectionInterface $conn): void
    {
        $conn->execute('DROP TABLE IF EXISTS realtime_events', []);
    }
    public function getVersion(): string
    {
        return '20260313000001';
    }
}
