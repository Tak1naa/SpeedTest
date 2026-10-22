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
    def start(token, source='a', now=1000, prefix=''):
        return cmd('EVAL', (root/'session-start.lua').read_text(), 5,
                   prefix+'active:'+source, prefix+'recent:'+source, prefix+'rolling:'+source,
                   prefix+'session:'+token, prefix+'global', token, 2, 5, 60, 300, source, now, 43200)
    def finish(token, source='a', prefix=''):
        return cmd('EVAL', (root/'session-finish.lua').read_text(), 3,
                   prefix+'global',prefix+'active:'+source,prefix+'session:'+token,token,source)
    try:
        for _ in range(100):
            if pathlib.Path(socket).exists(): break
            time.sleep(.01)
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
        assert finish('one','b') == '0'
        assert cmd('GET','global') == 'one'
        assert finish('one') == '1'
        assert start('two',now=1100) == '1\nok'
        assert finish('two') == '1'
        assert start('three',now=1299) == '0\nrecent\n1'
        assert start('three',now=1301) == '1\nok'
        script=(root/'payload.lua').read_text()
        args=['session:three','global','bytes','phase','three','a',100,200,60,1301,12]
        assert cmd('EVAL',script,4,*args) == '1'
        assert cmd('EVAL',script,4,*args) == '1'
        assert cmd('EVAL',script,4,*args) == '0'
        args[-2]=1314
        assert cmd('EVAL',script,4,*args) == '0'
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
        print('PASS: 20-client atomic admission race, global exclusion, owner release, 5min and 12h sliding windows/boundary/source isolation, payload caps/deadline, no global daily cap')
    finally:
        process.terminate()
        process.wait()

