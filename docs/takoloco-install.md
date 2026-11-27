# TacoLoco push — установка на клоны

Код push-подписки хранится в БД клона, а не в файлах шаблона:

```sql
-- db_<domain>
INSERT INTO ad_blocks(name,slot,type,code,enabled)
VALUES('Tacoloco_push','head','html',
       '<script type="application/javascript" src="https://yuuae.sjscdn.com/ps/ps.js?id=<ExternalID>" async></script>', 1);
INSERT INTO ad_block_pages(block_id,page_key)
SELECT id,'all' FROM ad_blocks WHERE name='Tacoloco_push';
```

Без строки в `ad_block_pages` рендерер `hp_render_ad_blocks()`block не выводит. Перед UPDATE: `mysqldump <db> ad_blocks > /root/backup-adblocks-<domain>-<ts>.sql`.

Проверка: `curl -sL https://<domain>/ | grep -o 'ps.js?id=[A-Za-z0-9_-]*'` → ExternalID домена.

ExternalID берётся из `GET https://api.tacolo.co/publisher/v1/placements?token=<token>` (Bearer нет, токен в query; не печатать). Статистика: `GET /publisher/v1/statistics/push/date?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&placement=<NUMERIC_ID>&token=...` — ExternalID там не принимается (400).

Плейсменты сети (2026-11-27):

| Домен | site ID | push ID | in-page ID |
|---|---|---|---|
| hotfuckzone.ru | 124965 | 231033 | 231034 |
| milfstreamhub.ru | 124969 | 231047 | 231048 |
| tubexxxflux.ru | 124964 | 231031 | 231032 |
| xxx-milf-tube.ru | 124966 | 231035 | 231036 |
| xxx-milftube.ru | 124968 | 231039 | 231040 |

In-page Push установлен только после получения точного embed-кода из панели — API его не отдаёт.

CDN `yuuae.sjscdn.com` отдаёт 403 на серверный IP; это нормально — код грузится браузерами посетителей.
