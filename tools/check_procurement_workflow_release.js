'use strict';
const fs=require('node:fs');const path=require('node:path');const vm=require('node:vm');const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
function read(file){return fs.readFileSync(path.join(root,file),'utf8');}
const migration=read('database/migrations/012_emcore_procurement_workflow.sql');
for(const table of ['workflows','commands','events','event_reads','notifications'])assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS emcore_procurement_'+table),'schema '+table);
for(const item of ['owner_usr_uid','manager_usr_uid','workflow_history_only','uq_procurement_case','uq_procurement_event_request','uq_procurement_notification','PRIMARY KEY (event_id, usr_uid)','activity_attachment'])assert.ok(migration.includes(item),item);
const policy=read('emcore_api/_procurement_workflow_policy.php'), bridge=read('emcore_api/_procurement_workflow.php'), actions=read('emcore_api/_procurement_workflow_actions.php');
for(const action of ['list_events','create_event','mark_events_seen','list_notifications','set_review_decision','prepare_workflow_action','get_workflow','get_native_context','record_submission','cancel_stop_request','transition_workflow'])assert.ok(actions.includes("'"+action+"'"),action);
assert.ok(policy.includes("$next['pending_result'] = $result"),'proposal separate from status');
const panelMigration=read('database/migrations/017_emcore_procurement_panel_workflow.sql');
assert.ok(/MODIFY COLUMN app_uid CHAR\(32\) NULL DEFAULT NULL/.test(panelMigration),'local workflow needs nullable app link');
for(const source of [bridge,actions,read('tools/configure_procurement_workflow.php')]) {
  assert.ok(!/\b(?:SELECT|UPDATE|INSERT|DELETE)[\s\S]*?\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:TASK|APPLICATION|APP_DELEGATION)\b/i.test(source),'no dependency on PM routing tables');
  assert.ok(!source.includes('emcore_pw_native_') && !source.includes('emcore_pw_reconcile_one'),'no native execution helpers');
}
for(const text of ['emcore_pw_assert_panel','emcore_pw_version','payload_hash',"'completed',NOW()",'permissions','allowed_actions'])assert.ok(bridge.includes(text),text);
assert.ok(!bridge.includes('case_url_prefix') && !bridge.includes('activation_task'),'only participant configuration required');
assert.ok(actions.includes('209715200') && actions.includes('emcore_procurement_remove_failed_upload'),'atomic publication cleanup/200 MiB limit');
assert.ok(actions.includes("$event['readers']") && actions.includes('first_seen_at'),'server view receipts');
assert.ok(actions.includes('410') && actions.includes("'transition_workflow'"),'retire old protocol and expose local transitions');
assert.ok(read('tools/reconcile_procurement_workflow.php').includes("'state' => 'retired'"),'obsolete scheduler is inert');
assert.ok(!read('tools/reconcile_procurement_workflow.php').includes('emcore_db'),'obsolete scheduler cannot mutate data');
const html=read('panels/emcore_procurement_notices_panel.html');let scripts=0;
for(const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)){if(match[1].trim()){new vm.Script(match[1],{filename:'procurement panel'});scripts++;}}
assert.ok(scripts>0,'panel script syntax');
const panel=html;
for(const text of ['document.hidden','document.hasFocus()','now-workflowSince[id]>=1000','workflowEventVisible','workflow_queue','publicationRequest','request_id:request','workflowSeenBusy',"api('transition_workflow',data)",'permissions.allowed_actions','workflowDecisionResult','!row.workflow_stage'])assert.ok(panel.includes(text),text);
for(const retired of ['prepare_workflow_action','get_native_context','native_url','case_url','cancel_stop_request'])assert.ok(!panel.includes(retired),'panel must not invoke '+retired);
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
assert.ok(read('docs/PROCUREMENT_WORKFLOW.md').includes('017_emcore_procurement_panel_workflow.sql'),'panel migration deployment guidance');
assert.ok(!fs.existsSync(path.join(root,'panels/emcore_procurement_workflow_panel.html')),'obsolete native panel removed');
console.log('Procurement in-panel workflow release checks passed.');
