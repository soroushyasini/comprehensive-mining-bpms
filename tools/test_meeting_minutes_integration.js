'use strict';
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const crypto=require('node:crypto');
const base=process.env.EMCORE_MINUTES_TEST_URL || 'http://127.0.0.1:33382';
if(!/^http:\/\/127\.0\.0\.1:33382$/.test(base))throw new Error('Disposable loopback fixture only.');
let checks=0;
function check(condition,label){assert.ok(condition,label);checks++;}
function sql(statement){return execFileSync('docker',['exec','emcore-minutes-test-db','mysql','-uroot','-pminutes-fixture-only','--default-character-set=utf8mb4','--batch','--skip-column-names','emcore_minutes_fixture','-e',statement],{encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();}
function fileCount(){return execFileSync('docker',['exec','emcore-minutes-test-api','find','/tmp/minutes-private','-type','f'],{encoding:'utf8'}).trim().split('\n').filter(Boolean).length;}
const id=()=>crypto.randomBytes(16).toString('hex');
function client(actor){let cookie='',token='';return{
  async call(action,payload={},status=200,csrf=true){
    const response=await fetch(base+'/emcore_api/emcore_meeting_minutes.php',{method:'POST',headers:{'X-Minutes-Fixture-Actor':actor,...(cookie?{Cookie:cookie}:{}),...(csrf && token?{'X-CSRF-Token':token}:{})},body:payload instanceof FormData?payload:new URLSearchParams({...payload,action})});
    const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];
    const body=await response.json();assert.ok((Array.isArray(status)?status:[status]).includes(response.status),action+': status '+response.status+' '+JSON.stringify(body));checks++;if(body.csrf_token)token=body.csrf_token;return body;
  },
  async file(action,meeting,name,content,extra={},status=201){const f=new FormData();Object.entries({action,id:meeting.id,lock_version:meeting.lock_version,file_role:'scan',request_id:id(),...extra}).forEach(([k,v])=>f.append(k,String(v)));f.append('file',new Blob([content],{type:'application/pdf'}),name);return this.call(action,f,status);},
  async download(fileId,status=200){const r=await fetch(base+'/emcore_api/emcore_meeting_minutes.php',{method:'POST',headers:{'X-Minutes-Fixture-Actor':actor,Cookie:cookie},body:new URLSearchParams({action:'download_file',file_id:String(fileId)})});assert.equal(r.status,status);checks++;return r;}
};}
const participant=(uid,extra={})=>({usr_uid:uid,name_snapshot:'نام ارسالی نباید مرجع باشد',attendance:'present',is_chair:1,is_secretary:1,...extra});
function managed(company=1,date='1405/07/12',overrides={}){return{company_id:company,record_origin:'managed',meeting_number:'',request_id:id(),title:'جلسه آزمون',meeting_date_fa:date,start_time:'09:00',end_time:'10:30',ends_next_day:0,agenda:'بررسی عملیات',notes:'',participants:JSON.stringify([participant('1'.repeat(32))]),...overrides};}
function updateData(m,overrides={}){return{id:m.id,lock_version:m.lock_version,title:m.title,meeting_date_fa:m.meeting_date_fa || '',start_time:(m.start_time || '').slice(0,5),end_time:(m.end_time || '').slice(0,5),ends_next_day:m.ends_next_day,agenda:m.agenda || '',notes:m.notes || '',participants:JSON.stringify(m.participants),...overrides};}
const pdf=Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
(async()=>{
  const admin=client('admin'),clerk=client('clerk'),reader=client('reader'),creator=client('creator'),outsider=client('outsider');
  await client('anonymous').call('list',{},401);await client('inactive').call('list',{},401);await outsider.call('list',{},403);
  const look=await admin.call('lookups');check(look.data.companies.filter(c=>c.code).length===8,'eight deterministic codes');check(look.data.storage_ready,'private storage ready');
  check(look.data.release.api==='2026-10-04.2' && look.data.release.domain===look.data.release.api && look.data.release.optional_meeting_times===true,'active API and helper release identified');
  for(const c of [clerk,reader,creator])await c.call('lookups');
  await clerk.call('create',managed(),403,false);await reader.call('create',managed(),403);
  await clerk.call('create',managed(9),422);
  await admin.call('get',{'id[]':'1'},422);
  await clerk.call('create',managed(1,'1405/07/12',{'ends_next_day[]':'1'}),422);
  // Production workspaces may have no SQL calendar routine. Preview, create,
  // edits and date filters must all use the validated server-side PHP calendar.
  sql('DROP FUNCTION shamsi_slash_to_gregorian_date');
  check(sql("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='shamsi_slash_to_gregorian_date'")==='0','SQL calendar routine absent during archive tests');
  const preview1=await admin.call('number_preview',{company_id:1,meeting_date_fa:'1405/07/12'});
  const preview2=await admin.call('number_preview',{company_id:1,meeting_date_fa:'۱۴۰۵/۰۷/۱۲'});
  check(preview1.data.meeting_number===preview2.data.meeting_number && preview1.data.meeting_number==='EMIDCO/1405/0001','preview never reserves');
  const payload=managed(1,'۱۴۰۵/۰۷/۱۲',{start_time:'۰۹:۰۰',end_time:'۱۰:۳۰'});let first=(await clerk.call('create',payload,201)).data;
  check(first.meeting_number==='EMIDCO/1405/0001','first atomic number');check(first.participants[0].name_snapshot==='clerk آزمایشی','server derives user name');
  const replay=(await clerk.call('create',payload)).data;check(replay.id===first.id,'create replay has same record');await clerk.call('create',{...payload,title:'changed'},409);
  await clerk.call('set_company_code',{company_id:9,code:'NEW'},403);
  await admin.call('set_company_code',{company_id:1,code:'CHANGED'},409);
  await admin.call('set_company_code',{company_id:9,code:'KGA'},409);check(sql('SELECT code FROM emcore_minutes_company_codes WHERE company_id=2')==='KGA','code conflict never overwrites another company');
  await admin.call('set_company_code',{company_id:9,code:'NEW'});
  const legacyPayload={company_id:1,record_origin:'legacy',meeting_number:'۴۳',request_id:id(),title:'جلسه قدیمی',participants:'[]'};
  let legacy=(await clerk.call('create',legacyPayload,201)).data;check(legacy.meeting_number==='۴۳' && legacy.meeting_date_fa===null && Number(legacy.metadata_complete)===0,'legacy keeps unknown metadata');
  await clerk.call('create',{...legacyPayload,request_id:id(),meeting_number:' 4 3 '},409);
  await clerk.call('create',{...legacyPayload,request_id:id(),meeting_number:'‌'},422);
  await clerk.call('create',{...legacyPayload,company_id:2,request_id:id(),meeting_number:'43'},201);
  await clerk.call('create',{...legacyPayload,company_id:2,request_id:id(),meeting_number:'KGA/1404/0001'},201);
  const occupied=await admin.call('number_preview',{company_id:2,meeting_date_fa:'1404/01/01'});check(occupied.data.meeting_number==='KGA/1404/0002','preview skips legacy spelling without consuming a counter');
  const afterOccupied=(await clerk.call('create',managed(2,'1404/01/01'),201)).data;check(afterOccupied.meeting_number==='KGA/1404/0002','modern issue skips an occupied legacy number');
  check(sql('SELECT next_sequence FROM emcore_minutes_counters WHERE company_id=1 AND jalali_year=1405')==='2','legacy does not consume modern sequence');
  await clerk.call('create',managed(1,'1404/12/30'),422);await clerk.call('create',managed(1,'1405/07/31'),422);
  const leap=(await clerk.call('create',managed(1,'1403/12/30'),201)).data;check(leap.meeting_date_en==='2025-03-20','leap Esfand conversion');
  const year=(await clerk.call('create',managed(1,'1406/01/01'),201)).data;check(year.meeting_number==='EMIDCO/1406/0001','independent years');
  sql('INSERT INTO emcore_minutes_counters (company_id,jalali_year,next_sequence) VALUES (2,1406,10000)');
  const wide=(await clerk.call('create',managed(2,'1406/01/01'),201)).data;check(wide.meeting_number==='KGA/1406/10000','sequence continues beyond four digits');
  const company=(await clerk.call('create',managed(2),201)).data;check(company.meeting_number==='KGA/1405/0001','independent companies');
  await clerk.call('create',managed(1,'1405/07/12',{end_time:'08:00'}),422);
  const overnight=(await clerk.call('create',managed(1,'1405/07/12',{start_time:'23:30',end_time:'00:10',ends_next_day:1}),201)).data;check(Number(overnight.ends_next_day)===1,'overnight accepted');
  await clerk.call('create',managed(1,'1405/07/12',{participants:JSON.stringify([participant('1'.repeat(32)),participant('1'.repeat(32),{attendance:'absent',is_chair:0,is_secretary:0})])}),422);
  await clerk.call('create',managed(1,'1405/07/12',{participants:JSON.stringify([participant('1'.repeat(32),{attendance:'absent'})])}),422);
  const external={usr_uid:null,name_snapshot:'مهمان بیرونی',organization_snapshot:'شرکت مهمان',attendance:'present',is_chair:1,is_secretary:1};
  const guest=(await clerk.call('create',managed(9,'1405/07/12',{participants:JSON.stringify([external])}),201)).data;check(guest.participants[0].person_key.startsWith('e:'),'external participant key');
  let changed=(await clerk.call('update',updateData(first,{title:'<img src=x onerror=alert(1)>',meeting_date_fa:'1406/01/01'}))).data;
  check(changed.meeting_number===first.meeting_number && changed.meeting_date_en==='2027-03-21','date edit keeps issued number');
  await clerk.call('update',updateData(first),409);await clerk.call('update',updateData(changed,{company_id:2}),422);
  await reader.call('update',updateData(changed),403);await creator.call('upload_file',{id:first.id},403);
  sql("UPDATE USERS SET USR_FIRSTNAME='نام جدید',USR_STATUS='INACTIVE' WHERE USR_UID='11111111111111111111111111111111'");
  changed=(await admin.call('update',updateData(changed,{notes:'اصلاح پس از غیرفعال شدن کاربر'}))).data;
  check(changed.participants[0].name_snapshot==='clerk آزمایشی','historical inactive user snapshot retained');
  await admin.call('create',managed(),422);
  sql("UPDATE USERS SET USR_FIRSTNAME='clerk',USR_STATUS='ACTIVE' WHERE USR_UID='11111111111111111111111111111111'");
  const request=id();let scan=(await admin.file('upload_file',changed,'صورت جلسه.pdf',pdf,{request_id:request}));changed=scan.data;
  check(changed.scan_status==='archived','scan completes archive');check(!JSON.stringify(changed).includes('storage_path'),'no storage path in JSON');
  const repeated=await admin.file('upload_file',changed,'صورت جلسه.pdf',pdf,{request_id:request},200);check(repeated.file_id===scan.file_id && repeated.data.lock_version===changed.lock_version,'upload replay does not duplicate or bump version');
  await admin.file('upload_file',changed,'other.pdf',pdf,{request_id:request},409);
  await admin.file('upload_file',changed,'fake.pdf',Buffer.from('<?php echo 1;'),{},422);
  await admin.file('upload_file',changed,'fake.png',pdf,{},422);
  await admin.file('upload_file',changed,'empty.pdf',Buffer.alloc(0),{},422);
  await admin.file('upload_file',changed,'oversized.pdf',Buffer.alloc(52428801),{},422);
  const attached=(await admin.file('upload_file',legacy,'پیوست.pdf',pdf,{file_role:'attachment'})).data;legacy=attached;
  check(legacy.scan_status==='awaiting_scan','attachment is not scan');
  legacy=(await admin.file('upload_file',legacy,'قدیمی.pdf',pdf)).data;check(legacy.scan_status==='archived' && Number(legacy.metadata_complete)===0,'scan and metadata independent');
  const oldFile=scan.file_id;const newer=Buffer.concat([pdf,Buffer.from('\n% replacement')]);
  await admin.file('replace_file',changed,'اصلاح.pdf',newer,{file_id:oldFile,replacement_reason:''},422);
  changed=(await admin.file('replace_file',changed,'اصلاح.pdf',newer,{file_id:oldFile,replacement_reason:'اسکن واضح‌تر'})).data;
  check(changed.files.some(f=>Number(f.id)===oldFile && f.superseded_at),'old scan retained');
  const download=await reader.download(oldFile);check(Buffer.from(await download.arrayBuffer()).equals(pdf),'reader can download historical version');
  check(Number(sql('SELECT COUNT(*) FROM emcore_minutes_download_log'))===1,'download logged');
  const storagePath=sql('SELECT storage_path FROM emcore_minutes_files WHERE id='+oldFile);
  check(/^meetings\/\d+\/[a-f0-9]{32}\.pdf$/.test(storagePath),'random storage filename');
  execFileSync('docker',['exec','emcore-minutes-test-api','php','-r','file_put_contents($argv[1],"tampered");','/tmp/minutes-private/'+storagePath]);
  await reader.download(oldFile,409);
  execFileSync('docker',['exec','emcore-minutes-test-api','php','-r','file_put_contents($argv[1],base64_decode($argv[2]));','/tmp/minutes-private/'+storagePath,pdf.toString('base64')]);
  const activeScan=changed.files.find(f=>f.file_role==='scan' && !f.superseded_at);
  await clerk.call('delete_file',{id:changed.id,file_id:activeScan.id,lock_version:changed.lock_version},403);
  changed=(await admin.call('delete_file',{id:changed.id,file_id:activeScan.id,lock_version:changed.lock_version})).data;
  check(changed.scan_status==='awaiting_scan','deleting final active scan resets status');await reader.download(activeScan.id,404);
  const beforeCounter=sql('SELECT next_sequence FROM emcore_minutes_counters WHERE company_id=1 AND jalali_year=1405');
  const beforeRecords=sql('SELECT COUNT(*) FROM emcore_meeting_minutes');sql('UPDATE fixture_flags SET fail_audit=1');
  await admin.call('create',managed(),500);check(sql('SELECT COUNT(*) FROM emcore_meeting_minutes')===beforeRecords,'audit failure rolls back record');
  check(sql('SELECT next_sequence FROM emcore_minutes_counters WHERE company_id=1 AND jalali_year=1405')===beforeCounter,'audit failure rolls back counter');
  const beforeFiles=fileCount();await admin.file('upload_file',changed,'失敗.pdf',pdf,{},500);check(fileCount()===beforeFiles,'failed audited upload removes physical file');sql('UPDATE fixture_flags SET fail_audit=0');
  const a=client('parallel'),b=client('parallel'),c=client('admin');await Promise.all([a.call('lookups'),b.call('lookups'),c.call('lookups')]);
  const concurrent=await Promise.all([a.call('create',managed(1,'1405/07/13'),201),b.call('create',managed(1,'1405/07/13'),201),c.call('create',managed(1,'1405/07/13'),201)]);
  check(new Set(concurrent.map(r=>r.data.meeting_number)).size===3,'concurrent numbers unique');
  const duplicate=managed(2,'1405/07/13');const results=await Promise.all([a.call('create',duplicate,[200,201]),b.call('create',duplicate,[200,201])]);
  check(results[0].data.id===results[1].data.id,'concurrent replay same record');
  const filtered=await admin.call('list',{company_id:1,scan_status:'archived',metadata_complete:0,page_size:1});check(filtered.summary.total==='1' || Number(filtered.summary.total)===1,'summary follows all filters');
  check(filtered.data[0].id===legacy.id,'filtered page matches summary');
  const person=await admin.call('list',{person_key:guest.participants[0].person_key});check(person.data.length===1 && person.data[0].id===guest.id,'external person filter');
  const search=await admin.call('list',{search:'۴۳',company_id:1});check(search.data[0].id===legacy.id,'Persian number search');
  await admin.call('list',{date_from:'1406/01/01',date_to:'1405/01/01'},422);
  const ordered=await admin.call('list',{sort_by:'meeting_number',sort_order:'asc',page_size:2});check(ordered.data.length===2 && ordered.pagination.total_pages>1,'server pagination and sorting');
  await admin.call('list',{sort_by:'id; DROP TABLE USERS'},422);
  await admin.call('delete',{id:legacy.id,lock_version:legacy.lock_version});await reader.download(legacy.files.find(f=>f.file_role==='scan').id,404);
  await admin.call('create',{...legacyPayload,request_id:id()},409);
  const companySnapshot=guest.company_name_snapshot;sql("UPDATE emcore_companies SET name_fa='نام تازه',deleted_at=NOW() WHERE id=9");
  check((await admin.call('get',{id:guest.id})).data.company_name_snapshot===companySnapshot,'deleted/renamed company does not erase archive');
  const codesBefore=sql('SELECT GROUP_CONCAT(code ORDER BY company_id) FROM emcore_minutes_company_codes');
  execFileSync('docker',['exec','emcore-minutes-test-api','php','-r',"$d=new PDO(getenv('EMCORE_DB_DSN'),getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'));$d->exec(file_get_contents('database/migrations/013_emcore_meeting_minutes.sql'));"],{stdio:'pipe'});
  check(sql('SELECT GROUP_CONCAT(code ORDER BY company_id) FROM emcore_minutes_company_codes')===codesBefore,'migration rerun preserves settings');
  const withoutTimes=(await clerk.call('create',managed(2,'1408/01/01',{start_time:'',end_time:''}),201)).data;
  check(withoutTimes.start_time===null && withoutTimes.end_time===null && Number(withoutTimes.metadata_complete)===1,'both optional times stored NULL without metadata penalty');
  const startOnly=(await clerk.call('create',managed(2,'1408/01/01',{start_time:'۰۹:۰۰',end_time:''}),201)).data;
  check(startOnly.start_time==='09:00:00' && startOnly.end_time===null,'start-only meeting accepted');
  const endOnly=(await clerk.call('create',managed(2,'1408/01/01',{start_time:'',end_time:'۱۰:۳۰'}),201)).data;
  check(endOnly.start_time===null && endOnly.end_time==='10:30:00','end-only meeting accepted');
  const cleared=(await clerk.call('update',updateData(startOnly,{start_time:'',end_time:''}))).data;
  check(cleared.start_time===null && cleared.end_time===null && Number(cleared.metadata_complete)===1,'edit can remove times without changing metadata quality');
  await clerk.call('create',managed(2,'1408/01/01',{start_time:'25:00'}),422);
  await clerk.call('create',managed(2,'1408/01/01',{start_time:'',end_time:'',ends_next_day:1}),422);
  await clerk.call('create',managed(2,'1408/01/01',{start_time:'09:00',end_time:'',ends_next_day:1}),422);
  console.log(`Meeting minutes integration: ${checks} checks passed (PHP 8.2 / MySQL 8.4).`);
})().catch(error=>{try{sql('UPDATE fixture_flags SET fail_audit=0');}catch(_){}console.error(error);process.exitCode=1;});
