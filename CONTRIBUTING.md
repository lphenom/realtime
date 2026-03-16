# Участие в разработке lphenom/realtime

Спасибо за интерес к проекту! 🎉

## Требования

- PHP >= 8.1
- Docker + Docker Compose (для запуска тестов с сервисами)
- Composer

## Настройка окружения

```bash
git clone git@github.com:lphenom/realtime.git
cd realtime
composer install

# Запуск тестов
make test
```

## Стиль кода

PSR-12. Автоисправление:

```bash
make lint-fix
```

Проверка:

```bash
make lint
```

## Статический анализ

```bash
make analyse   # PHPStan level 8
```

## Совместимость с KPHP

Весь код **обязан** оставаться KPHP-совместимым. Правила:

- Нет constructor property promotion (`__construct(private $x)`)
- Нет `readonly` свойств
- Нет `Reflection`, `eval()`, `$$var`, `new $className()`
- Нет `str_starts_with`, `str_ends_with`, `str_contains` — используйте `substr`/`strpos`
- `try/catch` всегда с явным `catch`
- Нет `callable` в типизированных массивах

## Сообщения коммитов

Следуйте [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(realtime): добавить поддержку TTL
fix(realtime): исправить обработку пустого ключа
test(realtime): добавить интеграционный тест
```

## Чеклист Pull Request

- [ ] Тесты проходят: `make test`
- [ ] Нет ошибок линтера: `make lint`
- [ ] PHPStan проходит: `make analyse`
- [ ] KPHP-совместимо (нет запрещённых конструкций)
- [ ] Документация обновлена при изменении публичного API

## Лицензия

Участвуя в проекте, вы соглашаетесь, что ваши изменения будут лицензированы под [MIT License](LICENSE).
