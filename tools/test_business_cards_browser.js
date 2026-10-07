'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{createRequire}=require('node:module');
let playwright;try{playwright=require('playwright');}catch(e){const modules=process.env.EMCORE_TEST_NODE_MODULES||path.join(process.env.USERPROFILE||'','.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules');playwright=createRequire(path.join(modules,'package.json'))('playwright');}
let checks=0;function check(value,message){assert.ok(value,message);checks++;}
(async()=>{
 const channel=process.env.EMCORE_TEST_BROWSER_CHANNEL||(process.platform==='win32'&&!fs.existsSync(playwright.chromium.executablePath())?'chrome':undefined);
 const browser=await playwright.chromium.launch({headless:true,channel});
 try{
  const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage(),errors=[];
  const initialImageRequests=[];page.on('request',r=>{if((r.postData()||'').includes('action=preview_image'))initialImageRequests.push(r);});
  page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  await page.goto('http://127.0.0.1:33383/panel');await page.locator('#bc-summary p').first().waitFor();
  // Panel WebControls live inside ProcessMaker's case form, unlike standalone pages.
  await page.evaluate(()=>{
   const root=document.querySelector('#bc-root'),host=document.createElement('form');
   host.id='pm-case-form';host.action='/cases_NextStep';root.before(host);host.append(root);
   window.__bcHostSubmits=0;host.addEventListener('submit',event=>{window.__bcHostSubmits++;event.preventDefault();});
  });
  await page.locator('#bc-rows').getByRole('button',{name:'جزئیات و ویرایش'}).first().click();await page.locator('#bc-editor-overlay').waitFor({state:'visible'});
  check(await page.evaluate(()=>window.__bcHostSubmits===0),'editing a card never submits the ProcessMaker case form');
  await page.locator('#bc-editor-close').click();
  check(await page.locator('#bc-root form').count()===0,'panel contains no nested forms inside the case form');
  const listedId=await page.locator('#bc-rows tr').first().locator('td').first().textContent();
  check(/^\d+$/.test(listedId)&&await page.locator('#bc-table th').first().textContent()==='شناسه کارت','first table column displays the database card ID');
  await page.locator('#bc-rows').getByRole('button',{name:'جزئیات و ویرایش'}).first().click();await page.locator('#bc-editor-overlay').waitFor({state:'visible'});
  check(await page.locator('#bc-editor-title').textContent()==='کارت ویزیت '+listedId.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),'table ID identifies the same record opened for editing');
  await page.locator('#bc-contact-name').press('Enter');await page.locator('#bc-editor-close').click();
  await page.locator('#bc-table th[data-sort="id"] button').click();
  await page.waitForResponse(r=>r.url().endsWith('emcore_business_cards.php')&&(r.request().postData()||'').includes('sort_order=asc'));
  check(await page.locator('#bc-rows tr').first().locator('td').first().textContent()==='1','card ID sorts by its database value');
  await page.locator('#bc-next').click();await page.locator('#bc-page').filter({hasText:'صفحهٔ ۲'}).waitFor();
  await page.locator('#bc-prev').click();await page.locator('#bc-page').filter({hasText:'صفحهٔ ۱'}).waitFor();
  await page.locator('#bc-search').press('Enter');await page.waitForResponse(r=>r.url().endsWith('emcore_business_cards.php')&&(r.request().postData()||'').includes('action=list'));
  check(await page.evaluate(()=>window.__bcHostSubmits===0),'sorting, paging, and Enter in panel inputs never submit the case form');
  check(initialImageRequests.length===0&&await page.locator('#bc-rows img').count()===0,'archive table fetches no images before a popup is opened');
  await page.evaluate(()=>{
   window.__bcRevoked=[];window.__bcOriginalCallbacks=[];window.__bcAborts=0;
   const revoke=URL.revokeObjectURL.bind(URL);URL.revokeObjectURL=url=>{window.__bcRevoked.push(url);revoke(url);};
   const send=XMLHttpRequest.prototype.send,abort=XMLHttpRequest.prototype.abort;
   XMLHttpRequest.prototype.send=function(body){if(typeof body==='string'&&body.includes('action=preview_image')&&body.includes('variant=original'))window.__bcOriginalCallbacks.push(this.onload.bind(this));return send.call(this,body);};
   // Retain an already completed Blob response to replay its queued load callback.
   // Pending requests still use the real abort implementation.
   XMLHttpRequest.prototype.abort=function(){window.__bcAborts++;if(this.readyState===4&&this.responseType==='blob')return;return abort.call(this);};
  });
  check(await page.locator('#bc-new').isVisible(),'authorized create visible');
  const countrySearch=page.locator('#bc-country + .bc-country-picker input');
  check(await page.locator('#bc-country').isHidden(),'original country dropdown hidden');
  await countrySearch.fill('Germany');await page.locator('#bc-country-suggestions').getByRole('option',{name:/Germany/}).click();
  check(await page.locator('#bc-country').inputValue()==='DE'&&await countrySearch.inputValue()==='آلمان','English country suggestion stores ISO code');
  await countrySearch.fill('ايران');await page.keyboard.press('ArrowDown');await page.keyboard.press('Enter');
  check(await page.locator('#bc-country').inputValue()==='IR','Persian country suggestion normalizes Arabic y and supports keyboard');
  await page.locator('#bc-reset').click();check(await countrySearch.inputValue()===''&&await countrySearch.evaluate(e=>e.validity.valid),'filter reset clears suggestion and validation');
  await page.locator('#bc-has-image').selectOption('1');await page.locator('#bc-filters').getByRole('button',{name:'اعمال فیلتر'}).click();
  await page.waitForResponse(r=>r.url().endsWith('emcore_business_cards.php')&&(r.request().postData()||'').includes('action=list'));
  const button=page.locator('#bc-rows').getByRole('button',{name:'نمایش کارت ویزیت'}).first();await button.click();
  await page.waitForFunction(()=>!document.querySelector('#bc-full-image').hidden&&document.querySelector('#bc-full-image').naturalWidth>0);
  check(await page.locator('#bc-image-overlay').isVisible(),'image popup opens');
  check(await page.locator('#bc-full-image').evaluate(e=>e.src.startsWith('blob:')),'authorized Blob display');
  const originalUrl=await page.locator('#bc-full-image').getAttribute('src');
  await page.keyboard.press('Tab');check(await page.locator('#bc-image-overlay').evaluate(e=>e.contains(document.activeElement)),'popup focus stays inside');
  await page.keyboard.press('Escape');check(await page.locator('#bc-image-overlay').isHidden(),'escape closes image popup');
  check(await button.evaluate(e=>e===document.activeElement),'focus returns to table button');
  check(await page.evaluate(url=>window.__bcRevoked.includes(url),originalUrl),'closed image Blob revoked');
  await page.locator('#bc-rows').getByRole('button',{name:'نمایش کارت ویزیت'}).nth(1).click();
  await page.waitForFunction(()=>!document.querySelector('#bc-full-image').hidden&&document.querySelector('#bc-full-image').naturalWidth>0);
  const nextUrl=await page.locator('#bc-full-image').getAttribute('src');
  await page.evaluate(()=>window.__bcOriginalCallbacks[0]());
  check(await page.locator('#bc-full-image').getAttribute('src')===nextUrl,'late previous-card callback cannot replace current image');
  await page.keyboard.press('Escape');
  await page.route('**/emcore_business_cards.php',async route=>{
   const body=route.request().postData()||'';
   if(body.includes('action=preview_image')&&body.includes('variant=original'))await route.fulfill({status:403,contentType:'application/json',body:JSON.stringify({error:'نشست منقضی شده است <b>متن</b>'})});
   else await route.continue();
  });
  await button.click();await page.locator('#bc-image-message').filter({hasText:'نشست منقضی شده است'}).waitFor();
  check(await page.locator('#bc-image-message b').count()===0,'image API error safely shown inside popup');
  check(await page.locator('#bc-download').isDisabled(),'failed preview keeps download disabled');
  await page.keyboard.press('Escape');await page.unroute('**/emcore_business_cards.php');
  check(await page.evaluate(()=>window.__bcAborts>0),'closing image popup aborts its request');
  await page.locator('#bc-rows').getByRole('button',{name:'جزئیات و ویرایش'}).first().click();await page.locator('#bc-editor-overlay').waitFor({state:'visible'});
  await page.locator('#bc-editor-preview').click();await page.locator('#bc-image-overlay').waitFor({state:'visible'});
  check(await page.locator('#bc-editor-overlay').isHidden(),'editor is paused while image popup is open');
  await page.keyboard.press('Escape');await page.locator('#bc-editor-overlay').waitFor({state:'visible'});check(await page.locator('#bc-editor-preview').evaluate(e=>e===document.activeElement),'focus restored to editor image button');
  await page.locator('#bc-editor-close').click();
  await page.locator('#bc-new').click();await page.locator('#bc-contact-name').fill('<img src=x onerror=alert(1)>');await page.locator('#bc-organization-name').fill('تست مرورگر');
  const businessSearch=page.locator('#bc-business-country + .bc-country-picker input');await businessSearch.fill('France');await page.locator('#bc-business-country-suggestions').getByRole('option',{name:/France/}).click();
  await businessSearch.fill('not a country');await page.locator('#bc-save').click();
  check(await page.locator('#bc-editor-title').textContent()==='ثبت کارت ویزیت'&&!await businessSearch.evaluate(e=>e.validity.valid),'invalid country suggestion prevents saving without relying on a nested form');
  check(await page.locator('#pm-case-form').evaluate(e=>e.checkValidity()),'local invalid country does not block native case-form validation');
  await businessSearch.fill('France');await page.locator('#bc-business-country-suggestions').getByRole('option',{name:/France/}).click();
  await page.locator('#bc-add-contact').click();await page.locator('.bc-point-value').fill('+98 912 5555555');
  await page.locator('#bc-add-location').click();await page.locator('.bc-location-city').fill('تهران');
  const locationSearch=page.locator('.bc-location-country + .bc-country-picker input');await locationSearch.fill('Iran');await page.locator('.bc-location-country + .bc-country-picker').getByRole('option',{name:/Iran/}).click();
  check(await page.locator('.bc-location-country').inputValue()==='IR','address country suggestion stores selection');
  await page.locator('#bc-save').click();await page.locator('#bc-editor-message').filter({hasText:'اطلاعات ذخیره شد.'}).waitFor();
  check(await businessSearch.inputValue()==='فرانسه'&&await page.locator('#bc-business-country').inputValue()==='FR','commercial country suggestion survives save and reload');
  check(await page.locator('#bc-image-file').getAttribute('multiple')===null,'exactly one file selectable');
  check(await page.locator('#bc-editor-preview').isDisabled(),'no image means preview disabled');
  await page.locator('#bc-image-file').setInputFiles('cart/آلمان/1.jpg');await page.locator('#bc-upload').click();await page.locator('#bc-editor-message').filter({hasText:'تصویر ذخیره شد.'}).waitFor();
  check(await page.locator('#bc-upload').textContent()==='جایگزینی تصویر','single image replacement control');
  await page.locator('#bc-editor-preview').click();await page.waitForFunction(()=>!document.querySelector('#bc-full-image').hidden&&document.querySelector('#bc-full-image').naturalWidth>0);await page.locator('#bc-image-close').click();await page.locator('#bc-editor-overlay').waitFor({state:'visible'});
  await page.locator('#bc-editor-close').click();await page.locator('#bc-reset').click();await page.locator('#bc-rows').filter({hasText:'<img src=x onerror=alert(1)>'}).waitFor();
  check(await page.locator('#bc-rows img[src="x"]').count()===0,'stored XSS remains text');
  await page.locator('#bc-has-image').selectOption('0');await page.locator('#bc-filters').getByRole('button',{name:'اعمال فیلتر'}).click();await page.waitForFunction(()=>document.querySelector('#bc-rows button')&&document.querySelector('#bc-rows button').disabled);
  check(await page.locator('#bc-rows').getByRole('button',{name:'نمایش کارت ویزیت'}).first().isDisabled(),'image-less table action disabled');
  await page.setViewportSize({width:375,height:812});check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'mobile document does not overflow');
  await page.locator('#bc-new').click();check(await page.locator('#bc-editor-overlay').isVisible(),'mobile form opens');await page.keyboard.press('Escape');
  check(await page.evaluate(()=>window.__bcHostSubmits===0),'all editor, filter, preview, upload, and close actions leave the case form unsubmitted');
  check(await page.locator('#bc-root button:not([type="button"])').count()===0,'all static and dynamic panel buttons have a non-submit type');
  check(await page.locator('#bc-root').evaluate(e=>[...e.querySelectorAll('input,textarea,select')].every(field=>field.form===null)),'static and repeated panel fields stay outside native case submission');
  const reader=await browser.newContext({viewport:{width:1440,height:1000}});await reader.addCookies([{name:'bc_fixture_actor',value:'reader',url:'http://127.0.0.1:33383'}]);const readPage=await reader.newPage();
  await readPage.route('**/panel',async route=>{
   const response=await route.fetch(),html=await response.text();
   await route.fulfill({response,body:html.replace('<body>','<body><form id="pm-parsed-case" action="/cases_NextStep">').replace('</body>','</form></body>')});
  });
  await readPage.goto('http://127.0.0.1:33383/panel');await readPage.locator('#bc-summary p').first().waitFor();check(await readPage.locator('#bc-new').isHidden(),'read-only create hidden');
  check(await readPage.locator('#pm-parsed-case #bc-root').count()===1&&await readPage.locator('#bc-root form').count()===0,'panel survives initial HTML parsing inside a ProcessMaker case form');
  await readPage.locator('#bc-rows').getByRole('button',{name:'جزئیات',exact:true}).first().click();await readPage.locator('#bc-editor-overlay').waitFor({state:'visible'});
  check(await readPage.locator('#bc-contact-name').isDisabled()&&await readPage.locator('#bc-save').isHidden(),'read-only detail disabled');
  check(await readPage.locator('#bc-business-country + .bc-country-picker input').isDisabled(),'read-only country suggestion disabled');
  check(errors.length===0,'no browser script errors: '+errors.join(';'));
  console.log(`Business-card browser passed: ${checks} checks, popup, Blob cleanup, keyboard, one-image form, XSS, mobile and read-only.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
