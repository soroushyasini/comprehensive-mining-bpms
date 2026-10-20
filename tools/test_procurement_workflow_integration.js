'use strict';
// Run only with tools/fixtures/procurement_router.php and its disposable schema.
const assert=require('node:assert/strict');
const crypto=require('node:crypto');
const fs=require('node:fs');
const base=process.env.EMCORE_FIXTURE_URL || 'http://127.0.0.1:33380';
if(base!=='http://127.0.0.1:33380')throw new Error('Isolated loopback fixture URL required.');
const clients={};let checks=0;
function check(value,message){checks++;assert.ok(value,message);}
function id(){return crypto.randomBytes(16).toString('hex');}
async function post(role,action,data={},expected=200,path='/emcore_api/emcore_procurement_notices.php'){
  const client=clients[role] || (clients[role]={});
  const headers={'x-emcore-fixture-actor':role};if(client.cookie)headers.cookie=client.cookie;if(client.csrf)headers['x-csrf-token']=client.csrf;
  const body=data instanceof FormData?data:new URLSearchParams({...data,...(action?{action}:{})});
  const response=await fetch(base+path,{method:'POST',headers,body});
  const cookie=response.headers.get('set-cookie');if(cookie)client.cookie=cookie.split(';')[0];
  const text=await response.text();let result;try{result=JSON.parse(text);}catch(e){throw new Error('Non-JSON fixture response: '+text.slice(0,400));}
  assert.equal(response.status,expected,action+': '+JSON.stringify(result));checks++;
  if(result.csrf_token)client.csrf=result.csrf_token;return result;
}
function fixture(role,data,expected=200){return post(role,null,data,expected,'/__fixture/case');}
async function get(role,notice){return (await post(role,'get',{id:notice})).data;}
async function main(){
  await post('anonymous','lookups',{},401);await post('operator','lookups');await post('manager','lookups');await post('outsider','lookups',{},403);
  const disabled=await post('operator','lookups',{},200,'/__fixture/disabled');
  check(disabled.workflow_permissions.enabled===false,'feature-off lookups retain legacy behavior');
  await post('operator','get',{id:2},200,'/__fixture/disabled');
  await post('operator','get_workflow',{id:1},404,'/__fixture/disabled');
  const savedCsrf=clients.operator.csrf;clients.operator.csrf='';await post('operator','create',{request_id:id()},403);clients.operator.csrf=savedCsrf;
  const original={notice_type:'tender',title:'فراخوان آزمایشی گردش کار',registered_on_fa:'1405/06/31',participation_status:'registered',currency:'تومان',request_id:id()};
  const created=await post('operator','create',original,201);const notice=created.id;
  check(created.data.participation_status==='registered','initial status forced');
  check(created.data.owner_usr_uid==='1'.repeat(32) && created.data.manager_usr_uid==='2'.repeat(32),'ownership separate from creator');
  const beforeFavorite=(await fixture('operator',{mode:'counts'})).data;
  await post('operator','set_favorite',{id:notice,is_favorite:'yes'},422);
  const favoriteCsrf=clients.operator.csrf;clients.operator.csrf='';await post('operator','set_favorite',{id:notice,is_favorite:'1'},403);clients.operator.csrf=favoriteCsrf;
  await post('operator','set_favorite',{id:2,is_favorite:'1'},404);
  await post('outsider','set_favorite',{id:notice,is_favorite:'1'},403);
  await post('operator','set_favorite',{id:notice,is_favorite:'1',usr_uid:'2'.repeat(32)});
  await post('operator','set_favorite',{id:notice,is_favorite:'1'});
  check(Number((await get('operator',notice)).is_favorite)===1 && Number((await get('manager',notice)).is_favorite)===0,'favorites belong to authenticated user only');
  const favorites=await post('operator','list',{favorites_only:'1',search:original.title,page_size:1});
  check(favorites.pagination.total===1 && favorites.data.length===1 && favorites.data[0].id==notice,'favorite search and pagination use full filtered dataset');
  await post('operator','set_favorite',{id:1,is_favorite:'1'});
  const favoritePage1=await post('operator','list',{favorites_only:'1',page_size:1,page:1});
  const favoritePage2=await post('operator','list',{favorites_only:'1',page_size:1,page:2});
  check(favoritePage1.pagination.total===2 && favoritePage1.pagination.total_pages===2 && favoritePage2.data[0].id!==favoritePage1.data[0].id,'favorite pagination includes a readable closed legacy record without duplicates');
  await post('operator','set_favorite',{id:1,is_favorite:'0'});
  check((await post('manager','list',{favorites_only:'1',search:original.title})).pagination.total===0,'other user favorite list is independent');
  check((await post('operator','list',{favorites_only:'1',search:'no-such-favorite-'+id()})).pagination.total===0,'search still filters favorites');
  await post('operator','list',{favorites_only:'x'},422);
  check(JSON.stringify(beforeFavorite)===JSON.stringify((await fixture('operator',{mode:'counts'})).data),'favorites create no business events notifications or commands');
  check((await get('operator',notice)).lock_version===created.data.lock_version,'favorite does not alter record version');
  await post('manager','set_favorite',{id:notice,is_favorite:'1'});
  await post('operator','set_favorite',{id:notice,is_favorite:'0'});await post('operator','set_favorite',{id:notice,is_favorite:'0'});
  check(Number((await get('operator',notice)).is_favorite)===0 && Number((await get('manager',notice)).is_favorite)===1,'unfavorite is idempotent and leaves other user marker intact');
  await post('manager','set_favorite',{id:notice,is_favorite:'0'});
  await post('operator','set_favorite',{id:notice,is_favorite:'1'},200,'/__fixture/disabled');
  check(Number((await get('operator',notice)).is_favorite)===1,'personal favorites also work when workflow is disabled');
  await post('operator','set_favorite',{id:notice,is_favorite:'0'});
  check((await post('operator','create',original)).id===notice,'create replay stable ID');
  await post('manager','create',{...original,request_id:id()},403);
  await post('operator','get',{id:2},404);await post('manager','get',{id:2},404);await post('outsider','list',{},403);
  const list=await post('manager','list',{workflow_queue:'pending_review'});check(list.data.some(row=>row.id==notice) && !list.data.some(row=>row.id==2),'queue scoped');
  const allList=await post('manager','list');
  const analytics=await post('manager','dashboard',{},200,'/emcore_api/emcore_procurement_analytics.php');
  check(analytics.data.summary.total===allList.pagination.total,'Analytics and export list use the same ownership scope');
  check((await post('manager','list',{workflow_queue:'history'})).data.some(row=>row.id==1),'legacy history retained');
  await post('manager','prepare_workflow_action',{id:1,lock_version:1,command_type:'activate',body:'غیرمجاز',request_id:id()},422);
  await post('operator','update',{...original,id:notice,lock_version:1,request_id:id(),participation_status:'won'},403);
  await fixture('operator',{mode:'historical_guarantee',id:notice});
  let updated=await post('operator','update',{...original,id:notice,lock_version:1,request_id:id(),title:'مشخصات اصلاح‌شده'});
  check(updated.data.secondary_guarantee==='سابقهٔ ضمانت','historical guarantee untouched');
  check((await post('manager','list_events',{id:notice})).data.every(event=>!Object.hasOwn(event.before_data||{},'is_favorite') && !Object.hasOwn(event.after_data||{},'is_favorite')),'personal favorite state is absent from shared event snapshots');
  await post('operator','update',{...original,id:notice,lock_version:1,request_id:id()},409);
  let row=await get('manager',notice);
  const rejection={id:notice,lock_version:row.lock_version,body:'نیاز فعلی نداریم',request_id:id()};
  await post('operator','set_review_decision',rejection,422);await post('manager','set_review_decision',rejection);await post('manager','set_review_decision',rejection);
  check(!(await get('manager',notice)).workflow.app_uid,'rejection creates no case');
  row=await get('manager',notice);
  let activate={id:notice,lock_version:row.lock_version,command_type:'activate',body:'فرصت مناسب برای پیگیری',request_id:id()};
  await post('operator','prepare_workflow_action',activate,422);await post('manager','prepare_workflow_action',activate);await post('manager','prepare_workflow_action',activate);
  check((await get('manager',notice)).participation_status==='not_interested','prepared/draft activation not interested');
  let app=(await fixture('manager',{mode:'create'})).app_uid;
  await fixture('operator',{mode:'before',app_uid:app,request_id:activate.request_id},403);
  await fixture('manager',{mode:'before',app_uid:app,request_id:activate.request_id});await fixture('manager',{mode:'before',app_uid:app,request_id:activate.request_id});
  check((await post('manager','get_native_context')).data.commands.some(c=>c.request_id===activate.request_id),'activation retry remains selectable after preparation and reload');
  check((await fixture('manager',{mode:'reconcile',app_uid:app})).state==='pending','before native route remains pending');
  await post('operator','update',{...original,id:notice,lock_version:row.lock_version,request_id:id()},403);
  await fixture('manager',{mode:'route',app_uid:app,stage:'follow_up'});
  check((await fixture('manager',{mode:'reconcile',app_uid:app})).state==='completed','partial native routing recovered');
  const counts=(await fixture('manager',{mode:'counts'})).data;await fixture('manager',{mode:'reconcile',app_uid:app});
  check(JSON.stringify(counts)===JSON.stringify((await fixture('manager',{mode:'counts'})).data),'reconcile replay adds no events or notifications');
  row=await get('operator',notice);check(row.participation_status==='interested' && row.interest_reason===activate.body,'interest finalized only after routing');
  const note={id:notice,body:'پیگیری تلفنی انجام شد',request_id:id()};
  let event=await post('operator','create_event',note);await post('operator','create_event',note);
  await post('operator','create_event',{...note,body:'متن متفاوت'},409);
  await post('manager','create_event',{id:notice,body:'اصلاح نوشتهٔ شخص دیگر',corrects_event_id:event.event_id,request_id:id()},403);
  await post('operator','create_event',{id:notice,body:'اصلاح توضیح تماس',corrects_event_id:event.event_id,request_id:id()});
  let events=await post('manager','list_events',{id:notice});check(events.data.every(e=>e.readers.length===0),'API fetching does not mark seen');
  let notifs=await post('manager','list_notifications');check(notifs.unread_count>0,'durable notifications');
  await post('manager','mark_events_seen',{id:notice,event_ids:JSON.stringify([event.event_id])});
  check((await post('manager','list_notifications')).unread_count===notifs.unread_count-1,'only relevant event notification read');
  await post('operator','mark_events_seen',{id:2,event_ids:JSON.stringify([event.event_id])},404);
  const attach=new FormData();for(const [key,value] of Object.entries({action:'create_event',id:notice,body:'دو تصویر پیوست',request_id:id()}))attach.append(key,value);
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9O0AAAAASUVORK5CYII=','base64');
  attach.append('attachments[]',new Blob([png],{type:'image/png'}),'one.png');attach.append('attachments[]',new Blob([png],{type:'image/png'}),'two.png');
  const uploaded=await post('operator',null,attach);
  events=await post('operator','list_events',{id:notice});const attachedEvent=events.data.find(e=>e.id==uploaded.event_id);check(attachedEvent.files.length===2,'atomic multiple attachments');
  await post('operator','delete_file',{file_id:attachedEvent.files[0].id},403);await post('outsider','download_file',{file_id:attachedEvent.files[0].id},403);
  const beforeFailure=(await fixture('operator',{mode:'counts'})).data;
  const storageBefore=fs.readdirSync(process.env.EMCORE_PROCUREMENT_STORAGE_ROOT,{recursive:true}).sort();
  const bad=new FormData();for(const [key,value]of Object.entries({action:'create_event',id:notice,body:'نباید منتشر شود',request_id:id()}))bad.append(key,value);
  bad.append('attachments[]',new Blob([png],{type:'image/png'}),'valid.png');bad.append('attachments[]',new Blob(['bad executable']),'bad.exe');await post('operator',null,bad,422);
  check(JSON.stringify(beforeFailure)===JSON.stringify((await fixture('operator',{mode:'counts'})).data),'failed second attachment rolls back event notification and files');
  check(JSON.stringify(storageBefore)===JSON.stringify(fs.readdirSync(process.env.EMCORE_PROCUREMENT_STORAGE_ROOT,{recursive:true}).sort()),'failed publication leaves no orphaned stored file');
  // Valid empty ZIP and RAR5 archives. RAR5 block CRC covers size/type/flags.
  function rarBlock(data){let crc=0xffffffff;for(const byte of data){crc^=byte;for(let bit=0;bit<8;bit++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}const header=Buffer.alloc(4);header.writeUInt32LE((crc^0xffffffff)>>>0);return Buffer.concat([header,Buffer.from(data)]);}
  const zip=Buffer.from('504b0506'+'00'.repeat(18),'hex');
  const rar=Buffer.concat([Buffer.from('526172211a070100','hex'),rarBlock([3,1,0,0]),rarBlock([3,5,0,0])]);
  for(const [filename,content] of [['documents.ZIP',zip],['followup.RAR',rar]]) {
    const form=new FormData();for(const [key,value] of Object.entries({action:'upload_file',procurement_id:notice,file_role:'notice_document'}))form.append(key,value);
    form.append('file',new Blob([content]),filename);const file=await post('operator',null,form,201);
    check(file.data.extension===filename.split('.').pop().toLowerCase(),'archive accepted as notice document '+filename);
    const response=await fetch(base+'/emcore_api/emcore_procurement_notices.php',{method:'POST',headers:{cookie:clients.operator.cookie,'x-emcore-fixture-actor':'operator'},body:new URLSearchParams({action:'download_file',file_id:file.id})});
    check(response.ok && Buffer.from(await response.arrayBuffer()).equals(content),'archive downloads unchanged '+filename);
  }
  const archives=new FormData();for(const [key,value] of Object.entries({action:'create_event',id:notice,body:'پیوست ZIP و RAR',request_id:id()}))archives.append(key,value);
  archives.append('attachments[]',new Blob([zip]),'documents.zip');archives.append('attachments[]',new Blob([rar]),'followup.rar');
  const archiveEvent=await post('operator',null,archives);check((await post('operator','list_events',{id:notice})).data.find(e=>e.id==archiveEvent.event_id).files.length===2,'ZIP and RAR can be published together as event attachments');
  for(const filename of ['fake.zip','fake.rar']) {
    const fake=new FormData();for(const [key,value] of Object.entries({action:'upload_file',procurement_id:notice,file_role:'notice_document'}))fake.append(key,value);
    fake.append('file',new Blob([Buffer.from([0,1,2,3,0,255,0,255])]),filename);await post('operator',null,fake,422);
  }
  row=await get('operator',notice);
  const proposal={id:notice,lock_version:row.lock_version,command_type:'propose_result',result:'won',body:'پیشنهاد نتیجه با توضیح',request_id:id()};
  // No documents_submitted prerequisite.
  await post('operator','prepare_workflow_action',proposal);await fixture('operator',{mode:'before',app_uid:app,request_id:proposal.request_id});
  await fixture('operator',{mode:'route',app_uid:app,stage:'result_review'});await fixture('operator',{mode:'reconcile',app_uid:app});
  row=await get('manager',notice);check(row.participation_status==='interested' && row.pending_result==='won','proposal separate from final status');
  await post('operator','update',{...original,id:notice,lock_version:row.lock_version,request_id:id(),participation_status:'interested',interest_reason:activate.body},403);
  await post('operator','create_event',{id:notice,body:'توضیح در انتظار تصمیم',request_id:id()});
  const returned={id:notice,lock_version:row.lock_version,command_type:'return_follow_up',body:'لطفاً بررسی بیشتر',request_id:id()};
  await post('manager','prepare_workflow_action',returned);await fixture('manager',{mode:'before',app_uid:app,request_id:returned.request_id});
  await fixture('manager',{mode:'route',app_uid:app,stage:'follow_up'});await fixture('manager',{mode:'reconcile',app_uid:app});
  row=await get('operator',notice);check(row.pending_result===null && row.interest_reason===activate.body,'return preserves interest reason');
  await post('operator','record_submission',{id:notice,lock_version:row.lock_version,body:'مدارک تحویل شد',request_id:id()});
  row=await get('operator',notice);proposal.lock_version=row.lock_version;proposal.request_id=id();await post('operator','prepare_workflow_action',proposal);
  await fixture('operator',{mode:'before',app_uid:app,request_id:proposal.request_id});await fixture('operator',{mode:'route',app_uid:app,stage:'result_review'});await fixture('operator',{mode:'reconcile',app_uid:app});
  row=await get('manager',notice);
  const approve={id:notice,lock_version:row.lock_version,command_type:'approve_result',body:'نتیجه تأیید می‌شود',request_id:id()};
  await post('operator','prepare_workflow_action',approve,422);await post('manager','prepare_workflow_action',approve);
  await fixture('manager',{mode:'before',app_uid:app,request_id:approve.request_id});await fixture('manager',{mode:'route',app_uid:app,stage:'completed'});await fixture('manager',{mode:'reconcile',app_uid:app});
  row=await get('operator',notice);check(row.participation_status==='won' && row.interest_reason===activate.body,'manager finalizes and preserves reason');
  await post('manager','prepare_workflow_action',{...activate,request_id:id(),lock_version:row.lock_version},422);
  await post('operator','create_event',{id:notice,body:'توضیح پس از پایان',request_id:id()});
  await post('outsider','dashboard',{},403,'/emcore_api/emcore_procurement_analytics.php');
  const second=(await post('operator','create',{...original,request_id:id(),title:'آزمون توقف'},201)).id;
  row=await get('manager',second);activate={...activate,id:second,lock_version:row.lock_version,request_id:id()};await post('manager','prepare_workflow_action',activate);
  app=(await fixture('manager',{mode:'create'})).app_uid;await fixture('manager',{mode:'before',app_uid:app,request_id:activate.request_id});await fixture('manager',{mode:'route',app_uid:app,stage:'follow_up'});await fixture('manager',{mode:'reconcile',app_uid:app});
  await fixture('manager',{mode:'route',app_uid:app,stage:'stopped'},409);
  row=await get('manager',second);let stop={id:second,command_type:'request_stop',body:'توقف با دلیل',request_id:id(),lock_version:row.lock_version};
  await post('manager','prepare_workflow_action',stop);await post('manager','cancel_stop_request',{id:second,request_id:stop.request_id});
  stop.request_id=id();await post('manager','prepare_workflow_action',stop);check((await get('manager',second)).participation_status==='interested','stop request not cancellation');
  await fixture('operator',{mode:'route',app_uid:app,stage:'stopped'},403);
  await fixture('manager',{mode:'route',app_uid:app,stage:'stopped'});await fixture('manager',{mode:'reconcile',app_uid:app});
  check((await get('manager',second)).participation_status==='withdrawn','native cancellation reflected');
  const third=(await post('operator','create',{...original,request_id:id(),title:'آزمون ارجاع ناسازگار'},201)).id;
  row=await get('manager',third);activate={...activate,id:third,lock_version:row.lock_version,request_id:id()};
  await post('manager','prepare_workflow_action',activate);app=(await fixture('manager',{mode:'create'})).app_uid;
  await fixture('manager',{mode:'before',app_uid:app,request_id:activate.request_id});
  await fixture('manager',{mode:'route',app_uid:app,stage:'result_review'});await fixture('manager',{mode:'reconcile',app_uid:app},500);
  check((await get('manager',third)).sync_state==='error','wrong native task is a visible reconciliation error');
  await fixture('manager',{mode:'route',app_uid:app,stage:'follow_up'});await fixture('manager',{mode:'reconcile',app_uid:app});
  check((await get('manager',third)).participation_status==='interested','corrected native routing recovers the same pending command');
  console.log(`Procurement workflow integration: ${checks} assertions passed.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
