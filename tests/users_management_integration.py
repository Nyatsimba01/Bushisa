"""Run only against a LOCAL disposable MySQL/MariaDB server.
Creates a uniquely named test database; never reads or changes the application DB.
Requires PHP 8 + pdo_mysql + mbstring and Python 3. TEST_DB_* configure the server.
PHP_BIN and PHP_INI optionally select PHP. No fixture is used by the application.
"""
import csv, io, os, re, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path

root = Path(__file__).resolve().parents[1]
env = os.environ.copy()
env.update(DB_HOST=os.getenv('TEST_DB_HOST', '127.0.0.1'), DB_PORT=os.getenv('TEST_DB_PORT', '3306'),
           DB_USER=os.getenv('TEST_DB_USER', 'root'), DB_PASS=os.getenv('TEST_DB_PASS', ''),
           DB_NAME='bushisa_users_test_' + uuid.uuid4().hex[:12])
if env['DB_HOST'] not in ('127.0.0.1', 'localhost', '::1'):
    raise SystemExit('Tests require a local disposable database server.')
php = [os.getenv('PHP_BIN', 'php')]
if os.getenv('PHP_INI'): php += ['-c', os.environ['PHP_INI']]
with socket.socket() as s:
    s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]
base = f'http://127.0.0.1:{port}/admin/users.php'
connection = """<?php
$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'),getenv('DB_USER'),getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=getenv('DB_NAME');
if(!preg_match('/^bushisa_users_test_[a-f0-9]{12}$/D',$db))exit(1);
"""
def req(q='',who=1,data=None):
    r=urllib.request.Request(base+q,headers={'Cookie':f'PHPSESSID=testusers{who}'},data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try:
        with urllib.request.urlopen(r) as x:return x.status,x.read().decode(),x.headers
    except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers

with tempfile.TemporaryDirectory(prefix='bushisa-users-') as tmp:
    php += ['-d', 'session.save_path='+tmp]
    def execute(code):
        path=Path(tmp)/'fixture.php';path.write_text(code)
        subprocess.run(php+[str(path)],env=env,check=True,stdout=subprocess.DEVNULL)
    # CREATE (without IF NOT EXISTS) ensures we only ever clean up our own database.
    execute(connection+'$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");')
    server=None
    try:
        execute(connection+'$p->exec("USE `$db`");'+'$p->exec("CREATE TABLE users (id INT PRIMARY KEY, nust_student_id VARCHAR(20), email VARCHAR(100), password_hash VARCHAR(255), role VARCHAR(20), is_verified INT, is_suspended INT, created_at DATETIME)");\n$p->exec(\'CREATE TABLE profiles (user_id INT PRIMARY KEY, display_name VARCHAR(100), anon_handle VARCHAR(100))\');\n$u=$p->prepare(\'INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?)\');\n$pr=$p->prepare(\'INSERT INTO profiles VALUES (?, ?, ?)\');\n$names=[\'Test administrator\',\'Test student\',\'Test moderator\',\'Test suspended admin\',\'Test pending student\',\'=SUM(1,2)\'];\nforeach($names as $k=>$name){$id=$k+1;$u->execute([$id,\'N00\'.$id,\'private\'.$id.\'@students.nust.ac.zw\',\'SECRET_HASH\', [1=>\'admin\',3=>\'moderator\',4=>\'admin\'][$id]??\'student\',$id%2,$id===4?1:0,\'2026-10-01 12:00:00\']);$pr->execute([$id,$name,\'SECRET_ANON\'.$id]);}\nfor($id=1;$id<=6;$id++){session_id(\'testusers\'.$id);session_start();$_SESSION=[\'user_id\'=>$id,\'role\'=>\'admin\',\'last_activity\'=>time()];session_write_close();}\n\n')
        server=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for attempt in range(50):
            try:
                req();break
            except urllib.error.URLError:time.sleep(.1)
        s,h,headers=req(); assert s==200 and 'no-store' in headers['Cache-Control'];assert 'SECRET_' not in h and 'private1@' not in h
        assert re.findall(r'class="admin-metric__value">(\d+)',h)==['6','3','1','2']
        for who in [2,3,4]:
         for q in ['', '?account=1', '?export=csv']:assert req(q,who)[0]==403
        assert req('?export=csv')[0]==405
        assert req(data={'export':'csv','csrf_token':'wrong'})[0]==403
        token=re.search(r'name="csrf_token" value="([^"]+)"',h)[1]
        s,c,_=req(data={'export':'csv','csrf_token':token});assert s==200
        rows=list(csv.reader(io.StringIO(c)));assert len(rows)==7 and any(r[1]=="'=SUM(1,2)" for r in rows[1:])
        assert 'SECRET_' not in c and 'private1@' not in c
        for q,count in [('role=admin',2),('status=suspended',1),('verification=verified',3),('q=Test+student',1),('q=%25',0),('q=%27+OR+1%3D1+--',0),('q=N002',1),('q=%23002',1),('q=private2%40students.nust.ac.zw',1),('role=student&verification=pending',2)]:
         s,h,_=req('?'+q);assert s==200 and f'{count} ACCOUN' in h,(q,s)
        assert req('?account=99999')[0]==404
        assert req('?account[]=1')[0]==404
        s,h,_=req('?account=2');assert s==200 and 'ACCOUNT DETAILS' in h and 'private2@' not in h
        print('PASS: statistics; masked data; student/moderator/suspended denial; CSRF; read-only details; missing accounts; filters; literal wildcards; SQL injection; CSV formula escaping.')

        execute(connection+'$p->exec("USE `$db`");'+'$p->beginTransaction();\n$s=$p->prepare("INSERT INTO users VALUES (?, ?, ?, \'SECRET_HASH\', \'student\', 0, 0, \'2026-10-01 12:00:00\')");\nfor($id=7;$id<=1007;$id++)$s->execute([$id,\'N\'.$id,\'private\'.$id.\'@students.nust.ac.zw\']);\n$p->commit();\n')
        s,h,_=req();token=re.search(r'name="csrf_token" value="([^"]+)"',h)[1]
        s,c,_=req(data={'export':'csv','csrf_token':token});rows=list(csv.reader(io.StringIO(c)));assert len(rows)==1008,len(rows)
        s,h,_=req('?page=999999');assert '1001–1007 of 1007' in h
        s,c,_=req('?role=admin',data={'export':'csv','csrf_token':token});assert len(list(csv.reader(io.StringIO(c))))==3
        print('PASS: 1,007 exported accounts, filtered export, last-page clamping, accounts without profiles.')
        execute(connection+'$p->exec("USE `$db`");$p->exec("UPDATE users SET role=\'student\' WHERE id=1");')
        assert req()[0]==403
        print('PASS: current database permissions override stale administrator sessions.')
    finally:
        if server:
            server.terminate();server.wait(timeout=10)
        execute(connection+'$p->exec("DROP DATABASE `$db`");')
