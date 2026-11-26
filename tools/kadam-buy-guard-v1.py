#!/usr/bin/env python3
"""kadam-buy-guard-v1 — per-domain spend guard on Kadam Advertiser API v1.

Rule (unchanged, operator 03.11): active campaign with today's spend >= $0.20
and ROI < 0 -> pause; a campaign THIS guard paused on an earlier day -> activate
(retest). Payment/moderation statuses are never touched. Bids/GEO are untouched:
v1 status endpoints (/campaigns/pause, /campaigns/activate) change only status.

Data: spend = POST /stats/extended (timezone 3), revenue = crm_finance_daily.
All HTTP via curl (urllib gets Cloudflare 403). No secrets on disk/stdout.
Modes: --self-test, --dry-run, --only=<campaign_id>, --resume=<campaign_id>,
       default: full guard cycle over crm_buy_campaigns registry.
Writes require --apply (except --resume, which requires --apply too).
"""
import json, subprocess, time, os, sys, fcntl

BASE = 'https://partners.kadam.net/api/v1'
MIN_SPEND_OFF = 0.20
TODAY_OFF_ROI = 0.0
TZ = 3
LOG = '/var/log/kadam-buy-guard-v1.log'
STATE = '/var/lib/kadam-buy-guard-v1.json'
LOCK = '/var/lock/kadam-buy-guard-v1.lock'
ACTIVE_STATUS = (1, 10)   # 1 active, 10 active/moderating
PAUSED_STATUS = (0,)
MODERATION_STATUS = (140,)


def log(msg):
    line = '[%s UTC] %s' % (time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime()), msg)
    os.makedirs(os.path.dirname(LOG), exist_ok=True)
    with open(LOG, 'a') as f:
        f.write(line + '\n')
    print(line, flush=True)


def sql(q):
    return subprocess.run(['mysql', '--protocol=socket', '-N', '-B', '-e', q],
                          capture_output=True, text=True).stdout.strip()


def bearer():
    return sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(params_json,'$.bearer')) "
               "FROM fapacrm.crm_cron_jobs WHERE job_type='cost' AND domain_id=49 LIMIT 1")


def api(token, method, path, body=None, tries=3):
    args = ['curl', '-s', '--compressed', '--max-time', '40', '-X', method,
            BASE + path, '-H', 'Authorization: Bearer ' + token,
            '-H', 'Accept: application/json', '-H', 'User-Agent: curl/8.5.0']
    if body is not None:
        args += ['-H', 'Content-Type: application/json',
                 '--data', json.dumps(body, ensure_ascii=False, separators=(',', ':'))]
    raw = ''
    for i in range(tries):
        r = subprocess.run(args, capture_output=True, text=True, errors='replace')
        raw = r.stdout
        try:
            d = json.loads(raw)
            if d.get('success') is True or (isinstance(d.get('data'), dict)):
                return d
        except Exception:
            pass
        time.sleep(5)
    raise RuntimeError('Kadam %s %s failed: %s' % (method, path, raw[:160]))


def campaign(token, cid):
    d = api(token, 'GET', '/campaigns/' + str(int(cid)))
    c = d.get('data', d)
    return {'id': int(c['id']), 'status': int(c.get('status', -1)),
            'name': str(c.get('name', '')), 'dayMoneyLimit': c.get('dayMoneyLimit')}


def spend_today(token, cid, day):
    d = api(token, 'POST', '/stats/extended', {
        'campaignIds': [int(cid)], 'perPage': 20,
        'filters': {'dateFrom': day, 'dateTo': day, 'timezone': TZ},
    })
    rows = d.get('data', {}).get('rows') or []
    # Each top-level slice row carries the campaign total; take the first one.
    for r in rows:
        if isinstance(r.get('name'), dict) and r['name'].get('type') == 'slice':
            return float(str(r.get('spending') or 0).replace(' ', '').replace('$', '') or 0)
    return 0.0


def set_status(token, cid, status):
    path = '/campaigns/pause' if status == 0 else '/campaigns/activate'
    api(token, 'POST', path, {'campaignIds': [int(cid)]})
    time.sleep(2)
    rb = campaign(token, cid)
    want = PAUSED_STATUS if status == 0 else ACTIVE_STATUS
    ok = rb['status'] in want
    return ok, 'readback status=%d' % rb['status']


def eligible(clicks, spend, rev):
    """Pure rule for tests: spend>=0.20 and ROI<0 (fraction)."""
    return spend >= MIN_SPEND_OFF and (rev - spend) / spend < TODAY_OFF_ROI


def self_test():
    assert not eligible(1, 0.19, 0.0), 'below spend threshold'
    assert eligible(1, 0.20, 0.19), 'strict loss'
    assert not eligible(1, 0.20, 0.20), 'zero ROI not off'
    assert not eligible(1, 0.20, 0.21), 'profit not off'
    assert not eligible(1, 0.0, 0.0), 'no division by zero'
    print('PASS guard v1 rule: spend>=0.2, ROI<0 strict')


def main():
    if '--self-test' in sys.argv:
        self_test()
        return
    dry = '--dry-run' in sys.argv
    apply = '--apply' in sys.argv
    only = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--only=')), None)
    resume = next((a.split('=', 1)[1] for a in sys.argv if a.startswith('--resume=')), None)
    lp = open(LOCK, 'a+')
    try:
        fcntl.flock(lp, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print('guard v1 lock busy; skipped')
        return

    token = bearer()
    if not token:
        log('no bearer in CRM; abort')
        return
    rows = [l.split('\t') for l in sql(
        'SELECT campaign_id, domain_id FROM fapacrm.crm_buy_campaigns ORDER BY domain_id'
    ).splitlines() if l.strip()]
    reg = {r[0]: int(r[1]) for r in rows if len(r) == 2}
    rev = {}
    for line in sql("SELECT domain_id, ROUND(SUM(amount),6) FROM fapacrm.crm_finance_daily "
                    "WHERE d=CURDATE() AND kind='revenue' GROUP BY domain_id").splitlines():
        if '\t' in line:
            k, v = line.split('\t')
            rev[int(k)] = float(v)
    state = {}
    if os.path.exists(STATE):
        try:
            state = json.load(open(STATE))
        except Exception:
            state = {}
    day = time.strftime('%Y-%m-%d', time.gmtime(time.time() + TZ * 3600))

    if resume:
        if resume not in reg:
            log('RESUME refused: campaign %s not in registry' % resume)
            return
        c = campaign(token, resume)
        if c['status'] == 0:
            if not apply:
                log('DRY RESUME %s (%s) status=0; pass --apply to write' % (resume, c['name']))
                return
            ok, detail = set_status(token, resume, 1)
            if ok:
                s = state.get(resume, {})
                s.update({'retest_day': day, 'manual_resume': True})
                state[resume] = s
                with open(STATE, 'w') as f:
                    json.dump(state, f, ensure_ascii=False)
                log('RESUMED %s (%s) %s' % (resume, c['name'], detail))
            else:
                log('RESUME FAILED %s %s' % (resume, detail))
        else:
            log('RESUME skipped %s: status=%d (not paused)' % (resume, c['status']))
        return

    targets = [only] if only else list(reg)
    log('cycle: registry=%d targets=%d kadam_day=%s%s' %
        (len(reg), len(targets), day, ' DRY-RUN' if dry else ''))
    changed = False
    for cid in targets:
        if cid not in reg:
            log('skip %s: not in registry' % cid)
            continue
        c = campaign(token, cid)
        st = c['status']
        if st in MODERATION_STATUS:
            log('%s: moderation status=%d; skip' % (cid, st))
            continue
        spend = spend_today(token, cid, day)
        rv = rev.get(reg[cid], 0.0)
        s = state.get(cid, {})
        if st in ACTIVE_STATUS and eligible(1, spend, rv):
            what = 'OFF spend=%.4f rev=%.4f roi=%.2f' % (spend, rv, (rv - spend) / spend)
            if dry:
                log('DRY %s %s' % (cid, what))
                continue
            ok, detail = set_status(token, cid, 0)
            if ok:
                s.update({'off_day': day, 'cost': spend, 'rev': rv,
                          'roi': (rv - spend) / spend})
                state[cid] = s
                changed = True
                log('%s: PAUSED %s %s' % (cid, what, detail))
            else:
                log('%s: OFF FAILED %s' % (cid, detail))
        elif st in PAUSED_STATUS and s.get('off_day') and s['off_day'] != day:
            if dry:
                log('DRY %s RETEST off_day=%s' % (cid, s['off_day']))
                continue
            ok, detail = set_status(token, cid, 1)
            if ok:
                s['retest_day'] = day
                state[cid] = s
                changed = True
                log('%s: RESUMED retest %s' % (cid, detail))
            else:
                log('%s: RETEST FAILED %s' % (cid, detail))
    if changed:
        with open(STATE, 'w') as f:
            json.dump(state, f, ensure_ascii=False)
    log('cycle done')


if __name__ == '__main__':
    main()
