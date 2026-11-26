# FapaCRM — админка сети тюбов

Рабочая админка FapaCRM: CRM-панель по доменам, финансы, закуп Kadam, метрики, конвейер деплоя клонов.

## Установка

1. Скопируйте код в `/var/www/<hub>/fapacrm/` (владелец `www-data`).
2. `cp config.example.php config.php` и заполните реальные значения **на сервере**.
3. Пользовательские override-настройки — в `config.user.php` (также не коммитится).
4. Выполните миграции из `migrations/` в БД `fapacrm`.

## Структура

- `pages/` — разделы панели (dashboard, finance, buy, networks, cron_jobs, …)
- `lib/` — коннекторы (Kadam, Takoloco, VibeMetrics, OwnMetrika), cron-раннер, хелперы
- `assets/` — JS/CSS и `sprites/` — PNG-спрайты шаблонов клонов, сгруппированные по домену
- `migrations/` — SQL-миграции схемы
- `deploy-cli.php` — CLI-деплой клонов
- `var/` — runtime-кэш и логи (создаётся на сервере, в репозиторий не входит)

## Безопасность

`config.php`, `config.user.php`, `var/`, `*.bak*` намеренно исключены из репозитория. «Пришлите ключ» — публичная часть SSH-ключа лежит в `~/.ssh/id_ed25519_hermeskanban.pub` на сервере; приватная часть никогда не попадает в чат или репозиторий.
