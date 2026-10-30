// Run only against isolated localhost with the weekly reminder integration fixture.
const assert=require('node:assert/strict');
const base=process.env.TAHSIN_TEST_URL || 'http://127.0.0.1:18083';
if(new URL(base).hostname!=='127.0.0.1') throw new Error('Only isolated localhost staging is allowed');
let checks=0;
const check=(condition,message)=>{assert(condition,message);checks++;};
const csrf=html=>html.match(/name="csrf_token"[^>]*value="([^"]+)"/)?.[1];
(async()=>{
 const login=await fetch(base+'/login.php');
 const loginHtml=await login.text();
 const cookie=login.headers.getSetCookie().map(value=>value.split(';')[0]).join('; ');
 const signed=await fetch(base+'/login.php',{method:'POST',redirect:'manual',headers:{cookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({username:'guru_weekly',password:'TahsinTest-2026',csrf_token:csrf(loginHtml)})});
 check(signed.status===302,'Teacher fixture can log in');
 const cookie2=signed.headers.getSetCookie().map(value=>value.split(';')[0]).join('; ') || cookie;
 const formResponse=await fetch(base+'/ustadz/form-presensi.php?id=1&tanggal=2026-10-18',{headers:{cookie:cookie2}});
 const html=await formResponse.text();
 const token=csrf(html);
 const attendanceToken=html.match(/name="attendance_form_token" value="([a-f0-9]{32})"/)?.[1];
 const revision=id=>html.match(new RegExp('name="entry_revisions\\['+id+'\\]" value="([a-f0-9]{64})"'))?.[1];
 check(formResponse.status===200 && !!token,'Teacher form and CSRF are available');
 check(html.includes('peserta yang belum dipilih akan disimpan sebagai Alfa'),'Form explains explicit finalization');
 const before=await fetch(base+'/api/daily-reminder.php',{headers:{'X-API-Key':'tahsin-test-api'}}).then(r=>r.json());
 check(before.status==='success' && before.data[0].missing_presensi===4,'Current teacher with no action is reported');
 const monitor=await fetch(base+'/pantau-input.php',{headers:{cookie:cookie2}}).then(r=>r.text());
 check(monitor.includes('Guru Pekanan') && monitor.includes('Belum Diinput'),'Monitoring page displays the teacher who has no saved attendance');
 const blank=await fetch(base+'/ustadz/api-save-single.php',{method:'POST',headers:{cookie:cookie2,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:token,attendance_form_token:attendanceToken,entry_revision:revision(1),operation_id:'e'.repeat(32),halaqoh_id:'1',wali_santri_id:'1',tanggal:'2026-10-18'})});
 check(blank.status===422,'Blank autosave cannot generate Alfa');
 const completeH={csrf_token:token,attendance_form_token:attendanceToken,entry_revision:revision(1),operation_id:'e'.repeat(32),halaqoh_id:'1',wali_santri_id:'1',tanggal:'2026-10-18',status:'H',jenis_materi:'Iqro',jilid:'2',halaman_dari:'1',halaman_sampai:'2',hasil_talaqqi:'Lulus'};
 const saved=await fetch(base+'/ustadz/api-save-single.php',{method:'POST',headers:{cookie:cookie2,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(completeH)}).then(r=>r.json());
 check(saved.status==='success','Complete Hadir autosaves: '+JSON.stringify(saved));
 const partial=await fetch(base+'/api/daily-reminder.php',{headers:{'X-API-Key':'tahsin-test-api'}}).then(r=>r.json());
 check(partial.data[0].missing_presensi===3,'Partially filled teacher remains in report');
 const post=new URLSearchParams({csrf_token:token,attendance_form_token:attendanceToken,attendance_operation_id:'f'.repeat(32),tanggal:'2026-10-18'});
 for(let id=1;id<=4;id++) {
   post.set('entry_revisions['+id+']',id===1?saved.entry_revisions['1']:revision(id));
   post.set('presensi['+id+'][intent]','save');
 }
 for(const [key,value] of Object.entries(completeH))if(['status','jenis_materi','jilid','halaman_dari','halaman_sampai','hasil_talaqqi'].includes(key))post.set('presensi[1]['+key+']',value);
 const finalized=await fetch(base+'/ustadz/form-presensi.php?id=1&tanggal=2026-10-18',{method:'POST',headers:{cookie:cookie2,'Content-Type':'application/x-www-form-urlencoded'},body:post});
 const finalHtml=await finalized.text();
 check(finalized.status===200 && finalHtml.includes('3 peserta baru disimpan sebagai Alfa'),
   'Full form saves three previously unselected participants as Alfa: status='+finalized.status+' pageText='+finalHtml.replace(/<[^>]*>/g,' ').slice(-1200));
 const after=await fetch(base+'/api/daily-reminder.php',{headers:{'X-API-Key':'tahsin-test-api'}}).then(r=>r.json());
 check(after.status==='success' && after.count===0 && after.inspection_complete===true,'Teacher leaves report after all attendance is persisted');
 check(after.summary.attendance_counts.H===1 && after.summary.attendance_counts.A===3,'Complete report includes Hadir and three Alfa');
 check(after.summary.complete_participant_sessions===4,'Complete response proves coverage of four participants');
 const unauthorized=await fetch(base+'/api/daily-reminder.php');
 check(unauthorized.status===401,'Reminder API continues to require authentication');
 console.log('PASS '+checks+' isolated HTTP checks; no production writes or messages sent');
})().catch(error=>{console.error(error);process.exitCode=1});
