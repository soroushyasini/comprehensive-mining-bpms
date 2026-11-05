'use strict';
// Focused viewer regression with mocked API data matching the reported layout.
// Supply EMCORE_TEST_JQUERY for a standalone run outside the local fixture cache.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http'),{createRequire}=require('node:module');
const repo=path.resolve(__dirname,'..');
let playwright;try{playwright=require('playwright');}catch{playwright=createRequire(path.join(process.env.EMCORE_TEST_NODE_MODULES||path.join(process.env.USERPROFILE||process.env.HOME,'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'),'package.json'))('playwright');}
const jquery=process.env.EMCORE_TEST_JQUERY||path.join(repo,'.codex-build/procurement-additions-runtime-20261020/jquery.js');
let checks=0;function check(v,m){assert.ok(v,m);checks++;}
const server=http.createServer((req,res)=>{
 let url=new URL(req.url,'http://localhost').pathname;
 if(url==='/panel'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(fs.readFileSync(path.join(repo,'panels/emcore_mines_panel.html'),'utf8').replace('<script>','<script src="/jquery.js"></script><script>'));}
 let file;if(url==='/jquery.js')file=jquery;
 else if(/^\/emcore_assets\/(?:mine-history\.(?:js|css)|emcore-ui\.(?:js|css)|fonts\/Vazirmatn-(?:Regular|Medium|SemiBold|Bold)\.ttf)$/.test(url))file=path.join(repo,url);
 if(!file||!fs.existsSync(file)){res.statusCode=404;return res.end();}
 res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.ttf')?'font/ttf':'application/javascript');res.end(fs.readFileSync(file));
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const base='http://127.0.0.1:'+server.address().port;
 const channel=process.env.EMCORE_TEST_BROWSER_CHANNEL||(process.platform==='win32'&&!fs.existsSync(playwright.chromium.executablePath())?'chrome':undefined);
 const browser=await playwright.chromium.launch({headless:true,channel});
 try{
 for(const mode of ['desktop','mobile','reader','many-desktop','many-mobile']){
  const many=mode.startsWith('many-'),fileCount=many?10:3;
  const context=await browser.newContext({viewport:mode.endsWith('mobile')?{width:390,height:844}:{width:1440,height:1000},timezoneId:mode==='desktop'?'America/Los_Angeles':'Asia/Tehran'});
  const page=await context.newPage(),errors=[],calls=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  const caps={can_read:1,can_create:mode==='reader'?0:1,can_update:mode==='reader'?0:1,can_delete:mode==='reader'?0:1};
  const record={id:1,mine_id:1,category_id:1,lock_version:1,executed_on_fa:'1405/08/14',created_at:'2026-11-05 11:18:44',creator_name:'خدیجه حشمتی',edited_at:'2026-11-05 11:20:00',description:'اصل آنالیزهای گمانه‌ها برای آقای ایمانی خانی ارسال شد.',files:[
   {id:11,original_filename:'Au sample of White Zone.xlsx',file_size:11059},
   {id:12,original_filename:'نتایج کامل آنالیز معدن تپه سیاه شمالی و گزارش نهایی بررسی نمونه‌ها.pdf',file_size:506777},
   {id:13,original_filename:'Au_sample_very_long_filename_without_spaces_012345678901234567890123456789.xlsx',file_size:11059}]};
  if(many)for(let i=14;i<=20;i++)record.files.push({id:i,original_filename:'گزارش کامل نمونه‌های معدن و نتایج آنالیز تکمیلی شماره '+i+'.pdf',file_size:120000});
  await page.route('**/emcore_api/*.php',async route=>{
   const params=new URLSearchParams(route.request().postData()||''),action=params.get('action');calls.push({action,params:Object.fromEntries(params)});
   let data={success:true};
   if(route.request().url().endsWith('emcore_mines.php'))data={...data,data:action==='list'?[{id:1,mine_name:'تپه سیاه شمالی'}]:[],permissions:caps,csrf_token:'test'};
   else if(action==='lookups')data={...data,csrf_token:'test',permissions:caps,today:'2026-11-05',storage_ready:true,max_upload_bytes:20971520,extensions:['pdf'],categories:[{id:1,title:'گزارش نهایی',is_active:1,sort_order:0}]};
   else if(action==='summary')data={...data,mine:{id:1,mine_name:'تپه سیاه شمالی'},data:[{id:1,title:'گزارش نهایی',record_count:3,is_active:1}]};
   else if(action==='list')data={...data,data:[record,{...record,id:2,executed_on_fa:'1405/08/13',created_at:'2026-11-05 11:17:16',edited_at:null,description:'گزارش نهایی تکمیل شده است.\nگزارش دورسنجی در حال تکمیل است.',files:[]},{...record,id:3,created_at:'2025-03-20 23:59:59',description:'اصل تست پایلوت تحویل گرفته شد. <img src=x onerror=alert(1)>',files:[]}],has_more:false,next_offset:3};
   else if(action==='get')data={...data,data:record};
   else if(action==='delete_file'){record.files=record.files.filter(f=>String(f.id)!==params.get('file_id'));record.lock_version++;}
   else if(action==='download')return route.fulfill({status:200,contentType:'application/pdf',body:'%PDF-1.4\n%%EOF'});
   return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
  });
  await page.goto(base+'/panel');await page.locator('.mh-history-trigger').click();await page.locator('.mh-entry').first().waitFor();
  const entry=page.locator('.mh-entry').first();
  check((await entry.locator('.mh-meta').innerText()).includes('۱۴۰۵/۰۸/۱۴، ساعت ۱۱:۱۸:۴۴'),'registration date converted without timezone shift in '+mode);
  check((await page.locator('.mh-entry').nth(2).locator('.mh-meta').innerText()).includes('۱۴۰۳/۱۲/۳۰'),'leap-day registration conversion');
  check(await page.locator('.mh-description img').count()===0,'text remains safely escaped');
  const content=await entry.locator('.mh-entry-content').boundingBox(),files=await entry.locator('.mh-attachments').boundingBox();
  if(mode.endsWith('mobile'))check(files.y>content.y,'mobile stacks attachments below content');
  else{check(files.x+files.width<=content.x,'files occupy left column in RTL');check(files.width/(content.width+files.width)<0.37,'attachments use approximately one third');}
  check(await page.locator('#mh-view .ec-dialog').evaluate(e=>e.scrollWidth<=e.clientWidth),'long filenames do not overflow dialog');
  check(await entry.locator('.mh-filename').count()===fileCount,'all filenames remain available');
  check(await entry.locator('.mh-filename').evaluateAll(es=>es.every(e=>e.getBoundingClientRect().height<=parseFloat(getComputedStyle(e).lineHeight)*2+1)),'filenames use at most two lines');
  if(many){
   const list=entry.locator('.mh-attachment-list');
   check(await list.evaluate(e=>e.clientHeight<=260&&e.scrollHeight>e.clientHeight),'ten-file list scrolls within 260 pixels');
   await entry.locator('.mh-attachment-hint').waitFor({state:'visible'});
   const headingY=(await entry.locator('.mh-attachments h3').boundingBox()).y;
   await list.focus();await page.keyboard.press('End');await page.waitForFunction(()=>document.querySelector('.mh-attachment-list').scrollTop>0);
   check(Math.abs((await entry.locator('.mh-attachments h3').boundingBox()).y-headingY)<1,'attachment heading stays fixed while list scrolls');
   await list.evaluate(e=>e.scrollTop=0);
  }
  check(await entry.locator('.mh-icon-button').evaluateAll(bs=>bs.every(b=>!b.textContent.trim()&&b.getAttribute('title')&&b.getAttribute('aria-label')&&b.querySelector('svg'))),'icon actions retain accessible names and titles');
  check(await page.locator('.mh-entry').nth(1).locator('.mh-attachments').count()===0,'records without files do not reserve a sidebar');
  check(await entry.locator('.mh-meta .mh-entry-actions .mh-icon-button').count()===(mode==='reader'?0:2),'record actions belong to the header');
  check(await entry.locator('.mh-entry-body .mh-entry-actions').count()===0,'content area contains no record toolbar');
  const firstCard=await entry.boundingBox(),nextCard=await page.locator('.mh-entry').nth(1).boundingBox();
  check(nextCard.y-firstCard.y-firstCard.height>=15,'each record has a clear gap from the next');
  check(await entry.evaluate(e=>getComputedStyle(e).borderTopWidth==='1px'&&getComputedStyle(e).borderBottomWidth==='1px'),'each record has a complete enclosing border');
  fs.mkdirSync(path.join(repo,'.codex-build/mine-history-runtime'),{recursive:true});
  if(mode!=='reader')await page.screenshot({path:path.join(repo,'.codex-build/mine-history-runtime/viewer-refined-'+mode+'.png')});
  if(mode==='reader')check(await entry.locator('.mh-icon-button').count()===0,'reader has no mutation icons');
  else{
   check(await entry.getByRole('button',{name:'حذف پیوست',exact:true}).first().evaluate(e=>getComputedStyle(e).color==='rgb(158, 48, 40)'),'delete attachment icon is red');
   const event=page.waitForEvent('download');await entry.getByRole('button',{name:'دانلود Au sample of White Zone.xlsx',exact:true}).click();check((await event).suggestedFilename()==='Au sample of White Zone.xlsx','download action preserves original name');
   await entry.getByRole('button',{name:'حذف پیوست',exact:true}).first().click();await page.waitForFunction(n=>document.querySelectorAll('#mh-view .mh-attachment').length===n,fileCount-1);
   check(calls.some(c=>c.action==='delete_file'&&c.params.file_id==='11'&&c.params.lock_version==='1'),'trash button targets correct attachment/version');
   await page.locator('.mh-entry').first().getByRole('button',{name:'ویرایش',exact:true}).click();await page.locator('#mh-editor').waitFor({state:'visible'});
   check(await page.locator('#mh-editor .mh-icon-button').count()===0 && await page.locator('#mh-editor').getByRole('button',{name:'حذف پیوست',exact:true}).count()===fileCount-1,'editor retains original text controls');
   await page.getByRole('button',{name:'بازگشت به سوابق',exact:true}).click();await page.locator('#mh-view').waitFor({state:'visible'});
   await page.locator('.mh-entry').first().getByRole('button',{name:'حذف',exact:true}).click();await page.waitForResponse(r=>r.url().endsWith('emcore_mine_history.php')&&(r.request().postData()||'').startsWith('action=delete&'));
   check(calls.some(c=>c.action==='delete'&&c.params.id==='1'),'record trash action targets correct record');
  }
  if(many){
   record.files=record.files.slice(0,1);
   await page.locator('#mh-view').getByRole('button',{name:'بستن',exact:true}).click();await page.locator('.mh-history-trigger').click();await page.waitForFunction(()=>document.querySelectorAll('#mh-view .mh-attachment').length===1);
   check(await page.locator('.mh-attachment-list').evaluate(e=>e.clientHeight<150),'single attachment does not reserve a fixed-height panel');
   await page.locator('.mh-attachment-hint').waitFor({state:'hidden'});
  }
  check(errors.length===0,'no runtime errors: '+errors.join('; '));await context.close();
 }
 console.log('Mine history viewer: '+checks+' checks passed (desktop, mobile, permissions, icons, Jalali dates, download and delete).');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(()=>server.close());
