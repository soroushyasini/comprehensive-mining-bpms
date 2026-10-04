'use strict';
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const {createRequire}=require('node:module');
let playwright;
try{playwright=require('playwright');}catch(error){
  const bundled=process.env.EMCORE_TEST_NODE_MODULES || path.join(process.env.USERPROFILE || '', '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules');
  playwright=createRequire(path.join(bundled,'package.json'))('playwright');
}
const url='http://127.0.0.1:33382/panel';
const pdf=Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');
let checks=0;
async function check(value,message){assert.ok(value,message);checks++;}
(async()=>{
  const channel=process.env.EMCORE_TEST_BROWSER_CHANNEL || (process.platform==='win32' && !fs.existsSync(playwright.chromium.executablePath())?'chrome':undefined);
  const browser=await playwright.chromium.launch({headless:true,channel});
  try{
    const context=await browser.newContext({viewport:{width:1440,height:1000}});
    const page=await context.newPage(),errors=[];
    page.on('pageerror',e=>errors.push(e.message));page.on('dialog',dialog=>dialog.accept());
    await page.goto(url);await page.locator('#mm-total').filter({hasText:/\d|[۰-۹]/}).waitFor();
    const serverLookups=await (await context.request.post('http://127.0.0.1:33382/emcore_api/emcore_meeting_minutes.php',{form:{action:'lookups'}})).json();
    const expectedToday=await page.evaluate(iso=>window.EmcoreUI.todayFromGregorian(iso),serverLookups.data.today_gregorian);
    await check(await page.locator('#mm-rows').textContent().then(t=>t.includes('<img src=x onerror=alert(1)>')),'stored XSS rendered as text');
    await check(await page.locator('#mm-rows img').count()===0,'no injected image');
    await check(await page.locator('#mm-create').isVisible(),'authorized create visible');
    await page.locator('#mm-create').click();await page.locator('#mm-overlay').waitFor({state:'visible'});
    await check(await page.locator('#mm-company').evaluate(e=>e.getAttribute('disabled')===null),'editable company selection');
    await check(await page.locator('#mm-date').inputValue()===expectedToday,'Tehran default date from API');
    await page.locator('#mm-date').fill('۱۴۰۵/۰۷/۱۲');await page.locator('#mm-date').dispatchEvent('change');
    await page.locator('#mm-company').selectOption('1');await page.locator('#mm-number').filter({visible:true}).waitFor();
    await page.waitForFunction(()=>document.querySelector('#mm-number').value.startsWith('EMIDCO/1405/'));
    const tentative=await page.locator('#mm-number').inputValue();await page.locator('#mm-close').click();await page.locator('#mm-create').click();
    await page.locator('#mm-date').fill('۱۴۰۵/۰۷/۱۲');await page.locator('#mm-date').dispatchEvent('change');
    await page.locator('#mm-company').selectOption('1');await page.waitForFunction(()=>document.querySelector('#mm-number').value.startsWith('EMIDCO/1405/'));
    await check(await page.locator('#mm-number').inputValue()===tentative,'cancel does not consume number');
    await page.locator('#mm-date').locator('..').getByRole('button',{name:'بازکردن تقویم'}).click();
    const calendar=page.locator('#mm-date').locator('..').locator('.ec-calendar');await check(await calendar.isVisible(),'calendar opens');
    await page.keyboard.press('ArrowLeft');await page.keyboard.press('Escape');await check(await calendar.isHidden(),'escape closes calendar only');
    await check(await page.locator('#mm-overlay').isVisible(),'escape in calendar preserves outer modal');
    await page.locator('#mm-title').fill('جلسه مرورگر');await page.locator('#mm-agenda').fill('بررسی پنل و آرشیو');
    await page.locator('#mm-chair input[role=combobox]').fill('clerk');await page.locator('#mm-chair [role=option]').first().waitFor();
    await page.locator('#mm-chair input[role=combobox]').press('ArrowDown');await page.locator('#mm-chair input[role=combobox]').press('Enter');
    await page.locator('#mm-secretary input[role=combobox]').fill('دبیر بیرونی');await page.locator('#mm-secretary input[aria-label="سازمان فرد بیرونی"]').fill('سازمان مهمان');await page.locator('#mm-secretary').getByRole('button',{name:'افزودن فرد بیرونی'}).click();
    await check(await page.locator('#mm-present .ec-chip').count()===2,'officers automatically present');
    await page.locator('#mm-save').click();await page.locator('#mm-upload-section').waitFor({state:'visible'});await page.waitForFunction(()=>!document.querySelector('#mm-save').disabled);
    await check((await page.locator('#mm-record-state').textContent()).includes('آمادهٔ بارگذاری اسکن'),'saved record awaits scan');
    await check(await page.locator('#mm-start').inputValue()==='' && await page.locator('#mm-end').inputValue()==='','normal meeting saved with both times empty');
    await check((await page.locator('#mm-record-state').textContent()).includes('اطلاعات سربرگ کامل'),'optional times do not make metadata incomplete');
    await page.locator('#mm-file-input').setInputFiles([{name:'صفحه اول.pdf',mimeType:'application/pdf',buffer:pdf},{name:'صفحه دوم.pdf',mimeType:'application/pdf',buffer:pdf}]);
    await page.locator('#mm-upload').click();await page.locator('#mm-progress-label').filter({hasText:'بارگذاری فایل‌ها کامل شد.'}).waitFor();
    await check(await page.locator('#mm-file-list .ec-file').count()===2,'multiple scans queued');
    await check((await page.locator('#mm-record-state').textContent()).includes('اسکن بایگانی شده'),'scan status updates after upload');
    await check(await page.locator('#mm-file-list button').first().isEnabled(),'file actions reenabled after queue');
    await page.locator('#mm-file-list').getByRole('button',{name:'جایگزینی اسکن'}).first().click();
    await page.locator('#mm-replace-reason').fill('نسخه خواناتر');await page.locator('#mm-file-input').setInputFiles({name:'نسخه جدید.pdf',mimeType:'application/pdf',buffer:Buffer.concat([pdf,Buffer.from('\n%new')])});
    await page.locator('#mm-upload').click();await page.waitForFunction(()=>!document.querySelector('#mm-file-history').hidden);
    await page.locator('#mm-file-history summary').click();await check(await page.locator('#mm-history-list .ec-file').count()===1,'scan history retained in UI');
    const output=path.join(__dirname,'../output/meeting-minutes');fs.mkdirSync(output,{recursive:true});
    await page.screenshot({path:path.join(output,'desktop-modal.png')});
    // A two-file batch fails on its second file once. Retry must skip the first.
    let failNext=true,uploadRequests=0;
    await page.route('**/emcore_api/emcore_meeting_minutes.php',async route=>{
      const body=route.request().postData() || '';
      if(body.includes('name="action"\r\n\r\nupload_file')){
        uploadRequests++;
        if(failNext && body.includes('fail-once.pdf')){failNext=false;await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({success:false,error:'خطای آزمایشی اتصال'})});return;}
      }
      await route.continue();
    });
    await page.locator('#mm-file-input').setInputFiles([{name:'batch-good.pdf',mimeType:'application/pdf',buffer:pdf},{name:'fail-once.pdf',mimeType:'application/pdf',buffer:pdf}]);
    await page.locator('#mm-upload').click();await page.locator('#mm-form-error').filter({hasText:'خطای آزمایشی اتصال'}).waitFor();
    const countAfterFailure=await page.locator('#mm-file-list .ec-file').count();await page.locator('#mm-upload').click();
    await page.waitForFunction(()=>document.querySelector('#mm-progress-label').textContent==='بارگذاری فایل‌ها کامل شد.' && document.querySelector('#mm-form-error').hidden);
    await check(await page.locator('#mm-file-list .ec-file').count()===countAfterFailure+1,'retry adds only remaining file');await check(uploadRequests===3,'queue did not resend completed file');await page.unroute('**/emcore_api/emcore_meeting_minutes.php');
    await page.locator('#mm-close').click();await check(await page.locator('#mm-create').evaluate(e=>e===document.activeElement),'modal restores trigger focus');
    await page.locator('#mm-search').fill('جلسه مرورگر');await page.locator('#mm-filters').getByRole('button',{name:'اعمال فیلترها'}).click();await page.waitForFunction(()=>document.querySelector('#mm-total').textContent==='۱');
    await check(await page.locator('#mm-rows tr').count()===1,'filter updates table and summary');
    await page.locator('#mm-create').click();await page.locator('#mm-legacy').check();await check(await page.locator('#mm-date').inputValue()==='','legacy has no invented date');
    await check(await page.locator('#mm-number').getAttribute('readonly')===null,'legacy number editable');
    await page.locator('#mm-company').selectOption('1');await page.locator('#mm-number').fill('browser-old-43');await page.locator('#mm-title').fill('آرشیو ناقص مرورگر');await page.locator('#mm-save').click();await page.locator('#mm-upload-section').waitFor({state:'visible'});
    await check((await page.locator('#mm-record-state').textContent()).includes('اطلاعات سربرگ ناقص'),'legacy metadata independent');
    await page.locator('#mm-close').click();await page.locator('#mm-reset').click();await page.waitForFunction(()=>document.querySelector('#mm-search').value==='');
    const mobile=await browser.newContext({viewport:{width:390,height:844}}),phone=await mobile.newPage();await phone.goto(url);await phone.locator('#mm-create').waitFor({state:'visible'});
    await check(await phone.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'mobile page does not overflow');
    await phone.locator('#mm-create').click();await phone.locator('#mm-save').focus();await phone.keyboard.press('Tab');await check(await phone.locator('#mm-close').evaluate(e=>e===document.activeElement),'modal Tab wraps');
    await phone.screenshot({path:path.join(output,'mobile.png')});
    await page.screenshot({path:path.join(output,'desktop.png')});
    const readonly=await browser.newContext({extraHTTPHeaders:{'X-Minutes-Fixture-Actor':'reader'}}),readpage=await readonly.newPage();await readpage.goto(url);await readpage.locator('#mm-total').filter({hasText:/[۰-۹]/}).waitFor();
    await check(await readpage.locator('#mm-create').isHidden(),'read-only create hidden');await check(await readpage.locator('#mm-rows').getByRole('button',{name:'بارگذاری',exact:true}).count()===0,'read-only upload hidden');
    await readpage.locator('#mm-rows').getByRole('button',{name:'مشاهده',exact:true}).first().click();await readpage.locator('#mm-overlay').waitFor({state:'visible'});await check(await readpage.locator('#mm-save').isHidden(),'read-only save hidden');await check(await readpage.locator('#mm-title').isDisabled(),'read-only metadata disabled');
    await check(errors.length===0,'no browser JavaScript errors: '+errors.join('; '));
    console.log(`Meeting minutes browser: ${checks} checks passed (Chromium / jQuery 1.11.3, desktop and mobile).`);
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
