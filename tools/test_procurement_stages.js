'use strict';
// Disposable loopback fixture only; never use production accounts or data.
const assert=require('node:assert/strict'), crypto=require('node:crypto');
const base='http://127.0.0.1:33380', clients={};let checks=0;
const requestId=()=>crypto.randomBytes(16).toString('hex');
function check(value,label){checks++;assert.ok(value,label);}
async function post(role,action,data={},expected=200,managed=true,path='/emcore_api/emcore_procurement_notices.php'){
  const c=clients[role]||(clients[role]={}), headers={'x-emcore-fixture-actor':role,'x-emcore-fixture-operator-managed':managed?'1':'0'};
  if(c.cookie)headers.cookie=c.cookie;if(c.csrf)headers['x-csrf-token']=c.csrf;
  const response=await fetch(base+path,{method:'POST',headers,body:new URLSearchParams({...(action?{action}:{}),...data})});
  const cookie=response.headers.get('set-cookie');if(cookie)c.cookie=cookie.split(';')[0];
  const text=await response.text();let result;try{result=JSON.parse(text);}catch(e){throw new Error('Non-JSON: '+text.slice(0,500));}
  assert.equal(response.status,expected,action+': '+text);checks++;
  if(result.csrf_token)c.csrf=result.csrf_token;return result;
}
const fixture=(data)=>post('operator',null,data,200,true,'/__fixture/state');
const get=async(id,role='operator',managed=true)=>(await post(role,'get',{id},200,managed)).data;
async function create(title){
  return post('operator','create',{notice_type:'tender',participation_status:'registered',registered_on_fa:'1405/06/31',title:title+' '+requestId(),follow_up_stage:'awaiting_price_meeting',request_id:requestId()},201);
}
async function main(){
  for(const role of ['operator','manager'])await post(role,'lookups');
  const lookup=await post('operator','lookups');
  check(Object.keys(lookup.data.follow_up_stages).length===5 && lookup.workflow_permissions.operator_managed,'five stages and delegated mode exposed');
  const created=await create('آزمون مرحله مستقل'), id=created.id;
  check(created.data.follow_up_stage==='registered' && created.data.participation_status==='registered','new records ignore a forged stage and start at registered');
  const counts=(await fixture({mode:'counts'})).data;
  let row=await get(id), request;
  for(const stage of ['analysis','document_preparation','awaiting_envelope_opening','awaiting_price_meeting','registered']){
    request={id,follow_up_stage:stage,lock_version:row.lock_version,request_id:requestId()};
    await post('operator','set_follow_up_stage',request);
    const replay=await post('operator','set_follow_up_stage',request);
    check(replay.data.replayed,'stage request replay is idempotent');
    row=await get(id);
    check(row.follow_up_stage===stage && row.participation_status==='registered' && row.workflow_stage===null,'tracking does not decide outcome or create follow-up workflow');
  }
  const after=(await fixture({mode:'counts'})).data;
  check(after.events-counts.events===5 && after.notifications-counts.notifications===5 && after.commands-counts.commands===5 && after.workflows===counts.workflows,'each stage change creates exactly one event command and notification');
  await post('operator','set_follow_up_stage',{...request,follow_up_stage:'analysis'},409);
  await post('operator','set_follow_up_stage',{id,follow_up_stage:'analysis',lock_version:1,request_id:requestId()},409);
  await post('operator','set_follow_up_stage',{id,follow_up_stage:'bad',lock_version:row.lock_version,request_id:requestId()},422);
  await post('operator','set_follow_up_stage',{id,follow_up_stage:'registered',lock_version:row.lock_version,request_id:requestId()},422);
  await post('manager','set_follow_up_stage',{id,follow_up_stage:'analysis',lock_version:row.lock_version,request_id:requestId()},422);
  await post('outsider','set_follow_up_stage',{id,follow_up_stage:'analysis',lock_version:row.lock_version,request_id:requestId()},403);
  await post('operator','set_follow_up_stage',{id:2,follow_up_stage:'analysis',lock_version:1,request_id:requestId()},404);
  const csrf=clients.operator.csrf;clients.operator.csrf='';
  await post('operator','set_follow_up_stage',{id,follow_up_stage:'analysis',lock_version:row.lock_version,request_id:requestId()},403);clients.operator.csrf=csrf;
  await fixture({mode:'operator_write_permission',allowed:'0'});
  try{await post('operator','set_follow_up_stage',{id,follow_up_stage:'analysis',lock_version:row.lock_version,request_id:requestId()},403);}
  finally{await fixture({mode:'operator_write_permission',allowed:'1'});}
  const failed={id,follow_up_stage:'analysis',lock_version:row.lock_version,request_id:requestId()}, beforeFailure=(await fixture({mode:'counts'})).data;
  await fixture({mode:'fail_notification'});
  try{await post('operator','set_follow_up_stage',failed,500);}finally{await fixture({mode:'reset_failure'});}
  check(JSON.stringify(beforeFailure)===JSON.stringify((await fixture({mode:'counts'})).data),'late notification failure rolls back all tracking writes');
  check((await get(id)).follow_up_stage==='registered','failed tracking change leaves previous stage');
  await post('operator','set_follow_up_stage',failed);
  row=await get(id);
  check((await post('operator','list',{follow_up_stage:'analysis'})).data.some(r=>r.id==id),'list filter sees tracking stage');
  await post('operator','list',{follow_up_stage:'bogus'},422);
  const analytics=await post('operator','dashboard',{follow_up_stage:'analysis'},200,true,'/emcore_api/emcore_procurement_analytics.php');
  check(analytics.data.follow_up_stages.length===1 && analytics.data.follow_up_stages[0].stage==='analysis','analytics filters and groups the independent stage');
  const cmd={id,command_type:'record_result',result:'won',body:'نتیجه با اختیار اپراتور',lock_version:row.lock_version,request_id:requestId()};
  await post('operator','transition_workflow',cmd,422,false);
  check(!(await get(id,'operator',false)).workflow.permissions.allowed_actions.includes('record_result'),'delegation-off removes direct outcome permission');
  await post('manager','transition_workflow',cmd,422);
  await post('operator','transition_workflow',{...cmd,result:'bad'},422);
  await post('operator','transition_workflow',cmd);
  row=await get(id);
  check(row.participation_status==='won' && row.follow_up_stage==='analysis' && row.workflow_stage==='completed','delegated outcome does not overwrite tracking stage');
  const events=(await post('operator','list_events',{id})).data;
  check(events.some(e=>e.event_type==='record_result' && e.actor_role==='operator' && e.after_data.operator_managed),'delegated decision records real operator identity');
  await post('operator','transition_workflow',{...cmd,lock_version:row.lock_version,request_id:requestId(),result:'lost'},422);
  await post('operator','set_follow_up_stage',{id,follow_up_stage:'awaiting_price_meeting',lock_version:row.lock_version,request_id:requestId()});
  row=await get(id);check(row.participation_status==='won' && row.workflow_stage==='completed','metadata correction on closed record does not reopen outcome');
  await post('operator','update',{id,lock_version:row.lock_version,request_id:requestId()},403);
  const legacy=(await create('سابقه بدون مرحله')).id;await fixture({mode:'legacy_stage',id:legacy});
  let old=await get(legacy);
  check(old.follow_up_stage===null && old.participation_status==='won','legacy outcomes remain unchanged with unspecified tracking');
  check((await post('operator','list',{follow_up_stage:'unassigned',search:String(legacy)})).data.some(r=>r.id==legacy),'unassigned legacy stage filter');
  await post('operator','set_follow_up_stage',{id:legacy,follow_up_stage:'document_preparation',lock_version:old.lock_version,request_id:requestId()});
  old=await get(legacy);check(old.participation_status==='won' && old.interest_reason==='دلیل تاریخی' && Number(old.workflow_history_only)===1,'explicit legacy classification preserves outcome reason and history flag');
  const native=(await create('سابقه مرتبط با پرونده')).id;await fixture({mode:'native_history',id:native});old=await get(native);
  await post('operator','set_follow_up_stage',{id:native,follow_up_stage:'analysis',lock_version:old.lock_version,request_id:requestId()},409);
  const stop=(await create('توقف مستقیم اپراتور')).id;old=await get(stop);
  await post('operator','transition_workflow',{id:stop,command_type:'record_result',result:'withdrawn',body:'توقف با توضیح اپراتور',lock_version:old.lock_version,request_id:requestId()});
  check((await get(stop)).participation_status==='withdrawn','operator may stop without manager activity');
  console.log('Procurement independent stages: '+checks+' assertions passed.');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
