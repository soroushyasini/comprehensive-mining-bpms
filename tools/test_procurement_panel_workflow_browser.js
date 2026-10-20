'use strict';
// Real browser, two isolated accounts; run only against the loopback fixture.
const assert=require('node:assert/strict');
const path=require('node:path');
const crypto=require('node:crypto');
const {chromium}=require(path.join(process.env.EMCORE_TEST_NODE_MODULES,'playwright'));
const base='http://127.0.0.1:33380', endpoint=base+'/emcore_api/emcore_procurement_notices.php';
let checks=0;
function check(value,message){checks++;assert.ok(value,message);}
function requestId(){return crypto.randomBytes(16).toString('hex');}
async function main(){
  const browser=await chromium.launch({headless:true,executablePath:process.env.EMCORE_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  try{
    const errors=[], clients={};
    for(const role of ['operator','manager']){
      const context=await browser.newContext({viewport:{width:1440,height:1000},extraHTTPHeaders:{'x-emcore-fixture-actor':role}});
      const lookups=await (await context.request.post(endpoint,{form:{action:'lookups'}})).json();
      const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
      clients[role]={context,page,csrf:lookups.csrf_token};
    }
    async function api(role,action,data={}){
      const c=clients[role], response=await c.context.request.post(endpoint,{form:{action,...data},headers:{'X-CSRF-Token':c.csrf}});
      assert.ok(response.ok(),await response.text());return response.json();
    }
    async function listAfter(page,callback){
      await Promise.all([page.waitForResponse(r=>r.url()===endpoint && new URLSearchParams(r.request().postData()||'').get('action')==='list'),callback()]);
      await page.locator('#ledger[aria-busy="true"]').waitFor({state:'hidden'});
    }
    async function open(role,title,queue=''){
      const page=clients[role].page;
      await page.goto(base+'/panel');
      await page.locator('#workflowTools').waitFor();
      await page.locator('#workflowQueue').selectOption(queue);
      await page.locator('#searchInput').fill(title);
      await listAfter(page,()=>page.locator('#filterButton').click());
      await page.getByRole('button',{name:'اقدامات و پیگیری‌ها',exact:true}).click();
      await page.locator('#workflowPublish').waitFor();
      return page;
    }
    async function decide(role,type,body,result){
      const page=clients[role].page;
      await page.locator('[data-workflow-action="'+type+'"]').click();
      if(type==='approve_result')check((await page.locator('#workflowContent').textContent()).includes('نتیجهٔ پیشنهادی برای تأیید: شکست'),'confirmation identifies the exact result');
      await page.locator('#workflowDecisionReason').fill(body);
      if(result)await page.locator('#workflowDecisionResult').selectOption(result);
      const responsePromise=page.waitForResponse(r=>r.url()===endpoint && new URLSearchParams(r.request().postData()||'').get('action')==='transition_workflow');
      await page.locator('#workflowDecisionConfirm').click();
      const response=await responsePromise;check(response.ok(),'decision submitted directly: '+type);
      await page.locator('#workflowPublish').waitFor();
      check(await page.locator('#workflowContent a[target="_top"]').count()===0,'no native case navigation: '+type);
    }
    const title='آزمون تصمیم مستقیم پنل '+requestId();
    const notice=(await api('operator','create',{request_id:requestId(),notice_type:'tender',title,registered_on_fa:'1405/06/31',participation_status:'registered'})).id;
    let page=await open('operator',title);
    check(await page.locator('[data-workflow-action]').count()===0,'operator cannot decide initial interest');
    page=await open('manager',title,'pending_review');
    check(await page.locator('[data-workflow-action="activate"]').count()===1,'manager sees initial decision');
    await page.locator('[data-workflow-action="activate"]').click();
    await page.locator('#workflowDecisionConfirm').click();
    check((await page.locator('#workflowFeedback').textContent()).includes('الزامی'),'empty reason rejected in UI');
    await page.getByRole('button',{name:'بازگشت به اقدامات',exact:true}).click();await page.locator('#workflowPublish').waitFor();
    await decide('manager','activate','انتخاب مدیر از پنل');
    let row=(await api('operator','get',{id:notice})).data;
    check(row.participation_status==='interested' && row.app_uid===null,'interest has no native case');
    check((await api('operator','list_notifications')).data.some(n=>n.procurement_id==notice && n.event_type==='activate'),'operator notified of interest');
    page=await open('operator',title,'follow_up');
    check(await page.locator('[data-workflow-action="propose_result"]').count()===1 && await page.locator('[data-workflow-action="approve_result"]').count()===0,'operator actions scoped to follow-up');
    check(await page.locator('#ledger tbody').getByRole('button',{name:'حذف',exact:true}).count()===0,'active follow-up has no delete button');
    await page.locator('[data-workflow-action="propose_result"]').click();
    await page.locator('#workflowDecisionReason').fill('پیشنهاد بدون انتخاب نتیجه');
    await page.locator('#workflowDecisionConfirm').click();
    check((await page.locator('#workflowFeedback').textContent()).includes('نتیجهٔ پیشنهادی'),'result selection required');
    await page.getByRole('button',{name:'بازگشت به اقدامات',exact:true}).click();await page.locator('#workflowPublish').waitFor();
    await decide('operator','propose_result','پیشنهاد نخست','won');
    row=(await api('operator','get',{id:notice})).data;
    check(row.participation_status==='interested' && row.pending_result==='won','proposal not final business status');
    check(await page.locator('[data-workflow-action]').count()===0 && await page.locator('#workflowPublish').isEnabled(),'pending operator may write notes but not decide');
    page=await open('manager',title,'result_review');
    check(await page.locator('[data-workflow-action="approve_result"]').count()===1,'manager sees final approval action');
    if(process.env.EMCORE_FIXTURE_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.EMCORE_FIXTURE_SCREENSHOT_DIR,'procurement-panel-result-review.png')});
    await decide('manager','return_follow_up','نیازمند بررسی تکمیلی');
    page=await open('operator',title,'follow_up');
    await decide('operator','propose_result','پیشنهاد اصلاح‌شده','lost');
    page=await open('manager',title,'result_review');
    await decide('manager','approve_result','نتیجه تأیید شد');
    check((await page.locator('#workflowContent').textContent()).includes('نتیجهٔ نهایی: شکست'),'history exposes the actual approved outcome');
    row=(await api('operator','get',{id:notice})).data;
    check(row.participation_status==='lost' && row.interest_reason==='انتخاب مدیر از پنل','approved result and original interest reason preserved');
    check(await page.locator('[data-workflow-action]').count()===0 && await page.locator('#workflowPublish').isEnabled(),'closed workflow has notes but no reopening');
    const stopTitle='آزمون توقف مستقیم '+requestId();
    const stopId=(await api('operator','create',{request_id:requestId(),notice_type:'auction',title:stopTitle,registered_on_fa:'1405/06/31',participation_status:'registered'})).id;
    page=await open('manager',stopTitle);
    await decide('manager','activate','انتخاب برای بررسی');
    await decide('manager','request_stop','توقف با تصمیم مدیر');
    check((await api('operator','get',{id:stopId})).data.participation_status==='withdrawn','stop commits in one panel action');
    check(errors.length===0,'no browser exceptions: '+errors.join('; '));
    console.log('Procurement panel decisions browser: '+checks+' assertions passed.');
  }finally{await browser.close();}
}
main().catch(error=>{console.error(error);process.exitCode=1;});
