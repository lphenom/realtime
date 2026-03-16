# lphenom/realtime

Коммуникации реального времени для LPhenom — единый API WebSocket / Long-Polling.

[![CI](https://github.com/lphenom/realtime/actions/workflows/ci.yml/badge.svg)](https://github.com/lphenom/realtime/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Обзор

`lphenom/realtime` предоставляет единый `RealtimeBusInterface`, который одинаково работает в двух режимах:

| Режим | Реализация | Транспорт |
|---|---|---|
| Shared hosting / long-polling | `DbEventStoreBus` | таблица MySQL `realtime_events` |
| WebSocket / реальное время | `WebSocketBus` | MySQL (хранение) + Redis pub/sub (fanout) |
| Асинхронный / высоконагруженный | `QueuedPublishBus` | Любой драйвер `QueueInterface` (БД или Redis) |

Одна кодовая база бизнес-логики — меняйте шину без изменения доменного кода.

## Установка

```bash
composer require lphenom/realtime
```

> **Примечание:** Этот пакет использует VCS-репозитории из экосистемы LPhenom.
> Инструкции по настройке — в [CONTRIBUTING.md](CONTRIBUTING.md).

## Быстрый старт

```php
use LPhenom\Db\Driver\PdoMySqlConnection;
use LPhenom\Realtime\Bus\DbEventStoreBus;
use LPhenom\Realtime\Message;

// Shared hosting: шина только через БД
$connection = new PdoMySqlConnection('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$bus = new DbEventStoreBus($connection);

// Опубликовать сообщение
$bus->publish('chat', new Message(0, 'chat', '{"text":"Привет!"}', new \DateTimeImmutable()));

// Получить новые сообщения (эндпоинт long-polling)
$messages = $bus->readSince('chat', $lastSeenId, 100);
foreach ($messages as $msg) {
    echo $msg->getId() . ': ' . $msg->getPayloadJson() . PHP_EOL;
}
```

## Документация

- [docs/realtime.md](docs/realtime.md) — WebSocket vs Long-Polling: как писать единый код
- [docs/server-setup.md](docs/server-setup.md) — Настройка сервера (shared hosting и VPS/KPHP)
- [docs/client-guide.md](docs/client-guide.md) — Клиентская интеграция (Vue, React, Pure JS)

## Требования

- PHP >= 8.1
- KPHP-совместим (нет reflection, нет eval, нет динамической загрузки классов)

## Лицензия

MIT — см. [LICENSE](LICENSE).
