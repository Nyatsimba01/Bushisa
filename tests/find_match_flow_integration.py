"""Exercise the real PHP questionnaire and save endpoint in an isolated local DB.
Requires PHP/PDO MySQL, Python and Playwright. All fixtures are disposable.
"""
import json, os, socket, subprocess, tempfile, time, urllib.error, urllib.request, uuid
from pathlib import Path
root=Path(__file__).resolve().parents[1]
env=os.environ.copy()
env.update(DB_HOST=os.getenv('TEST_DB_HOST','127.0.0.1'),DB_PORT=os.getenv('TEST_DB_PORT','3306'),DB_USER=os.getenv('TEST_DB_USER','root'),DB_PASS=os.getenv('TEST_DB_PASS',''),DB_NAME='bushisa_flow_test_'+uuid.uuid4().hex[:12])
if env['DB_HOST'] not in ['127.0.0.1','localhost','::1']:raise SystemExit('Use a local disposable database server.')
php=[os.getenv('PHP_BIN','php')]
if os.getenv('PHP_INI'):php+=['-c',os.environ['PHP_INI']]
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}/Bushisa-Figma'
connection="""<?php
$p=new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'),getenv('DB_USER'),getenv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=getenv('DB_NAME');if(!preg_match('/^bushisa_flow_test_[a-f0-9]{12}$/D',$db))exit(1);
"""
with tempfile.TemporaryDirectory(prefix='bushisa-flow-') as tmp:
 php+=['-d','session.save_path='+tmp]
 public=Path(tmp)/'public';public.mkdir();(public/'Bushisa-Figma').symlink_to(root,target_is_directory=True)
 def execute(code):
  path=Path(tmp)/'fixture.php';path.write_text(code)
  result=subprocess.run(php+[str(path)],env=env,capture_output=True,text=True)
  assert result.returncode==0,result.stdout+result.stderr
  return result.stdout
 execute(connection+'$p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");')
 def sql(code):return execute(connection+'$p->exec("USE `$db`");'+code)
 server=None
 try:
  sql('''
$p->exec("CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(20),is_suspended INT DEFAULT 0)");
$p->exec("INSERT INTO users VALUES (1,'student',0),(2,'student',0)");
$p->exec("CREATE TABLE profiles(user_id INT PRIMARY KEY,display_name VARCHAR(100),profile_photo_path VARCHAR(255),faculty VARCHAR(100))");
$p->exec("INSERT INTO profiles VALUES (1,'Flow test',NULL,'Computing'),(2,'Other account',NULL,'Science')");
$p->exec("CREATE TABLE matches(id INT PRIMARY KEY,user_a_id INT,user_b_id INT,matched_at DATETIME)");
$p->exec("CREATE TABLE messages(id INT PRIMARY KEY,match_id INT,body TEXT,sent_at DATETIME)");
require '''+repr(str(root/'php/operational.php'))+'''; ensure_operational_schema($p);
$p->exec("INSERT INTO find_match_answers(user_id,question_key,answer_value) VALUES (2,'intent','Friendship first'),(1,'connection_pace','Taking my time'),(1,'conversation_opener','A funny story'),(1,'planning_style','Planning ahead'),(1,'football_team','Old team')");
for($i=1;$i<=2;$i++){session_id('testflow'.$i);session_start();$_SESSION=['user_id'=>$i,'role'=>'student','last_activity'=>time()];session_write_close();}
''')
  with open(Path(tmp)/'server.log','w+') as log:
   server=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','-t',str(public)],env=env,stdout=log,stderr=log)
   for _ in range(50):
    try:
     with urllib.request.urlopen(urllib.request.Request(base+'/matches.php',headers={'Cookie':'PHPSESSID=testflow1'})) as response:
      assert response.status==200
     break
    except urllib.error.URLError:time.sleep(.1)
   subprocess.run(['node',str(root/'tests/find_match_flow.cjs')],env=dict(env,FLOW_TEST_URL=base),check=True)
   rows=json.loads(sql('echo json_encode($p->query("SELECT user_id,question_key,answer_value FROM find_match_answers ORDER BY user_id,question_key")->fetchAll(PDO::FETCH_ASSOC));'))
   answers={r['question_key']:r['answer_value'] for r in rows if r['user_id']==1}
   assert answers['intent']=='Casual dating'
   assert answers['social_activity']=='Quiet moments' and answers['communication_style']=='A bit of both'
   assert answers['hangout_style']=='Small groups'
   assert answers['interests']=='Music' and answers['availability']=='Weekends'
   assert answers['music_styles']=='Amapiano'
   for key in ['connection_pace','conversation_opener','football_team','sports','sport_style','planning_style']:assert key not in answers,(key,answers)
   assert [r for r in rows if r['user_id']==2]==[{'user_id':2,'question_key':'intent','answer_value':'Friendship first'}]
   print('PASS: real per-phase persistence, skipped optional deletion, obsolete sports/team cleanup, reload/prefill and account isolation.')
 finally:
  if server:server.terminate();server.wait()
  execute(connection+'$p->exec("DROP DATABASE `$db`");')
