# lphenom/realtime
Realtime communications for LPhenom — unified WebSocket / Long-Polling API.
[![CI](https://github.com/lphenom/realtime/actions/workflows/ci.yml/badge.svg)](https://github.com/lphenom/realtime/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
## Overview
`lphenom/realtime` provides a single `RealtimeBusInterface` that works identically in two modes:
| Mode | Implementation | Transport |
|------|---------------|-----------|
| Shared hosting / long-polling | `DbEventStoreBus` | MySQL `realtime_events` table |
| WebSocket / real-time | `WebSocketBus` | MySQL (persistence) + Redis pub/sub (fanout) |
| Async / high-load | `QueuedPublishBus` | Any `QueueInterface` driver (DB or Redis) |
One business-logic codebase — swap the bus without changing domain code.
## Installation
```bash
composer require lphenom/realtime
```
> **Note:** This package uses VCS repositories from the LPhenom ecosystem.
> See [CONTRIBUTING.md](CONTRIBUTING.md) for setup instructions.
## Quick start
```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Message;
// Shared hosting: DB-only bus
$connection = new PdoMySqlConnection('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$bus = new DbEventStoreBus($connection);
// Publish a message
$bus->publish('chat', new Message(0, 'chat', '{"text":"Hello!"}', new \DateTimeImmutable()));
// Poll for new messages (long-polling endpoint)
$messages = $bus->readSince('chat', $lastSeenId, 100);
foreach ($messages as $msg) {
    echo $msg->getId() . ': ' . $msg->getPayloadJson() . PHP_EOL;
}
```
## Documentation
- [docs/realtime.md](docs/realtime.md) — WebSocket vs Long-Polling guidance
## Requirements
- PHP >= 8.1
- KPHP-compatible (no reflection, no eval, no dynamic class loading)
## License
MIT — see [LICENSE](LICENSE).
