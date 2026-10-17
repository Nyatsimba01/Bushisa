"""Local disposable database tests. Never connects to the application database.
PHP_BIN/PHP_INI and TEST_DB_* select local test runtimes. Optional BROWSER_TEST
runs a browser script while the isolated test application is available.
"""
import os, re, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path
root = Path(__file__).resolve().parents[1]
env = os.environ.copy()
env.update(DB_HOST=os.getenv('TEST_DB_HOST','127.0.0.1'), DB_PORT=os.getenv('TEST_DB_PORT','3306'), DB_USER=os.getenv('TEST_DB_USER','root'), DB_PASS=os.getenv('TEST_DB_PASS',''), DB_NAME='bushisa_reports_test_'+uuid.uuid4().hex[:12])
assert env['DB_HOST'] in ['127.0.0.1','localhost','::1'], 'Use a local disposable database server'
php=[os.getenv('PHP_BIN','php')]
if os.getenv('PHP_INI'): php+=['-c',os.environ['PHP_INI']]
with socket.socket() as s:
    s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}/admin/reports.php'
connection="""<?php
$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'),getenv('DB_USER'),getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=getenv('DB_NAME'); if(!preg_match('/^bushisa_reports_test_[a-f0-9]{12}$/D',$db))exit(1);
"""
def req(q='',who=1,data=None):
    r=urllib.request.Request(base+q,headers={'Cookie':f'PHPSESSID=testreports{who}'},data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try:
        with urllib.request.urlopen(r) as x: return x.status,x.read().decode(),x.headers
    except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
with tempfile.TemporaryDirectory(prefix='bushisa-reports-') as tmp:
    php+=['-d','session.save_path='+tmp]
    def execute(code):
        path=Path(tmp)/'fixture.php';path.write_text(code)
        result = subprocess.run(php+[str(path)],env=env,text=True,capture_output=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout
    execute(connection+'$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");')
    def sql(code):return execute(connection+'$p->exec("USE `$db`");'+code)
    server=None
    try:
        sql('''
$p->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role VARCHAR(20), is_suspended INT) ENGINE=InnoDB");
$p->exec("INSERT INTO users VALUES (1,'admin',0),(2,'student',0),(3,'moderator',0),(4,'admin',1),(5,'student',0),(6,'admin',0)");
$p->exec("CREATE TABLE confessions (id INT PRIMARY KEY, body TEXT, is_flagged INT DEFAULT 0) ENGINE=InnoDB");
$p->exec("INSERT INTO confessions VALUES (1,'<script>private-content</script>',0)");
$p->exec("CREATE TABLE reports (id INT PRIMARY KEY, reporter_id INT, reported_user_id INT UNSIGNED NULL, confession_id INT NULL, target_type VARCHAR(40), target_id INT NULL, reason TEXT, status VARCHAR(30), evidence_path VARCHAR(255) NULL, created_at DATETIME) ENGINE=InnoDB");
$s=$p->prepare("INSERT INTO reports VALUES (?,2,?,NULL,'user',NULL,?,'pending',NULL,'2026-10-01 12:00:00')");
for($id=1;$id<=25;$id++)$s->execute([$id,5,'Test reason '.$id]);
$p->exec("UPDATE reports SET confession_id=1,target_type='confession' WHERE id=1");
$p->exec("UPDATE reports SET reported_user_id=6 WHERE id=2");
$p->exec("UPDATE reports SET reported_user_id=1 WHERE id=3");
$p->exec("UPDATE reports SET reason='100% literal_under',evidence_path='/secret/evidence.png' WHERE id=4");
for($id=1;$id<=6;$id++){session_id('testreports'.$id);session_start();$_SESSION=['user_id'=>$id,'role'=>'admin','last_activity'=>time()];session_write_close();}
''')
        log=open(Path(tmp)/'server.log','w+')
        server=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
        for _ in range(50):
            try:req();break
            except urllib.error.URLError:time.sleep(.1)
        status,h,headers=req();assert status==200,(status,h)
        assert 'no-store' in headers['Cache-Control'] and headers['Referrer-Policy']=='no-referrer'
        assert re.findall(r'class="metric-value">(\d+)',h)==['25','0','0','25']
        assert h.count('class="report-row"')==20 and req('?page=2')[1].count('class="report-row"')==5
        for who in [2,4]:
            assert req(who=who)[0]==403
            assert req('?report=1',who)[0]==403
            assert req(who=who,data={'action':'reviewed'})[0]==403
        assert req(who=3)[0]==200
        for q,count in [('q=%25',1),('q=literal_',1),('q=%23001',1),('q=%27+OR+1%3D1',0),('status=reviewed',0)]:
            response=req('?'+q); assert response[1].count('class="report-row"')==count,(q,response[0],response[1][-1200:])
        assert req('?report=999')[0]==404 and req('?report[]=1')[0]==404
        h=req('?report=1')[1];assert '&lt;script&gt;private-content&lt;/script&gt;' in h and '<script>private-content' not in h
        assert '/secret/evidence' not in req('?report=4')[1]
        def action(rid,act,who=1,**extra):
            h=req('?report='+str(rid),who)[1]
            token=re.search(r'name="csrf_token" value="([^"]+)"',h)[1]
            return req('?report='+str(rid),who,dict(csrf_token=token,report_id=rid,action=act,expected_status='pending',**extra))
        assert req(data={'csrf_token':'bad','action':'reviewed','report_id':1})[0]==403
        assert action(1,'hide_confession')[0]==409
        assert action(2,'suspend_user',confirm='yes')[0]==409
        assert action(3,'suspend_user',confirm='yes')[0]==409
        assert action(5,'suspend_user',who=3,confirm='yes')[0]==409
        assert sql('echo $p->query("SELECT SUM(is_suspended) FROM users")->fetchColumn();')=='1'
        # Browser uses the same isolated records; no fixtures enter the application DB.
        if os.getenv('BROWSER_TEST'):
            subprocess.run(['node',os.environ['BROWSER_TEST']],env=dict(env,REPORTS_TEST_URL=base),check=True)
        assert action(1,'hide_confession',who=3,confirm='yes')[0]==200
        assert sql('echo $p->query("SELECT is_flagged FROM confessions WHERE id=1")->fetchColumn();')=='1'
        assert sql('echo $p->query("SELECT status FROM reports WHERE id=1")->fetchColumn();')=='action_taken'
        assert action(5,'suspend_user',confirm='yes')[0]==200
        assert sql('echo $p->query("SELECT is_suspended FROM users WHERE id=5")->fetchColumn();')=='1'
        assert action(6,'reviewed')[0]==200
        assert action(7,'dismiss')[0]==200
        # Stale/replayed decision cannot overwrite a completed report.
        token=re.search(r'name="csrf_token" value="([^"]+)"',req('?report=8')[1])[1]
        assert req(data=dict(csrf_token=token,report_id=6,expected_status='pending',action='dismiss'))[0]==409
        assert sql('echo $p->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();')=='4'
        assert re.findall(r'class="metric-value">(\d+)',req()[1])==['21','1','2','25']
        # An audit failure must roll back both account state and report status.
        sql('$p->exec("UPDATE users SET is_suspended=0 WHERE id=5");$p->exec("ALTER TABLE audit_log ADD COLUMN required_value INT NOT NULL");')
        assert action(8,'suspend_user',confirm='yes')[0]==503
        assert sql('echo $p->query("SELECT is_suspended FROM users WHERE id=5")->fetchColumn();')=='0'
        assert sql('echo $p->query("SELECT status FROM reports WHERE id=8")->fetchColumn();')=='pending'
        # Legacy content column is supported as well.
        sql('$p->exec("ALTER TABLE confessions CHANGE body content TEXT");')
        assert '&lt;script&gt;private-content' in req('?report=1')[1]
        print('PASS: permissions, statistics, search, pagination, private details, escaping, CSRF, confirmations, staff protection, moderation, audit, rollback, stale decisions and legacy schema.')
    finally:
        if server:server.terminate();server.wait()
        execute(connection+'$p->exec("DROP DATABASE `$db`");')
