"""Exercise real Redis Lua contracts on an isolated Unix-socket instance."""
import pathlib
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor

root = pathlib.Path(__file__).resolve().parents[1] / 'upstream/backend'
with tempfile.TemporaryDirectory(prefix='speedtest-redis-') as directory:
    socket = directory + '/redis.sock'
    process = subprocess.Popen(['redis-server', '--port', '0', '--unixsocket', socket,
                                '--save', '', '--appendonly', 'no'], stdout=subprocess.DEVNULL)
    def cmd(*args):
        return subprocess.check_output(['redis-cli', '-s', socket, '--raw', *map(str,args)], text=True).strip()
    def start(token, source='a', now=1000, prefix='', network=None, deadline=None):
        network = network or source
        return cmd('EVAL', (root/'session-start.lua').read_text(), 8,
                   prefix+'active:'+source, prefix+'recent:'+source, prefix+'rolling:'+source,
                   prefix+'session:'+token, prefix+'global', prefix+'prefix5:'+network,
                   prefix+'prefix1h:'+network, prefix+'starts5', token, 2, 5, 60, 300, source, now, 43200,20,120,30,
                   time.time()+30 if deadline is None else deadline)
    def finish(token, source='a', prefix=''):
        return cmd('EVAL', (root/'session-finish.lua').read_text(), 3,
                   prefix+'global',prefix+'active:'+source,prefix+'session:'+token,token,source)
    try:
        for _ in range(100):
            if pathlib.Path(socket).exists(): break
            time.sleep(.01)
        assert start('late',prefix='late:',deadline=0)=='0\nunavailable'
        assert start('missing',prefix='late:',deadline='')=='0\nunavailable'
        assert cmd('KEYS','late:*')=='', 'Stale dispatch must not create locks or charge quota'
        # Real simultaneous clients: exactly one winner and one budget reservation.
        with ThreadPoolExecutor(max_workers=20) as workers:
            answers = list(workers.map(lambda i: start('race-'+str(i), 'source-'+str(i), prefix='race:'), range(20)))
        assert answers.count('1\nok') == 1
        assert answers.count('0\nactive') == 19
        winner = answers.index('1\nok')
        assert cmd('KEYS','race:global-budget') == ''
        assert finish('race-'+str(winner), 'source-'+str(winner), prefix='race:') == '1'
        assert cmd('EXISTS','race:global') == '0'
        assert start('one') == '1\nok'
        assert start('two','b') == '0\nactive'
        assert finish('one','b') == '-1'
        assert cmd('GET','global') == 'one'
        assert finish('one') == '1'
        assert start('two',now=1100) == '1\nok'
        assert finish('two') == '1'
        assert start('three',now=1299) == '0\nrecent\n1'
        assert start('three',now=1301) == '1\nok'
        script=(root/'payload.lua').read_text()
        args=['session:three','global','bytes','phase','slots','slots:dl','three','a',100,200,60,1301,12,'req1',25,12,8,time.time()+30]
        assert cmd('EVAL',script,6,*args[:-1],0)=='-2'
        assert cmd('EVAL',script,6,*args[:-1])=='-2'
        assert cmd('EXISTS','bytes','phase','slots')=='0', 'Stale payload dispatch must be inert'
        assert cmd('EVAL',script,6,*args) == '1'
        args[13]='req2'
        assert cmd('EVAL',script,6,*args) == '1'
        assert cmd('EVAL',script,6,*args) == '0'
        args[11]=1314
        assert cmd('EVAL',script,6,*args) == '0'
        assert finish('three') == '1'
        for i in range(5):
            assert start('window-'+str(i),'c',now=2000+i*301) == '1\nok'
            assert finish('window-'+str(i),'c') == '1'
        assert start('blocked','c',now=3505) == '0\nrolling\n41695'
        assert start('other','d',now=3505) == '1\nok'
        assert finish('other','d') == '1'
        assert start('boundary','c',now=45200) == '1\nok'
        assert finish('boundary','c') == '1'
        cmd('SET','global-budget',999999999999)
        assert start('no-global-cap','e',now=45201) == '1\nok'
        assert finish('no-global-cap','e') == '1'
        assert cmd('EXISTS','global') == '0'
        # A /64 shares a wide short-window quota, not the five-tests/12h quota.
        for i in range(20):
            t='prefix-'+str(i); s='addr-'+str(i)
            assert start(t,s,now=50000,prefix='network:',network='shared64')=='1\nok'
            assert finish(t,s,prefix='network:')=='1'
        assert '\nprefix\n' in start('prefix-block','next',50000,'network:','shared64')
        assert start('prefix-other','new',50000,'network:','other64')=='1\nok'
        assert finish('prefix-other','new',prefix='network:')=='1'
        assert start('prefix-expire','next',50300,'network:','shared64')=='1\nok'
        assert finish('prefix-expire','next',prefix='network:')=='1'

        # Sixteen actual Redis clients cannot reserve more than eight downloads.
        assert start('slots-token','slot-source',now=60000,prefix='slot:')=='1\nok'
        def reserve(i,direction='download',now=60000):
            return cmd('EVAL',script,6,'slot:session:slots-token','slot:global','slot:bytes:'+direction,
                       'slot:phase:'+direction,'slot:inflight','slot:direction:'+direction,
                       'slots-token','slot-source',100,100000,60,now,12 if direction=='download' else 14,
                       'request-'+str(i),25,12,8 if direction=='download' else 4,time.time()+30)
        with ThreadPoolExecutor(max_workers=16) as pool:
            answers=list(pool.map(reserve,range(16)))
        assert answers.count('1')==8 and answers.count('-1')==8
        assert cmd('GET','slot:bytes:download')=='800', 'Rejected work must not charge bytes'
        for i in range(4): assert reserve(100+i,'upload')=='1'
        assert reserve(104,'upload')=='-1'
        release=(root/'request-finish.lua').read_text()
        assert cmd('EVAL',release,2,'slot:inflight','slot:direction:download','foreign-id')=='1'
        assert cmd('ZCARD','slot:inflight')=='12'
        assert finish('slots-token','slot-source',prefix='slot:')=='1'
        assert cmd('ZCARD','slot:inflight')=='12', 'Session finish cannot free blocked old I/O slots'
        assert start('new-token','new-source',60001,prefix='slot:')=='1\nok'
        def reserve_new(now):
            return cmd('EVAL',script,6,'slot:session:new-token','slot:global','slot:newbytes','slot:newphase',
                       'slot:inflight','slot:new-direction','new-token','new-source',100,100000,60,now,12,'new-request',25,12,8,time.time()+30)
        assert reserve_new(60001)=='-1'
        assert reserve_new(60025)=='1', 'Expired old request leases must recover capacity'
        assert cmd('ZCARD','slot:inflight')=='1'
        assert cmd('EVAL',release,2,'slot:inflight','slot:new-direction','new-request')=='1'
        assert cmd('ZCARD','slot:inflight')=='0'
        for i in range(30):
            t='global-'+str(i);s='src-'+str(i)
            assert start(t,s,70000,'cap:','network-'+str(i))=='1\nok'
            assert finish(t,s,prefix='cap:')=='1'
        assert '\nglobal\n' in start('denied','denied',70000,'cap:','another')
        assert cmd('EXISTS','cap:rolling:denied')=='0'
        assert start('window-recovered','recovered',70300,'cap:','another')=='1\nok'
        assert finish('window-recovered','recovered',prefix='cap:')=='1'
        for i in range(120):
            t='hour-'+str(i);s='src-'+str(i);now=80000+(i//20)*301
            assert start(t,s,now,'hour:','campus64')=='1\nok'
            assert finish(t,s,prefix='hour:')=='1'
        assert '\nprefix\n' in start('hour-denied','next',81806,'hour:','campus64')
        assert start('hour-other','other',81806,'hour:','different64')=='1\nok'
        assert finish('hour-other','other',prefix='hour:')=='1'
        assert start('hour-boundary','next',83600,'hour:','campus64')=='1\nok'
        assert finish('hour-boundary','next',prefix='hour:')=='1'
        print('PASS: atomic admission, source/rolling/prefix/global quotas, payload leases/races, owner release, deadline/bytes and crash lease recovery; no daily cap')
    finally:
        process.terminate()
        process.wait()
