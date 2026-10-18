"""Integration checks using a unique disposable LOCAL database and private fixtures.
Never reads the application's database or evidence files. PHP_BIN/PHP_INI and
TEST_DB_* select local runtimes; BROWSER_TEST optionally checks the live layouts.
"""
import base64, os, re, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request, uuid
from pathlib import Path
root=Path(__file__).resolve().parents[1]
env=os.environ.copy()
env.update(DB_HOST=os.getenv('TEST_DB_HOST','127.0.0.1'),DB_PORT=os.getenv('TEST_DB_PORT','3306'),DB_USER=os.getenv('TEST_DB_USER','root'),DB_PASS=os.getenv('TEST_DB_PASS',''),DB_NAME='bushisa_content_test_'+uuid.uuid4().hex[:12])
if env['DB_HOST'] not in ['127.0.0.1','localhost','::1']: raise SystemExit('Use a local disposable database server.')
php=[os.getenv('PHP_BIN','php')]
if os.getenv('PHP_INI'):php+=['-c',os.environ['PHP_INI']]
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
base=f'http://127.0.0.1:{port}'
connection="""<?php
$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'),getenv('DB_USER'),getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=getenv('DB_NAME');if(!preg_match('/^bushisa_content_test_[a-f0-9]{12}$/D',$db))exit(1);
"""
def req(route='confessions.php',q='',who=1,data=None,binary=False):
    r=urllib.request.Request(base+'/admin/'+route+q,headers={'Cookie':f'PHPSESSID=testcontent{who}'},data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try:
        with urllib.request.urlopen(r) as x:return x.status,x.read() if binary else x.read().decode(),x.headers
    except urllib.error.HTTPError as e:return e.code,e.read() if binary else e.read().decode(),e.headers
with tempfile.TemporaryDirectory(prefix='bushisa-content-') as tmp:
    php+=['-d','session.save_path='+tmp]
    private=Path(tmp)/'evidence';private.mkdir();env['BUSHISA_EVIDENCE_DIR']=str(private)
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1cAAAAASUVORK5CYII=')
    (private/'sample.png').write_bytes(png)
    (private/'sample.pdf').write_bytes(b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n')
    (private/'evil.svg').write_text('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
    (private/'fake.png').write_text('<?php echo "PRIVATE_SECRET";')
    (private/'oversized.png').write_bytes(png+b'0'*(10*1024*1024))
    outside=Path(tmp)/'outside.png';outside.write_bytes(png)
    (private/'symlink.png').symlink_to(outside)
    def execute(code):
        path=Path(tmp)/'fixture.php';path.write_text(code)
        result=subprocess.run(php+[str(path)],env=env,text=True,capture_output=True)
        assert result.returncode==0,result.stdout+result.stderr
        return result.stdout
    execute(connection+'$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");')
    def sql(code):return execute(connection+'$p->exec("USE `$db`");'+code)
    server=None
    try:
        sql('''
$p->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role VARCHAR(20), is_suspended INT, nust_student_id VARCHAR(30), email VARCHAR(100), password_hash VARCHAR(255), is_verified INT DEFAULT 1, created_at DATETIME DEFAULT '2026-10-01 12:00:00') ENGINE=InnoDB");
$p->exec("INSERT INTO users(id,role,is_suspended) VALUES (1,'admin',0),(2,'student',0),(3,'moderator',0),(4,'admin',1),(5,'student',1),(6,'moderator',1),(7,'student',0)");
$p->exec("CREATE TABLE profiles (user_id INT PRIMARY KEY, display_name VARCHAR(100), anon_handle VARCHAR(100)) ENGINE=InnoDB");
$p->exec("INSERT INTO profiles VALUES (1,'Admin','PRIVATE_ANON'),(2,'PRIVATE_PERSON','PRIVATE_ANON'),(3,'Moderator','PRIVATE_ANON'),(4,'Admin suspended','PRIVATE_ANON'),(5,'Student suspended','PRIVATE_ANON'),(6,'Moderator suspended','PRIVATE_ANON')");
$p->exec("CREATE TABLE confessions (id INT PRIMARY KEY, author_id INT, body TEXT, is_flagged INT DEFAULT 0, created_at DATETIME DEFAULT '2026-10-01 12:00:00') ENGINE=InnoDB");
$s=$p->prepare('INSERT INTO confessions(id,author_id,body,is_flagged) VALUES (?,2,?,?)');
for($id=1;$id<=27;$id++)$s->execute([$id,$id===1?'<script>private-content</script>':($id===2?'100% literal_under':'Test confession '.$id),in_array($id,[2,3,4])?1:0]);
$p->exec("CREATE TABLE reports (id INT PRIMARY KEY, reporter_id INT, reported_user_id INT UNSIGNED NULL, confession_id INT NULL, target_type VARCHAR(40), target_id INT NULL, reason TEXT, status VARCHAR(30), evidence_path VARCHAR(255) NULL, created_at DATETIME DEFAULT '2026-10-01 12:00:00') ENGINE=InnoDB");
$p->exec("INSERT INTO reports(id,reporter_id,reported_user_id,confession_id,target_type,target_id,reason,status,evidence_path) VALUES (1,2,7,1,'confession',1,'First reason','pending',NULL),(2,2,5,NULL,'user',5,'Account reason','pending',NULL),(3,2,7,2,'confession',2,'Reviewed reason','reviewed',NULL),(4,2,7,3,'confession',3,'Dismissed reason','dismissed',NULL),(5,2,7,NULL,'user',7,'Attachment image','pending','sample.png'),(6,2,7,NULL,'user',7,'Attachment PDF','pending','sample.pdf')");
for($id=1;$id<=7;$id++){session_id('testcontent'.$id);session_start();$_SESSION=['user_id'=>$id,'role'=>'admin','last_activity'=>time()];session_write_close();}
''')
        with open(Path(tmp)/'server.log','w+') as log:
            server=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
            for _ in range(50):
                try:req();break
                except urllib.error.URLError:time.sleep(.1)
            status,h,headers=req();assert status==200,(status,h)
            assert 'PRIVATE_PERSON' not in h and 'PRIVATE_ANON' not in h
            assert 'no-store' in headers['Cache-Control'] and headers['Referrer-Policy']=='no-referrer'
            assert re.findall(r'class="metric-value">(\d+)',h)==['27','3','24','0']
            sql('$p->exec("INSERT INTO audit_log(user_id,action,ip_address) VALUES (1,\'delete_confession:99\',\'127.0.0.1\'),(1,\'admin_delete_confession:99\',\'127.0.0.1\'),(1,\'password_reset:PRIVATE_SECRET\',\'127.0.0.1\')");')
            assert re.findall(r'class="metric-value">(\d+)',req()[1])[-1]=='1'
            assert req()[1].count('class="report-row"')==20 and req(q='?page=2')[1].count('class="report-row"')==7
            for route in ['confessions.php','moderation.php','report-evidence.php']:
                for who in [2,4]:
                    assert req(route,who=who)[0]==403
                    assert req(route,who=who,data={'action':'unflag_confession'})[0]==403
            assert req(who=3)[0]==200
            for q,count in [('q=%25',1),('q=literal_',1),('q=%23001',1),('q=%27+OR+1%3D1',0),('state=flagged',3)]:
                assert req(q='?'+q)[1].count('class="report-row"')==count,q
            for q in ['?post=999','?post[]=1']:assert req(q=q)[0]==404
            h=req(q='?post=1')[1];assert '&lt;script&gt;private-content&lt;/script&gt;' in h and '<script>private-content' not in h
            assert 'PRIVATE_ANON' not in h and 'First reason' in h
            assert req('moderation.php')[1].count('class="report-row"')==3
            assert 'PRIVATE_SECRET' not in req('moderation.php','?view=activity')[1]
            assert req('moderation.php','?view=suspended&q=Student+suspended')[1].count('class="report-row"')==1
            def form(route,q,who=1):
                html=req(route,q,who)[1]
                names=['csrf_token','record_id','version','action']
                return {key:re.search('name="'+key+'" value="([^"]+)"',html)[1] for key in names}
            original=form('confessions.php','?post=1')
            assert req(data=dict(original,csrf_token='bad',confirm='yes'))[0]==403
            assert req(data=original)[0]==409
            assert req(data=dict(original,action='delete_confession',confirm='yes'))[0]==409
            assert req(data=dict(original,record_id='7',confirm='yes'))[0]==409
            assert req(data=dict(original,version='wrong',confirm='yes'))[0]==409
            sql('$p->exec("UPDATE confessions SET body=\'Changed content\' WHERE id=1");')
            assert req(data=dict(original,confirm='yes'))[0]==409
            sql('$p->exec("UPDATE confessions SET body=\'<script>private-content</script>\' WHERE id=1");')
            # Attachment endpoints only serve approved files and record access.
            for who in [1,3]:
                s,body,hd=req('report-evidence.php','?report=5',who,binary=True)
                assert s==200 and body==png and hd['Content-Type']=='image/png'
                assert 'no-store' in hd['Cache-Control'] and 'sandbox' in hd['Content-Security-Policy']
            assert 'report-evidence.php?report=5' in req('reports.php','?report=5')[1]
            assert 'sample.png' not in req('reports.php','?report=5')[1]
            s,body,hd=req('report-evidence.php','?report=6',binary=True)
            assert s==200 and hd['Content-Disposition'].startswith('attachment;') and body.startswith(b'%PDF')
            assert req('report-evidence.php','?report=5',data={})[0]==405
            for reference in ['../outside.png',str(outside),'evil.svg','fake.png','oversized.png','symlink.png','https://example.com/a.png','missing.png']:
                sql('$s=$p->prepare("UPDATE reports SET evidence_path=? WHERE id=5");$s->execute(['+repr(reference)+']);')
                s,body,_=req('report-evidence.php','?report=5');assert s==404,(reference,s,body)
                assert reference not in body
            sql('$p->exec("UPDATE reports SET evidence_path=\'uploads/evidence/sample.png\' WHERE id=5");')
            assert req('report-evidence.php','?report=5',binary=True)[1]==png
            assert req('report-evidence.php','?report[]=5')[0]==404
            if os.getenv('BROWSER_TEST'):
                subprocess.run(['node',os.environ['BROWSER_TEST']],env=dict(env,CONTENT_TEST_URL=base),check=True)
            # Confirmed reversible changes retain the post, report and user rows.
            fresh=form('confessions.php','?post=1');assert req(data=dict(fresh,confirm='yes'))[0]==200
            assert sql('echo $p->query("SELECT is_flagged FROM confessions WHERE id=1")->fetchColumn();')=='1'
            assert req(data=dict(fresh,confirm='yes'))[0]==409
            restore=form('moderation.php','?post=1',3)
            assert req('moderation.php',who=3,data=dict(restore,confirm='yes'))[0]==200
            assert sql('echo $p->query("SELECT is_flagged FROM confessions WHERE id=1")->fetchColumn();')=='0'
            account=form('moderation.php','?view=suspended&account=5')
            modtoken=re.search(r'name="csrf_token" value="([^"]+)"',req('moderation.php','?post=2',3)[1])[1]
            assert req('moderation.php',who=3,data=dict(account,csrf_token=modtoken,confirm='yes'))[0]==409
            assert req('moderation.php',data=dict(account,record_id=6,confirm='yes'))[0]==409
            assert req('moderation.php',data=dict(account,confirm='yes'))[0]==200
            assert sql('echo $p->query("SELECT is_suspended FROM users WHERE id=5")->fetchColumn();')=='0'
            assert sql('echo $p->query("SELECT COUNT(*) FROM confessions")->fetchColumn();')=='27'
            assert sql('echo $p->query("SELECT COUNT(*) FROM reports")->fetchColumn();')=='6'
            assert sql('echo $p->query("SELECT status FROM reports WHERE id=1")->fetchColumn();')=='pending'
            assert req('moderation.php','?view=activity')[1].count('class="report-row"')==3
            # Revocation is checked against current DB role rather than cached session.
            sql('$p->exec("UPDATE users SET role=\'student\' WHERE id=3");')
            assert req(who=3)[0]==403 and req('report-evidence.php','?report=5',3)[0]==403
            rollback=form('confessions.php','?post=7')
            sql('$p->exec("ALTER TABLE audit_log ADD COLUMN required_value INT NOT NULL");')
            assert req(data=dict(rollback,confirm='yes'))[0]==503
            assert sql('echo $p->query("SELECT is_flagged FROM confessions WHERE id=7")->fetchColumn();')=='0'
            # Legacy installations store post text in content instead of body.
            sql('$p->exec("ALTER TABLE confessions CHANGE body content TEXT");')
            assert '100% literal_under' in req(q='?post=2')[1]
            print('PASS: content totals, search, pagination, anonymity, role revocation, confirmations, tamper/stale rejection, restoration, activity, transactional rollback, private evidence types/paths and legacy schemas.')
    finally:
        if server:server.terminate();server.wait()
        execute(connection+'$p->exec("DROP DATABASE `$db`");')
