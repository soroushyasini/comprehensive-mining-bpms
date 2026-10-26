'use strict';
// Real multipart upload against the disposable loopback fixture, never production.
const assert=require('node:assert/strict'),crypto=require('node:crypto');
const base='http://127.0.0.1:33380',endpoint=base+'/emcore_api/emcore_procurement_notices.php';
const id=()=>crypto.randomBytes(16).toString('hex');
let cookie='',csrf='',checks=0;
async function api(action,data={},status=200){
  const headers={'x-emcore-fixture-actor':'operator'};
  if(cookie)headers.cookie=cookie;if(csrf)headers['x-csrf-token']=csrf;
  const response=await fetch(endpoint,{method:'POST',headers,body:data instanceof FormData?data:new URLSearchParams({action,...data})});
  if(response.headers.get('set-cookie'))cookie=response.headers.get('set-cookie').split(';')[0];
  const text=await response.text();assert.equal(response.status,status,text);checks++;
  const json=JSON.parse(text);if(json.csrf_token)csrf=json.csrf_token;return json;
}
const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7xkAAAAASUVORK5CYII=','base64');
async function publish(notice,bytes,status){
  const request=id(),form=new FormData();
  for(const [key,value] of Object.entries({action:'create_event',id:notice,request_id:request,body:'آزمون پیوست چندفایلی با حجم '+bytes}))form.append(key,value);
  const count=11;
  for(let index=0;index<count;index++){
    const size=Math.floor(bytes/count)+(index<bytes%count?1:0),data=Buffer.alloc(size);png.copy(data);
    form.append('attachments[]',new Blob([data],{type:'image/png'}),'boundary-'+index+'.png');
  }
  return api(null,form,status);
}
async function main(){
  await api('lookups');
  const notice=(await api('create',{notice_type:'tender',title:'آزمون مجموع پیوست‌ها '+id(),registered_on_fa:'1405/06/31',participation_status:'registered',request_id:id()},201)).id;
  const totals=[55*1048576];if(process.argv.includes('--large-boundaries'))totals.push(200*1048576);
  for(const bytes of totals){
    const created=await publish(notice,bytes,200),events=(await api('list_events',{id:notice})).data;
    const event=events.find(row=>row.id==created.event_id);
    assert.equal(event.files.length,11);checks++;
    assert.equal(event.files.reduce((sum,file)=>sum+Number(file.file_size),0),bytes);checks++;
  }
  if(process.argv.includes('--large-boundaries')){
    const before=(await api('list_events',{id:notice})).pagination.total;
    const rejected=await publish(notice,200*1048576+1,422);
    assert.ok(rejected.error.includes('۲۰۰ مگابایت'));checks++;
    assert.equal((await api('list_events',{id:notice})).pagination.total,before);checks++;
  }
  console.log('Procurement real multi-attachment upload: '+checks+' assertions passed.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
