'use strict';
// Run only against the loopback, disposable mine-history fixture.
const assert=require('node:assert/strict'),crypto=require('node:crypto');
const base='http://127.0.0.1:33388',endpoint='/emcore_api/emcore_mine_history.php';
let checks=0;function check(v,m){assert.ok(v,m);checks++;}const rid=()=>crypto.randomBytes(16).toString('hex');
function client(actor){
 let cookie='',token='';
 return {async call(action,data={},status=200,path=endpoint){
  const multipart=data instanceof FormData;
  const body=multipart?data:new URLSearchParams({action,...data});if(multipart)body.set('action',action);
  const r=await fetch(base+path,{method:'POST',body,headers:{'X-MH-Fixture-Actor':actor,...(cookie?{Cookie:cookie}:{}),...(token?{'X-CSRF-Token':token}:{})}});
  const set=r.headers.get('set-cookie');if(set)cookie=set.split(';')[0];
  const text=await r.text();check(r.status===status,`${actor} ${action}: expected ${status}, got ${r.status}: ${text.slice(0,500)}`);
  let value;try{value=JSON.parse(text);}catch{value=text;}if(value.csrf_token)token=value.csrf_token;return value;
 },clearToken(){token='';}};
}
const pdf=Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF');
function upload(record,name='report.pdf',content=pdf,request=rid(),version=record.lock_version){
 const data=new FormData();for(const [k,v] of Object.entries({mine_id:record.mine_id,id:record.id,lock_version:version,request_id:request}))data.set(k,String(v));
 data.set('file',new Blob([content],{type:'application/pdf'}),name);return data;
}
(async()=>{
 const admin=client('admin'),reader=client('reader'),creator=client('creator'),editor=client('editor'),deleter=client('deleter');
 const settings=await admin.call('lookups');check(settings.storage_ready,'private storage ready');check(settings.categories.length===3,'idempotent category seeds');
 for(const c of [reader,creator,editor,deleter])await c.call('lookups');
 for(const name of ['outsider','no_read'])await client(name).call('lookups',{},403);
 for(const name of ['inactive','anonymous'])await client(name).call('lookups',{},401);
 const noCsrf=client('admin');await noCsrf.call('create',{mine_id:1},403);
 const payload={mine_id:1,category_id:1,description:'پیگیری آزمایشی <img src=x onerror=alert(1)>',executed_on_fa:'۱۴۰۵/۰۷/۱۲',request_id:rid()};
 await reader.call('create',payload,403);
 const created=await creator.call('create',payload,201);let record=created.data;
 check(record.executed_on_en==='2026-10-04','Persian date converted');
 const replay=await creator.call('create',payload);check(replay.replayed && replay.data.id===record.id,'create replay does not duplicate');
 await creator.call('create',{...payload,description:'changed'},409);
 const detail=await reader.call('get',{mine_id:1,id:record.id});check(detail.data.description===payload.description,'reader sees exact text');
 await reader.call('get',{mine_id:2,id:record.id},404);
 check((await reader.call('summary',{mine_id:2})).data.length===0,'mine separation');
 await creator.call('update',{...payload,id:record.id,lock_version:record.lock_version},403);
 const updated=await editor.call('update',{...payload,id:record.id,lock_version:record.lock_version,description:'اصلاح توسط کاربر دیگر'});record=updated.data;
 check(!!record.edited_at,'edited badge metadata');
 await editor.call('update',{...payload,id:record.id,lock_version:1},409);
 await admin.call('delete',{id:1},409,'/emcore_api/emcore_mines.php');
 for(const date of ['1404/12/30','1405/07/31','1499/01/01',''])await admin.call('create',{...payload,request_id:rid(),executed_on_fa:date},422);
 await admin.call('create',{...payload,request_id:rid(),description:'م'.repeat(5001)},422);
 await admin.call('create',{...payload,request_id:rid(),category_id:999999},422);
 await reader.call('category_create',{title:'دستهٔ غیرمجاز'},403);
 const category=(await creator.call('category_create',{title:'دستهٔ تست',sort_order:40,is_active:1})).data;
 await admin.call('category_create',{title:' پيگيري  گمانه ها ',sort_order:0,is_active:1},409);
 const catOld=settings.categories[0];
 const disabled=(await editor.call('category_update',{id:1,lock_version:catOld.lock_version,title:catOld.title,sort_order:10,is_active:0})).data;
 await admin.call('create',{...payload,request_id:rid()},422);
 record=(await editor.call('update',{...payload,id:record.id,lock_version:record.lock_version})).data;
 check(Number(record.category_id)===1,'inactive current category retained on edit');
 await editor.call('category_update',{id:1,lock_version:disabled.lock_version,title:catOld.title,sort_order:10,is_active:1});
 const rec2=(await admin.call('create',{...payload,request_id:rid(),category_id:category.id} ,201)).data;
 await reader.call('upload',upload(rec2),403);await editor.call('upload',upload(rec2),403);
 await admin.call('upload',upload(rec2,'report.php'),422);
 await admin.call('upload',upload(rec2,'report.pdf',Buffer.from('not a PDF')),422);
 await admin.call('upload',upload(rec2,'report.pdf',Buffer.alloc(20*1024*1024+1)),422);
 const uploadId=rid();const first=(await creator.call('upload',upload(rec2,'report.pdf',pdf,uploadId),201));
 check(!first.data.storage_path && !first.data.sha256,'private storage metadata withheld');
 const again=await creator.call('upload',upload(rec2,'report.pdf',pdf,uploadId));check(again.replayed && again.data.id===first.data.id,'upload replay accepted with old lock version');
 await creator.call('upload',upload(rec2,'other.pdf',pdf,uploadId),409);
 await creator.call('upload',upload(rec2),409);
 let fileRecord=(await reader.call('get',{mine_id:1,id:rec2.id})).data;
 for(let i=1;i<10;i++){
  const result=await creator.call('upload',upload(fileRecord,'report-'+i+'.pdf'),201);fileRecord.lock_version=result.lock_version;
 }
 await creator.call('upload',upload(fileRecord),422);
 const downloaded=await reader.call('download',{mine_id:1,id:rec2.id,file_id:first.data.id});check(downloaded.startsWith('%PDF-'),'authenticated download');
 await reader.call('download',{mine_id:2,id:rec2.id,file_id:first.data.id},404);
 await client('outsider').call('download',{mine_id:1,id:rec2.id,file_id:first.data.id},403);
 await creator.call('delete_file',{mine_id:1,id:rec2.id,file_id:first.data.id,lock_version:fileRecord.lock_version},403);
 await deleter.call('delete_file',{mine_id:1,id:rec2.id,file_id:first.data.id,lock_version:fileRecord.lock_version});
 await reader.call('download',{mine_id:1,id:rec2.id,file_id:first.data.id},404);
 fileRecord=(await reader.call('get',{mine_id:1,id:rec2.id})).data;
 await deleter.call('delete',{mine_id:1,id:rec2.id,lock_version:fileRecord.lock_version});
 await reader.call('download',{mine_id:1,id:rec2.id,file_id:first.data.id+1},404);
 const fresh=(await admin.call('create',{...payload,request_id:rid()},201)).data;
 await admin.call('',{enabled:1},200,'/fixture/audit-failure');
 await admin.call('upload',upload(fresh),500);
 await admin.call('create',{...payload,request_id:rid()},500);
 await admin.call('',{enabled:0},200,'/fixture/audit-failure');
 const rollback=(await reader.call('get',{mine_id:1,id:fresh.id})).data;
 check(rollback.files.length===0 && rollback.lock_version===fresh.lock_version,'audit failure rolls back upload and version');
 for(let i=0;i<28;i++)await admin.call('create',{...payload,request_id:rid(),description:'سابقهٔ صفحه‌بندی '+i},201);
 const page1=await reader.call('list',{mine_id:1,category_id:1});const page2=await reader.call('list',{mine_id:1,category_id:1,offset:page1.next_offset});
 check(page1.data.length===25 && page1.has_more,'page size and next cursor');
 check(!page1.data.some(a=>page2.data.some(b=>b.id===a.id)) && page2.data.length>0,'pages do not overlap');
 await admin.call('delete',{id:3},200,'/emcore_api/emcore_mines.php');
 console.log(`Mine history integration: ${checks} checks passed (real PHP/database/HTTP).`);
})().catch(e=>{console.error(e);process.exitCode=1;});
