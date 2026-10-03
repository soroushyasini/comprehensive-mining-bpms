'use strict';
const fs=require('node:fs');const path=require('node:path');const vm=require('node:vm');const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
function read(file){return fs.readFileSync(path.join(root,file),'utf8');}
const migration=read('database/migrations/012_emcore_procurement_workflow.sql');
for(const table of ['workflows','commands','events','event_reads','notifications'])assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS emcore_procurement_'+table),'schema '+table);
for(const item of ['owner_usr_uid','manager_usr_uid','workflow_history_only','uq_procurement_case','uq_procurement_event_request','uq_procurement_notification','PRIMARY KEY (event_id, usr_uid)','activity_attachment'])assert.ok(migration.includes(item),item);
const policy=read('emcore_api/_procurement_workflow_policy.php'), bridge=read('emcore_api/_procurement_workflow.php'), actions=read('emcore_api/_procurement_workflow_actions.php');
for(const action of ['list_events','create_event','mark_events_seen','list_notifications','set_review_decision','prepare_workflow_action','get_workflow','get_native_context','record_submission','cancel_stop_request'])assert.ok(actions.includes("'"+action+"'"),action);
assert.ok(policy.includes("$next['pending_result'] = $result"),'proposal separate from status');
assert.ok(bridge.includes('source_del_index') && bridge.includes('DEL_THREAD_STATUS') && bridge.includes('expected_version'),'native context/version checks');
assert.ok(!/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+(?:APPLICATION|APP_DELEGATION)\b/i.test(bridge+'\n'+actions),'production code must not mutate PM routing tables');
assert.ok(actions.includes('52428800') && actions.includes('emcore_procurement_remove_failed_upload'),'atomic publication cleanup/limit');
assert.ok(actions.includes("$event['readers']") && actions.includes('first_seen_at'),'server view receipts');
assert.ok(actions.includes("$retry?:null") && actions.includes("c.command_state='pending'"),'native preparation is recoverable after reload');
assert.ok(bridge.includes('emcore_pw_native_cancel_guard'),'native cancellation guard');
const definition=JSON.parse(read('processmaker/procurement/process.definition.json'));
assert.equal(definition.tasks.length,3);assert.equal(definition.all_task_triggers.before_assignment,'before_assignment.trigger');assert.equal(definition.process_action_triggers.cancel,'before_cancel.trigger');
for(const trigger of Object.values({...definition.all_task_triggers,...definition.process_action_triggers}))assert.ok(read('processmaker/procurement/'+trigger));
JSON.parse(read('processmaker/procurement/native-form.template.json'));
for(const file of ['panels/emcore_procurement_notices_panel.html','panels/emcore_procurement_workflow_panel.html']){
  const html=read(file);let scripts=0;
  for(const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)){if(match[1].trim()){new vm.Script(match[1],{filename:file});scripts++;}}
  assert.ok(scripts>0,file+' script syntax');
}
const panel=read('panels/emcore_procurement_notices_panel.html');
for(const text of ['document.hidden','document.hasFocus()','now-workflowSince[id]>=1000','workflowEventVisible','workflow_queue','publicationRequest','request_id:request','workflowSeenBusy'])assert.ok(panel.includes(text),text);
const visibleMatch=panel.match(/  function workflowEventVisible\([^\n]*\) \{[\s\S]*?\n  \}/);
const visible=vm.runInNewContext('('+visibleMatch[0].trim()+')');
const viewport={top:100,bottom:500,left:0,right:400};
assert.equal(visible({top:120,bottom:150,left:20,right:100,height:30},viewport),true);
assert.equal(visible({top:90,bottom:150,left:20,right:100,height:60},viewport),false);
assert.equal(visible({top:500,bottom:530,left:20,right:100,height:30},viewport),false);
assert.equal(visible({top:120,bottom:150,left:410,right:440,height:30},viewport),false);
const main=read('emcore_api/emcore_procurement_notices.php'), analytics=read('emcore_api/emcore_procurement_analytics.php');
assert.ok(main.includes('emcore_pw_assert_edit') && main.includes('emcore_pw_scope'),'CRUD scope');
assert.ok(analytics.includes('emcore_pw_scope'),'Analytics scope');
assert.ok(read('docs/PROCUREMENT_WORKFLOW.md').includes('Before Assignment'),'deployment timing guidance');
console.log('Procurement workflow release checks passed.');
