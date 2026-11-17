'use strict';
const assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs');
const {randomBytes}=require('node:crypto'),{createRequire}=require('node:module');
let req;try{require.resolve('playwright');req=require;}catch{req=createRequire(path.join(process.env.EMCORE_TEST_NODE_MODULES||path.join(process.env.USERPROFILE,'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'),'package.json'));}
const {chromium}=req('playwright'),{expect}=req('playwright/test');
const origin='http://127.0.0.1:33418',number='UI-V4-'+randomBytes(5).toString('hex');
const pdf=Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:fs.existsSync(chromium.executablePath())?undefined:'chrome'});
 try{
  const context=await browser.newContext({viewport:{width:1365,height:1000},timezoneId:'Pacific/Honolulu',acceptDownloads:true});
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  const btn=n=>page.getByRole('button',{name:n,exact:true});
  const modal=page.getByRole('dialog').filter({has:page.locator('.ct-dialog-body')});
  await page.goto(origin+'/panel');await expect(btn('ثبت قرارداد')).toBeVisible();
  await expect(page.locator('.ct-nav>button')).toHaveCount(2);
  await page.evaluate(()=>{const form=document.createElement('form'),panel=document.querySelector('[data-emcore-contracts]');panel.before(form);form.append(panel);window.hostSubmissions=0;form.addEventListener('submit',e=>{window.hostSubmissions++;e.preventDefault();});});
  await btn('ثبت قرارداد').click();await modal.locator('[name=title]').fill('پروندهٔ آزمایش '+number+' <img src=x onerror=alert(1)>');
  await modal.locator('[name=official_number]').fill(number);await modal.locator('[name=contract_type]').fill('خدمات');
  await modal.locator('[name=counterparty_name]').fill('طرف نمونهٔ رابط');await modal.getByRole('button',{name:'ذخیره',exact:true}).click();
  await expect(page.getByRole('tab',{name:'مدارک ۰',exact:true})).toHaveAttribute('aria-selected','true');
  assert.equal(await page.locator('.ct-content img').count(),0);
  await expect(page.getByText('طرف نمونهٔ رابط',{exact:true})).toBeVisible();
  await btn('افزودن مدارک').click();await modal.locator('[name=role_code]').selectOption('main');
  await modal.getByLabel('انتخاب فایل',{exact:true}).setInputFiles([{name:'first-independent.pdf',mimeType:'application/pdf',buffer:pdf},{name:'second-independent.pdf',mimeType:'application/pdf',buffer:pdf}]);
  let failed=false;
  await page.route('**/emcore_contracts.php',route=>{
   if(!failed&&route.request().postData()?.includes('filename="second-independent.pdf"')){failed=true;return route.fulfill({status:500,contentType:'application/json',body:'{"success":false,"error":"خطای آزمایشی فایل دوم"}'});}
   return route.continue();
  });
  await modal.getByRole('button',{name:'ذخیره',exact:true}).click();await modal.getByRole('button',{name:'ادامه از فایل ناموفق',exact:true}).click();
  await expect(modal).not.toBeVisible();await expect(page.locator('.ct-document')).toHaveCount(2);
  const first=page.locator('.ct-document').filter({has:page.getByRole('heading',{name:'first-independent',exact:true})});
  const second=page.locator('.ct-document').filter({has:page.getByRole('heading',{name:'second-independent',exact:true})});
  await expect(first.getByRole('button',{name:'نسخه‌ها (۱)',exact:true})).toBeVisible();await expect(second.getByRole('button',{name:'نسخه‌ها (۱)',exact:true})).toBeVisible();
  const downloadPromise=page.waitForEvent('download');await first.getByRole('button',{name:'دریافت',exact:true}).click();assert.deepEqual(fs.readFileSync(await (await downloadPromise).path()),pdf);
  await first.getByRole('button',{name:'مشاهده',exact:true}).click();await expect(modal.locator('iframe')).toBeVisible();await page.keyboard.press('Escape');await expect(modal).not.toBeVisible();
  await first.getByRole('button',{name:'نسخهٔ جدید',exact:true}).click();await modal.getByLabel('انتخاب فایل',{exact:true}).setInputFiles({name:'readable-scan.pdf',mimeType:'application/pdf',buffer:pdf});
  await modal.getByRole('button',{name:'افزودن نسخه',exact:true}).click();await expect(modal).not.toBeVisible();await expect(first.getByText('first-independent.pdf',{exact:true})).toBeVisible();
  await first.getByRole('button',{name:'نسخه‌ها (۲)',exact:true}).click();await modal.getByRole('button',{name:'تعیین به‌عنوان نسخهٔ جاری',exact:true}).click();
  await expect(modal.locator('tr').filter({hasText:'readable-scan.pdf'}).getByText('جاری',{exact:true})).toBeVisible();await modal.getByRole('button',{name:'بستن',exact:true}).click();
  await expect(first.getByText('readable-scan.pdf',{exact:true})).toBeVisible();
  await page.getByRole('tab',{name:'سوابق ۰',exact:true}).click();await btn('ثبت سابقه').click();
  await modal.locator('[name=category_id]').selectOption('new');await modal.locator('[name=new_category]').fill('گزارش بازدید');
  await modal.locator('[name=description]').fill('گزارش بازدید با دو پیوست دریافت شد.');
  await modal.locator('[name=files]').setInputFiles([{name:'visit.pdf',mimeType:'application/pdf',buffer:pdf},{name:'receipt.pdf',mimeType:'application/pdf',buffer:pdf}]);
  await expect(modal.locator('[name=reminder_due]')).not.toBeVisible();
  await modal.locator('[name=has_reminder]').check();await modal.locator('[name=reminder_title]').fill('پیگیری بازدید '+number);await modal.locator('[name=reminder_due]').fill('۱۴۰۰/۰۱/۰۱');
  await modal.getByRole('button',{name:'ذخیره',exact:true}).click();await expect(modal).not.toBeVisible();
  await expect(page.locator('.ct-history-entry')).toHaveCount(1);await expect(page.locator('.ct-history-entry .ct-attachment')).toHaveCount(2);
  await expect(page.getByRole('tab',{name:'مدارک ۴',exact:true})).toBeVisible();
  await btn('ثبت سابقه').click();await modal.locator('[name=category_id]').selectOption({label:'گزارش بازدید (اختصاصی)'});await modal.locator('[name=description]').fill('سابقهٔ بدون موعد');
  await modal.getByRole('button',{name:'ذخیره',exact:true}).click();await expect(modal).not.toBeVisible();await expect(page.locator('.ct-history-entry')).toHaveCount(2);
  await btn('گزارش بازدید · ۲').click();await expect(page.locator('.ct-history-entry')).toHaveCount(2);
  await page.getByRole('tab',{name:'مشخصات',exact:true}).click();await btn('ویرایش مشخصات').click();
  const calendar=modal.locator('.ct-date-host').filter({has:page.locator('[name=ends_fa]')});await calendar.getByRole('button',{name:'بازکردن تقویم'}).click();await expect(calendar.locator('.ec-calendar')).toBeVisible();
  await page.keyboard.press('Escape');await expect(calendar.locator('.ec-calendar')).not.toBeVisible();await expect(modal).toBeVisible();
  await modal.locator('[name=ends_fa]').fill('۱۴۰۵/۱۲/۲۹');await modal.locator('[name=original_location]').fill('زونکن ۱۲، پوشهٔ ۳');
  await modal.getByRole('button',{name:'ذخیره',exact:true}).click();await expect(modal).not.toBeVisible();await expect(page.getByText('زونکن ۱۲، پوشهٔ ۳',{exact:true})).toBeVisible();
  await btn('یادآوری‌ها').click();await expect(page.getByRole('heading',{name:'یادآوری‌ها',exact:true})).toBeVisible();
  const reminder=page.locator('.ct-reminder').filter({has:page.getByRole('heading',{name:'پیگیری بازدید '+number,exact:true})});await reminder.getByRole('button',{name:'ثبت نتیجه',exact:true}).click();
  await modal.locator('[name=result]').fill('گزارش بررسی شد و نتیجه اعلام شد.');await modal.getByRole('button',{name:'ذخیره',exact:true}).click();await expect(modal).not.toBeVisible();
  await btn('انجام‌شده').click();await expect(reminder).toBeVisible();await reminder.getByRole('button',{name:'پروندهٔ آزمایش '+number+' <img src=x onerror=alert(1)>',exact:true}).click();
  await page.getByRole('tab',{name:'سوابق ۳',exact:true}).click();await expect(page.getByText('گزارش بررسی شد و نتیجه اعلام شد.',{exact:true})).toBeVisible();
  await btn('بازگشت به قراردادها').click();await page.getByRole('searchbox',{name:'جست‌وجوی قراردادها'}).fill(number);
  await expect(page.locator('.ct-contract-row')).toHaveCount(1);
  await btn('فیلتر بیشتر').click();await expect(page.locator('[name=archive]')).toBeVisible();await btn('فیلتر بیشتر').click();
  await page.getByRole('button',{name:'پروندهٔ آزمایش '+number+' <img src=x onerror=alert(1)>',exact:true}).click();
  for(const width of [320,390,806,1365]){
   await page.setViewportSize({width,height:840});await expect(page.locator('.ct-document')).toHaveCount(4);
   assert.ok(await page.locator('[data-emcore-contracts]').evaluate(el=>el.scrollWidth<=el.clientWidth+2),'case fits '+width);
   await page.getByRole('tab',{name:'سوابق ۳',exact:true}).click();await btn('ثبت سابقه').click();
   await modal.locator('[name=has_reminder]').check();
   assert.ok(await modal.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'form fits '+width);
   const save=modal.getByRole('button',{name:'ذخیره',exact:true});await modal.locator('.ct-dialog-body').evaluate(el=>el.scrollTop=el.scrollHeight/2);
   assert.ok(await save.evaluate(el=>{const a=el.getBoundingClientRect(),b=el.closest('.ct-dialog-body').getBoundingClientRect();return a.top>=b.top&&a.bottom<=b.bottom+1;}),'sticky save '+width);
   await modal.getByRole('button',{name:'بستن',exact:true}).first().click();await page.getByRole('tab',{name:'مدارک ۴',exact:true}).click();
  }
  assert.equal(await page.evaluate(()=>window.hostSubmissions),0,'nested forms must not submit Dynaform');assert.deepEqual(errors,[]);
  const reader=await browser.newContext({viewport:{width:390,height:840},extraHTTPHeaders:{'X-Contract-Fixture-Actor':'reader'}}),readPage=await reader.newPage();
  await readPage.goto(origin+'/panel');await expect(readPage.getByRole('heading',{name:'قراردادها',exact:true})).toBeVisible();assert.equal(await readPage.getByRole('button',{name:'ثبت قرارداد',exact:true}).count(),0);
  await readPage.getByRole('searchbox',{name:'جست‌وجوی قراردادها'}).fill(number);await expect(readPage.locator('.ct-contract-row')).toHaveCount(1);await readPage.getByRole('button',{name:'مشاهده',exact:true}).click();
  await expect(readPage.locator('.ct-document')).toHaveCount(4);assert.equal(await readPage.getByRole('button',{name:'افزودن مدارک',exact:true}).count(),0);assert.equal(await readPage.getByRole('button',{name:'نسخهٔ جدید',exact:true}).count(),0);
  await readPage.getByRole('tab',{name:'سوابق ۳',exact:true}).click();assert.equal(await readPage.getByRole('button',{name:'ثبت سابقه',exact:true}).count(),0);await expect(readPage.locator('.ct-history-entry')).toHaveCount(3);
  const creator=await browser.newContext({extraHTTPHeaders:{'X-Contract-Fixture-Actor':'creator'}}),createPage=await creator.newPage();await createPage.goto(origin+'/panel');await createPage.getByRole('button',{name:'ثبت قرارداد',exact:true}).click();assert.equal(await createPage.locator('[name=counterparty_name]').count(),0);
  console.log('Archive-first browser passed: real API, independent-file retry, direct download/preview, explicit versions, multi-file history, optional reminder, category, calendar, completion, mobile, Dynaform and read/create-only permissions.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
