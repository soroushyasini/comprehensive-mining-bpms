'use strict';
const assert=require('node:assert/strict');
const path=require('node:path');
const crypto=require('node:crypto');
const {chromium}=require(path.join(process.env.EMCORE_TEST_NODE_MODULES,'playwright'));
const base='http://127.0.0.1:33380';let checks=0;
function check(value,message){checks++;assert.ok(value,message);}
async function main(){
  const browser=await chromium.launch({headless:true,executablePath:process.env.EMCORE_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe'});
  try{
    const context=await browser.newContext({viewport:{width:1440,height:1000},extraHTTPHeaders:{'x-emcore-fixture-actor':'operator'}});
    const lookups=await (await context.request.post(base+'/emcore_api/emcore_procurement_notices.php',{form:{action:'lookups'}})).json();
    const csrf=lookups.csrf_token;
    async function api(action,data={}){const response=await context.request.post(base+'/emcore_api/emcore_procurement_notices.php',{form:{action,...data},headers:{'X-CSRF-Token':csrf}});assert.ok(response.ok(),await response.text());return response.json();}
    const title='آزمون مرورگر مشاهدهٔ واقعی رویدادها '+crypto.randomBytes(8).toString('hex');
    const created=await api('create',{request_id:crypto.randomBytes(16).toString('hex'),notice_type:'tender',title,registered_on_fa:'1405/06/31',participation_status:'registered'});
    const notice=created.id;
    for(let index=0;index<29;index++)await api('create_event',{id:notice,request_id:crypto.randomBytes(16).toString('hex'),body:'رویداد آزمایشی '+index+'\n'+('توضیح پیگیری برای کنترل محدودهٔ قابل مشاهده. '.repeat(8))});
    const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.addInitScript(()=>{Date.prototype.getFullYear=function(){return 1405;};});
    await page.goto(base+'/panel');await page.getByRole('button',{name:'اقدامات و پیگیری‌ها'}).first().waitFor();
    await page.locator('#searchInput').fill(title);
    await Promise.all([
      page.waitForResponse(response=>response.url().endsWith('emcore_procurement_notices.php')&&new URLSearchParams(response.request().postData()||'').get('search')===title),
      page.locator('#filterButton').click()
    ]);
    await page.getByRole('button',{name:'اقدامات و پیگیری‌ها'}).click();await page.locator('.workflow-event-heading').first().waitFor();
    let reads=(await api('list_events',{id:notice})).data;
    check(reads.every(event=>event.readers.length===0),'Opening/fetching the modal does not immediately mark seen');
    await page.waitForTimeout(1600);
    reads=(await api('list_events',{id:notice})).data;
    const seen=reads.filter(event=>event.readers.length>0);
    check(seen.length>0 && seen.length<reads.length,'Only headers visible for a second are seen, not the full page');
    check((await api('list_events',{id:notice,page:2})).data.every(event=>event.readers.length===0),'Unrendered second page is not seen');
    // Moving to a background page resets all dwell time. No DOM/auth mutation.
    const other=await context.newPage();await other.goto('about:blank');await other.bringToFront();
    const firstSeenCount=seen.length;await page.waitForTimeout(1300);
    reads=(await api('list_events',{id:notice})).data;check(reads.filter(e=>e.readers.length>0).length===firstSeenCount,'Inactive page does not add seen events');
    await other.close();await page.bringToFront();
    await page.locator('#workflowClose').focus();await page.keyboard.press('Tab');
    check(await page.locator('#workflowOverlay').evaluate(el=>el.contains(document.activeElement)),'Keyboard focus stays inside modal');
    await page.keyboard.press('Escape');check(await page.locator('#workflowOverlay').isHidden(),'Escape closes modal');
    await page.locator('[data-date-target="registeredOnFilter"]').click();
    check((await page.locator('#jalaliMonthLabel').textContent()).includes('۱۴۰۵'),'Datepicker survives modified Date getter');
    await page.locator('#jalaliDays .calendar-day[tabindex="0"]').focus();
    const previousDay=await page.locator('#jalaliDays .calendar-day:focus').getAttribute('data-day');
    await page.keyboard.press('ArrowLeft');
    await page.waitForFunction(day=>document.activeElement?.matches('.calendar-day') && document.activeElement.getAttribute('data-day')!==day,previousDay);
    check(await page.locator('#jalaliDays .calendar-day:focus').count()===1,'Arrow navigation works under jQuery 1.11');
    await page.keyboard.press('Escape');
    check(await page.locator('#jalaliDatepicker').isHidden(),'Escape closes calendar under jQuery 1.11');
    await page.getByRole('button',{name:'اقدامات و پیگیری‌ها'}).click();await page.locator('#workflowPublish').waitFor();
    await page.getByRole('button',{name:'صفحهٔ بعد',exact:true}).click();await page.waitForTimeout(200);
    check((await page.locator('.workflow-pagination').textContent()).includes('۲'),'Event pagination renders second page');
    await page.getByRole('button',{name:'صفحهٔ قبل',exact:true}).click();await page.locator('#workflowPublish').waitFor();
    await page.locator('#workflowBodyText').fill('ثبت اقدام از مرورگر');
    await Promise.all([
      page.waitForResponse(response=>response.url().endsWith('emcore_procurement_notices.php')&&response.request().postData()?.includes('name="action"\r\n\r\ncreate_event')),
      page.locator('#workflowPublish').click()
    ]);
    await page.locator('.workflow-message').filter({hasText:'ثبت اقدام از مرورگر'}).waitFor();
    check(true,'Browser publishes via create_event, not upload_file');
    for(const width of [320,768,1024,1440]){
      await page.setViewportSize({width,height:900});
      const geometry=await page.locator('.workflow-dialog').evaluate(el=>{const r=el.getBoundingClientRect();return {start:r.left,end:r.right,width:innerWidth};});
      check(geometry.start>=0 && geometry.end<=geometry.width,'Modal fits viewport '+width);
    }
    if(process.env.EMCORE_FIXTURE_SCREENSHOT_DIR)await page.screenshot({path:path.join(process.env.EMCORE_FIXTURE_SCREENSHOT_DIR,'procurement-workflow-desktop.png'),fullPage:false});
    check(errors.length===0,'No browser exceptions: '+errors.join('; '));
    await context.close();
    const manager=await browser.newContext({extraHTTPHeaders:{'x-emcore-fixture-actor':'manager'}});
    const managerPage=await manager.newPage();await managerPage.goto(base+'/panel');await managerPage.locator('#workflowQueue').waitFor();
    check(await managerPage.locator('#addButton').isHidden(),'Manager cannot create from UI despite full CRUD permission');
    check(await managerPage.locator('#workflowQueue').inputValue()==='pending_review','Manager starts in review queue');
    await manager.close();console.log(`Procurement workflow browser: ${checks} assertions passed.`);
  }finally{await browser.close();}
}
main().catch(error=>{console.error(error);process.exitCode=1;});
