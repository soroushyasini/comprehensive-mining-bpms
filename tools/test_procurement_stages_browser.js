'use strict';
const assert=require('node:assert/strict'), path=require('node:path'), crypto=require('node:crypto');
const {chromium}=require(path.join(process.env.EMCORE_TEST_NODE_MODULES,'playwright'));
const base='http://127.0.0.1:33380', endpoint=base+'/emcore_api/emcore_procurement_notices.php';
let checks=0;function check(value,label){checks++;assert.ok(value,label);}
async function main(){
  const browser=await chromium.launch({headless:true,executablePath:process.env.EMCORE_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  try{
    const context=await browser.newContext({viewport:{width:1600,height:1000},extraHTTPHeaders:{'x-emcore-fixture-actor':'operator','x-emcore-fixture-operator-managed':'1'}});
    const config=await(await context.request.post(endpoint,{form:{action:'lookups'}})).json();
    async function api(action,data={}){
      const response=await context.request.post(endpoint,{form:{action,...data},headers:{'x-csrf-token':config.csrf_token}});
      assert.ok(response.ok(),await response.text());return response.json();
    }
    const title='آزمون مرحله در جدول '+crypto.randomBytes(8).toString('hex');
    const created=await api('create',{title,notice_type:'tender',participation_status:'registered',registered_on_fa:'1405/06/31',request_id:crypto.randomBytes(16).toString('hex')});
    const id=created.id, page=await context.newPage(), errors=[];page.on('pageerror',e=>errors.push(e.message));
    async function listAfter(callback){
      await Promise.all([page.waitForResponse(r=>r.url()===endpoint && new URLSearchParams(r.request().postData()||'').get('action')==='list'),callback()]);
      await page.locator('#ledger[aria-busy="true"]').waitFor({state:'hidden'});
    }
    await page.goto(base+'/panel');await page.locator('[data-stage-id="'+id+'"]').waitFor();
    check(await page.locator('#operatorManagedNotice').isVisible(),'temporary mode is visible');
    await page.locator('#searchInput').fill(String(id));await listAfter(()=>page.locator('#filterButton').click());
    const select=()=>page.locator('[data-stage-id="'+id+'"]'), save=()=>page.locator('[data-stage-save="'+id+'"]');
    check(await select().inputValue()==='registered' && await save().isDisabled(),'new row starts at registered with no pending change');
    check(await select().locator('option').count()===5,'table exposes exactly five stage choices');
    await select().selectOption('analysis');
    check((await api('get',{id})).data.follow_up_stage==='registered','selecting alone does not save an accidental change');
    await listAfter(()=>save().click());
    check((await api('get',{id})).data.follow_up_stage==='analysis','save changes stage without editing modal');
    check(await page.locator('#formOverlay.open').count()===0 && await page.locator('#workflowOverlay').isHidden(),'quick save opens no modal');
    check(await select().evaluate(el=>el===document.activeElement),'focus returns to changed row');
    const event=(await api('list_events',{id})).data.find(e=>e.event_type==='stage_changed');
    check(event.before_data.follow_up_stage==='registered' && event.after_data.follow_up_stage==='analysis','history records both stages');
    await listAfter(()=>page.locator('#stageFilter').selectOption('document_preparation').then(()=>page.locator('#filterButton').click()));
    check(await select().count()===0,'stage filter excludes the target row in a different stage');
    await page.locator('#stageFilter').selectOption('analysis');await listAfter(()=>page.locator('#filterButton').click());
    check(await select().inputValue()==='analysis','stage filter retrieves matching row');
    await page.waitForFunction(()=>!document.getElementById('exportButton').disabled);
    const download=page.waitForEvent('download');await page.locator('#exportButton').click();await download;
    const exported=await page.evaluate(()=>{
      const workbook=window.__fixtureWorkbook, sheet=workbook.Sheets[workbook.SheetNames[0]];
      return Object.values(sheet).filter(cell=>cell && typeof cell==='object').map(cell=>cell.v);
    });
    check(exported.includes('مرحلهٔ پیگیری') && exported.includes('تحلیل و تصمیم‌گیری'),'Excel includes a distinct stage header and stage values');
    const current=(await api('get',{id})).data;
    await api('set_follow_up_stage',{id,follow_up_stage:'document_preparation',lock_version:current.lock_version,request_id:crypto.randomBytes(16).toString('hex')});
    await select().selectOption('awaiting_price_meeting');
    const conflict=page.waitForResponse(r=>r.url()===endpoint && new URLSearchParams(r.request().postData()||'').get('action')==='set_follow_up_stage');
    await save().click();check((await conflict).status()===409,'stale inline edit is rejected');
    await page.locator('#ledger[aria-busy="true"]').waitFor({state:'hidden'});
    check((await api('get',{id})).data.follow_up_stage==='document_preparation','conflict does not overwrite newer stage');
    await page.locator('#stageFilter').selectOption('');await page.locator('#searchInput').fill(title);await listAfter(()=>page.locator('#filterButton').click());
    if(process.env.EMCORE_FIXTURE_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.EMCORE_FIXTURE_SCREENSHOT_DIR,'procurement-inline-stage.png'),fullPage:true});
    await page.getByRole('button',{name:'اقدامات و پیگیری‌ها',exact:true}).click();await page.locator('#workflowPublish').waitFor();
    await page.locator('[data-workflow-action="record_result"]').click();
    await page.locator('#workflowDecisionResult').selectOption('won');
    await page.locator('#workflowDecisionReason').fill('تأیید نتیجه توسط اپراتور در حالت موقت');
    const decided=page.waitForResponse(r=>r.url()===endpoint && new URLSearchParams(r.request().postData()||'').get('action')==='transition_workflow');
    await page.locator('#workflowDecisionConfirm').click();check((await decided).ok(),'operator records final result without manager');
    await page.locator('#workflowPublish').waitFor();
    const final=(await api('get',{id})).data;
    check(final.participation_status==='won' && final.follow_up_stage==='document_preparation','result and tracking stage stay independent');
    check((await page.locator('#workflowContent').textContent()).includes('تأیید مدیر محسوب نمی‌شود'),'history identifies delegated operator action honestly');
    await page.locator('#workflowClose').click();
    await select().selectOption('awaiting_envelope_opening');await listAfter(()=>save().click());
    check((await api('get',{id})).data.participation_status==='won','tracking remains correctable without reopening final result');
    const manager=await browser.newContext({extraHTTPHeaders:{'x-emcore-fixture-actor':'manager','x-emcore-fixture-operator-managed':'1'}});
    const managerPage=await manager.newPage();await managerPage.goto(base+'/panel');await managerPage.locator('#workflowTools').waitFor();
    check(await managerPage.locator('[data-stage-save]').count()===0,'manager does not receive operator inline controls');
    check(errors.length===0,'no browser errors: '+errors.join('; '));
    console.log('Procurement inline stages browser: '+checks+' assertions passed.');
  }finally{await browser.close();}
}
main().catch(e=>{console.error(e);process.exitCode=1;});
