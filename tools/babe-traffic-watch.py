#!/usr/bin/env python3
"""Контроль одного домена babeporntube.ru (РК Kadam 948645): статус, расход,
клики, выручка, VibeMetrics и решение guard v1. Read-only + guard --only.
Запуск из cron каждые 5 минут под flock; секреты не печатает."""
import json, subprocess, time, os

DB = 'db_babeporntube_ru'
CID = '948645'
DOMAIN = 'babeporntube.ru'
DOMAIN_ID = 58


def mysql(q):
    return subprocess.run(['mysql', '--protocol=socket', '-N', '-B', '-e', q],
                          capture_output=True, text=True).stdout.strip()


def out_line(**kw):
    print(time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime()) + ' UTC ' +
          ' '.join('%s=%s' % (k, v) for k, v in kw.items()), flush=True)


def main():
    cfg = mysql("SELECT params_json FROM fapacrm.crm_cron_jobs WHERE job_type='cost' AND domain_id=49 LIMIT 1")
    tok = json.loads(cfg.split('\n')[0]).get('bearer', '')
    H = ['-H', 'Authorization: Bearer ' + tok, '-H', 'Accept: application/json', '--compressed']
    day = time.strftime('%Y-%m-%d', time.gmtime(time.time() + 3 * 3600))
    try:
        raw = subprocess.run(['curl', '-s', '--max-time', '20', *H,
                              'https://partners.kadam.net/api/v1/campaigns/' + CID],
                             capture_output=True, text=True, errors='replace').stdout
        status = json.loads(raw).get('data', {}).get('status', '?')
    except Exception:
        status = 'ERR'
    try:
        raw = subprocess.run(['curl', '-s', '--max-time', '30', *H, '-X', 'POST',
                              'https://partners.kadam.net/api/v1/stats/extended',
                              '-H', 'Content-Type: application/json',
                              '--data', json.dumps({'campaignIds': [int(CID)], 'perPage': 20,
                                                    'filters': {'dateFrom': day, 'dateTo': day,
                                                                'timezone': 3}})],
                             capture_output=True, text=True, errors='replace').stdout
        rows = json.loads(raw).get('data', {}).get('rows', [])
        spend = next((r.get('spending') for r in rows
                      if isinstance(r.get('name'), dict) and r['name'].get('type') == 'slice'), '?')
    except Exception:
        spend = 'ERR'
    clicks5 = mysql("SELECT COUNT(*) FROM %s.clicks WHERE ts>=NOW()-INTERVAL 5 MINUTE AND campaign_id>1" % DB)
    clicks60 = mysql("SELECT COUNT(*) FROM %s.clicks WHERE ts>=NOW()-INTERVAL 1 HOUR AND campaign_id>1" % DB)
    rev = mysql("SELECT ROUND(SUM(amount),4) FROM fapacrm.crm_finance_daily WHERE domain_id=%d AND d=CURDATE() AND kind='revenue'" % DOMAIN_ID)
    try:
        key = open('/root/.vm_agent_key').read().strip()
        vm = subprocess.run(['curl', '-s', '--max-time', '25', '-H', 'Authorization: Bearer ' + key,
                             'https://vibemetrics.click/api/agent/stats?domains=' + DOMAIN +
                             '&from=' + day + '&to=' + day], capture_output=True, text=True).stdout
        st = json.loads(vm).get('stats', [{}])[0]
        events, human = st.get('events', 0), st.get('human_visitors', 0)
    except Exception:
        events, human = 'ERR', 'ERR'
    page = subprocess.run(['curl', '-sL', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15',
                           'https://' + DOMAIN + '/'], capture_output=True, text=True).stdout.strip()
    out_line(kadam_status=status, spend_v1=spend, clicks_5m=clicks5, clicks_1h=clicks60,
             revenue_crm=rev, vm_events=events, vm_human=human, page=page)
    # решение гарда по правилу оператора: OFF при spend>=0.2 и ROI<0, retest на новый день
    g = subprocess.run(['python3', '/root/kadam-buy-guard-v1.py', '--only=' + CID],
                       capture_output=True, text=True, timeout=180)
    for line in g.stdout.strip().splitlines():
        print('  guard:', line, flush=True)


if __name__ == '__main__':
    main()
