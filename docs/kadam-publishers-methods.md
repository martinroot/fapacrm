# Kadam Advertiser API v1 — паблишеры (placement/site), шпаргалка

База: `https://partners.kadam.net/api/v1`. Auth: `Authorization: Bearer <advertiser API key>`.
Заголовок `User-Agent: curl/8.5.0` обязателен (иначе Cloudflare 1010/403 на python-клиенты).
Ответ: `{"success":true,"code":200,"data":...}`; ошибки читать из `data.msg`, HTTP часто 200/422.
Спека: `https://partners.kadam.net/api-doc/openapi.json?v=1.4.0`.

## Сущности

- Паблишер = **placement/site**, 16-значный `macrosId`. В трекере — `push.click.token1`.
- Перед write всегда сверять `click.token1 === row.macrosId` для того же Kadam campaign.
- Маппинг своих РК (tracker → Kadam): `1290→955610`, `1291→966929`, `1292→966931`.
- `click.token2` = click ID у паблишера, `token3` = GEO.

## Методы

| Что | Метод | Тело / параметры |
|---|---|---|
| Список/поиск сайтов | `POST /stats/sites` | `{"page":1,"perPage":50,"filters":{"campaignIds":[ID],"view":"all"\|"blacklist","searchQuery":"SITE_ID"}}` |
| Заблокировать | `POST /stats/sites/block` | `{"campaignIds":[ID],"zones":["SITE_ID",...]}` |
| Разблокировать | `POST /stats/sites/unblock` | та же форма |
| Кампания целиком | `GET /campaigns/{id}` | readback статуса, что блок не задел таргет |
| Баланс | `GET /finances/balance` | — |

Лимиты на запрос: 100 кампаний, 500 zones, 2000 пар campaign×zone.
`view=blacklist` + `isInBlackList=true` — подтверждение блока. Пагинацию листать до `totalRows`; `hasNextPage=false` на урезанной первой странице не доказательство полного списка.

**Старые `ads.*` (`ads.stats.campaign.placement.get`, `ads.campaigns.update` с `black_list`) закрыты — HTTP 410. Не использовать.**

## Правило автоблеклиста (приказ владельца)

rolling **90 дней**, `clicks > 200` (строго), `SUM(cpc) > 0`, `ROI < 0%` (строго), считается отдельно по каждой паре:
`ROI = (SUM(click.rev) - SUM(click.cpc)) / SUM(click.cpc) * 100`.
Ровно 200 кликов и ровно 0% — не проходят. Минимум spend не добавлять.

## Процедура (порядок обязателен)

1. Одна единственная writer-задача с локом, каждые 15 мин; убедись, что legacy `auto/module19.php`, `module23.php` не пишут (старый API мёртв).
2. Агрегируй `click.token1` по `camp_id` за 90 дней в MSK (`SET SESSION time_zone='+03:00'`).
3. Для каждого кандидата `POST /stats/sites` (`view=all`, точный `searchQuery`) → требуй `macrosId === token1`.
4. `POST /stats/sites/block` батчами ≤500 zones.
5. Readback: `POST /stats/sites` (`view=blacklist`) → `isInBlackList=true`. Только после этого upsert `push.black_list_ts` (`pub_name=macrosId`, `source_id`, tracker `camp_id`, `status_api=1`) — Source: badge BL в `page/all_pubs.php`.
6. Проверь эффект по факту: клики `click.token1=<zone>` подтверждённо продолжаются/остановились; HTTP 200 сам по себе ничего не доказывает.
7. Разблокировка — только по прямому приказу владельца, `POST /stats/sites/unblock` + readback, автоматической амнистии нет.

## Скрипт

`/home/main/kadam-publishers-v1.php` — самодостаточный PHP 8.3, без секретов в коде.

```
php kadam-publishers-v1.php --self-test
php kadam-publishers-v1.php --list --kadam=955610 --view=blacklist
php kadam-publishers-v1.php --list --kadam=955610 --query=1938819183573963
php kadam-publishers-v1.php --check --camp=1290 --kadam=955610
php kadam-publishers-v1.php --camp=1290 --kadam=955610            # dry-run
php kadam-publishers-v1.php --camp=1290 --kadam=955610 --apply    # write + readback
php kadam-publishers-v1.php --block --camp=1290 --kadam=955610 --zones=ID,ID --apply
php kadam-publishers-v1.php --unblock --camp=1290 --kadam=955610 --zones=ID,ID --apply
```

Токен: env `KADAM_BEARER` → `--token-file=PATH` → локальная CRM `fapacrm.crm_cron_jobs` (`connector='kadam_cost'`). БД: env/флаги `--db-host --db-user --db-pass --db-name` (по умолчанию localhost/root/push). Лок: `/var/lock/kadam-publisher-blacklist-v1.lock`. Лог: `--log=/path`.

Без `--apply` все режимы только читают/печатают `DRY`. `--block` требует mapping `campaings.source_campaing_id == Kadam ID`; UI-отметка пишется только после API readback.

## Ловушки

- `click.rev` (момент клика) и `postback_log.payout` (момент постбека) — разные деньги/время; в правиле блеклиста используется `click.rev`.
- Повторный поиск свежего блока через `searchQuery` может промахнуться — fallback: полное сканирование страниц `view=blacklist` с точным `macrosId`.
- Статус кампании `200` = перемодерация, но трафик может продолжаться; сайт-блок не трогает `countryTarget` и не вызывает full update.
- Не снимать BL автоматически по исчезновению кликов: после блока выборки нет.
