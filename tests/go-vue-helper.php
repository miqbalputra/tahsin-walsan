<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../includes/go_vue_helper.php';
require __DIR__.'/../includes/tahsin_surah_helper.php';
$checks=0;
function nvCheck(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
putenv('TAHSIN_NEXT_ENABLED=0');nvCheck(!goVueEnabled(),'Preview off by default');
putenv('TAHSIN_NEXT_ENABLED=1');nvCheck(goVueEnabled(),'Explicit opt in');
$query=goVueQuery(['search'=>'Ibu Contoh','scope'=>'all']);
nvCheck($query==='page=1&limit=25&search=Ibu%20Contoh&scope=all','Canonical query string');
foreach([['limit'=>101],['page'=>0],['scope'=>'write'],['search'=>['bad']],['extra'=>'yes'],['search'=>str_repeat('a',101)]] as $bad){try{goVueQuery($bad);throw new LogicException('Invalid query accepted');}catch(InvalidArgumentException $e){$checks++;}}
$signature=goVueSignature('0123456789abcdef0123456789abcdef',$query,7,'admin',1700000000);
$expected=hash_hmac('sha256',"v1\nGET\n/api/v1/families\npage=1&limit=25&search=Ibu%20Contoh&scope=all\n7\nadmin\n1700000000",'0123456789abcdef0123456789abcdef');
nvCheck(hash_equals($expected,$signature),'Signed identity includes exact request and query');
nvCheck($signature!==goVueSignature('0123456789abcdef0123456789abcdef',$query,8,'admin',1700000000),'Actor identity is bound');
nvCheck($signature!==goVueSignature('0123456789abcdef0123456789abcdef',$query,7,'ustadz',1700000000),'Role is bound');
putenv('TAHSIN_VUE_TEACHER_ENABLED=0');putenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS=3');nvCheck(!goVueTeacherEnabled(3),'Teacher renderer remains off independently of read preview');
putenv('TAHSIN_VUE_TEACHER_ENABLED=1');putenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS=');nvCheck(!goVueTeacherEnabled(3),'Teacher renderer requires explicit pilot groups');
putenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS=3, 5');nvCheck(goVueTeacherEnabled(3)&&goVueTeacherEnabled(5)&&!goVueTeacherEnabled(1),'Pilot is isolated per group');
putenv('TAHSIN_VUE_TEACHER_HALAQOH_IDS=3,wrong');nvCheck(!goVueTeacherEnabled(3),'Malformed pilot configuration fails closed');
$surahs=tahsinSurahNames();nvCheck(count($surahs)===114&&count(array_unique($surahs))===114&&$surahs[113]==='An-Nas','Both renderers retain all 114 surah suggestions');
putenv('TAHSIN_NEXT_TEACHER_READ_ENABLED=0');putenv('TAHSIN_NEXT_TEACHER_READ_HALAQOH_IDS=3');nvCheck(!goVueTeacherReadEnabled(3),'Teacher read requires independent opt in');
putenv('TAHSIN_NEXT_TEACHER_READ_ENABLED=1');putenv('TAHSIN_NEXT_TEACHER_READ_HALAQOH_IDS=');nvCheck(!goVueTeacherReadEnabled(3),'Teacher read requires explicit pilot list');
putenv('TAHSIN_NEXT_TEACHER_READ_HALAQOH_IDS=3, 5');nvCheck(goVueTeacherReadEnabled(3)&&!goVueTeacherReadEnabled(1),'Teacher read group isolation');
$teacherQuery=goVueTeacherQuery(['tanggal'=>'2026-10-18','halaqoh_id'=>'3']);nvCheck($teacherQuery==='halaqoh_id=3&tanggal=2026-10-18','Canonical teacher query');
nvCheck(goVueSignature('0123456789abcdef0123456789abcdef',$teacherQuery,7,'ustadz',1700000000,'/api/v1/teacher-session')!==goVueSignature('0123456789abcdef0123456789abcdef',$teacherQuery,7,'ustadz',1700000000),'Signature cannot move to another endpoint');
foreach([['halaqoh_id'=>3,'tanggal'=>'2026-02-30'],['halaqoh_id'=>'03','tanggal'=>'2026-10-18'],['halaqoh_id'=>[3],'tanggal'=>'2026-10-18'],['halaqoh_id'=>3,'tanggal'=>'2026-10-18','user_id'=>9]] as $bad){try{goVueTeacherQuery($bad);throw new LogicException('Invalid session query accepted');}catch(InvalidArgumentException $e){$checks++;}}
echo "PASS $checks Go/Vue PHP gateway assertions\n";
