'use strict';
const assert=require('node:assert/strict'), fs=require('node:fs'), crypto=require('node:crypto'),{execFileSync}=require('node:child_process');
const base='http://127.0.0.1:33383',url=base+'/emcore_api/emcore_business_cards.php';let checks=0;
function check(value,message){assert.ok(value,message);checks++;}
function sql(statement){return execFileSync('docker',['exec','emcore-bc-test-db','mysql','-N','-uroot','-pbc-fixture-only','emcore_business_cards_fixture','-e',statement],{encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();}
class Client {
 constructor(actor){this.actor=actor;this.cookie='';this.token='';}
 async call(action,data={},file){
  let body;
  if(file){body=new FormData();for(const [k,v]of Object.entries({...data,action}))body.append(k,String(v));body.append('file',new Blob([file.bytes],{type:file.type||'image/jpeg'}),file.name||'scan.jpg');}
  else body=new URLSearchParams(Object.entries({...data,action}).map(([k,v])=>[k,typeof v==='object'?JSON.stringify(v):String(v)]));
  const response=await fetch(url,{method:'POST',headers:{'X-BC-Fixture-Actor':this.actor,...(this.cookie?{Cookie:this.cookie}:{}),...(this.token?{'X-CSRF-Token':this.token}:{})},body});
  const cookie=response.headers.get('set-cookie');if(cookie)this.cookie=cookie.split(';')[0];
  const binary=(response.headers.get('content-type')||'').startsWith('image/');
  const content=binary?Buffer.from(await response.arrayBuffer()):await response.json();if(content.csrf_token)this.token=content.csrf_token;
  return {status:response.status,body:content,headers:response.headers};
 }
}
const request=()=>crypto.randomBytes(16).toString('hex');
function payload(row={}){return {contact_name:row.contact_name||'آزمایش کارت',organization_name:row.organization_name||'سازمان آزمایشی',job_title:row.job_title||'',business_country_code:row.business_country_code||'IR',related_unit:row.related_unit||'آزمون',source_category:row.source_category||'',activity:row.activity||'',notes:row.notes||'',review_status:row.review_status||'needs_review',contact_points:row.contact_points||[],locations:row.locations||[]};}
(async()=>{
 const admin=new Client('admin'), reader=new Client('reader'),creator=new Client('creator'),editor=new Client('editor'),deleter=new Client('deleter');
 check((await new Client('anonymous').call('list')).status===401,'anonymous');
 check((await new Client('inactive').call('list')).status===401,'inactive');
 check((await new Client('outsider').call('list')).status===403,'ungranted');
 const lookup=await admin.call('lookups');check(lookup.status===200&&lookup.body.data.storage_ready,'storage/GD ready');check(lookup.body.data.countries.length===249,'country seed');
 const seeded=await admin.call('list');check(seeded.body.summary.total===443,'443 seeded cards');check(seeded.body.summary.with_image===304&&seeded.body.summary.without_image===139,'image acceptance counts');check(seeded.body.summary.with_coordinates===295,'295 geolocated cards');
 check(Number(sql('SELECT COUNT(*) FROM emcore_business_card_source_records'))===443,'immutable source coverage');
 check(Number(sql('SELECT COUNT(*) FROM emcore_business_card_locations WHERE latitude IS NOT NULL'))===297,'all 297 points preserved');
 check(Number(sql('SELECT imported_count FROM emcore_business_card_import_batches ORDER BY id DESC LIMIT 1'))===0,'repeat import creates zero');
 check(Number(sql('SELECT COUNT(*) FROM emcore_audit_log WHERE module_key=\'business_cards\' AND action=\'create\''))===443,'one transactional audit per seeded card');
 const austrian=(await admin.call('list',{business_country_code:'AT',location_country:'DE'})).body;
 check(austrian.summary.total>=1,'commercial country and address country remain separate');
 for(const c of [reader,creator,editor,deleter])await c.call('lookups');
 check((await reader.call('create',{...payload(),request_id:request()})).status===403,'reader cannot write');
 const savedToken=admin.token;admin.token='invalid';check((await admin.call('create',{...payload(),request_id:request()})).status===403,'CSRF');admin.token=savedToken;
 check((await admin.call('create',{contact_points:[],locations:[],request_id:request()})).status===422,'identity required for new card');
 check((await admin.call('create',{...payload(),business_country_code:'ZZ',request_id:request()})).status===422,'invalid country');
 check((await admin.call('create',{...payload(),locations:[{latitude:91,longitude:45}],request_id:request()})).status===422,'latitude bound');
 check((await admin.call('create',{...payload(),locations:[{latitude:35}],request_id:request()})).status===422,'coordinate pair');
 const r=request(),p={...payload(),contact_points:[{kind:'mobile',raw_value:'+98 912 1234567'}],locations:[{country_code:'IR',city:'تهران',address:'نشانی آزمایشی',latitude:'35.7',longitude:'51.4',accuracy:'city'}]};
 let created=await admin.call('create',{...p,request_id:r});check(created.status===201&&!created.body.data.image,'create without image');let card=created.body.data;
 check((await admin.call('create',{...p,request_id:r})).body.data.id===card.id,'create replay');
 check((await admin.call('create',{...p,notes:'changed',request_id:r})).status===409,'create replay mismatch');
 const parallelCreateClient=new Client('admin');await parallelCreateClient.call('lookups');const concurrentRequest=request();
 const concurrentCreate=await Promise.all([admin.call('create',{...p,request_id:concurrentRequest}),parallelCreateClient.call('create',{...p,request_id:concurrentRequest})]);
 check(concurrentCreate.every(x=>x.status===201||x.status===200)&&concurrentCreate[0].body.data.id===concurrentCreate[1].body.data.id,'simultaneous create replay');
 check((await creator.call('update',{...p,id:card.id,lock_version:card.lock_version})).status===403,'creator cannot edit');
 let edited=await editor.call('update',{...payload(card),id:card.id,lock_version:card.lock_version,notes:'ویرایش'});check(edited.status===200,'editor can edit');card=edited.body.data;
 check((await admin.call('update',{...p,id:card.id,lock_version:1})).status===409,'stale edit');
 const image=fs.readFileSync('cart/آلمان/1.jpg'),file={bytes:image,name:'کارت.jpg'};
 let fileRequest=request(),version=card.lock_version;
 let uploaded=await admin.call('upload_image',{id:card.id,lock_version:version,request_id:fileRequest},file);check(uploaded.status===201,'single image upload');card=uploaded.body.data;
 check((await admin.call('upload_image',{id:card.id,lock_version:version,request_id:fileRequest},file)).status===200,'upload replay before stale-version check');
 check((await admin.call('upload_image',{id:card.id,lock_version:card.lock_version,request_id:request()},file)).status===409,'second upload blocked');
 check((await admin.call('replace_image',{id:card.id,lock_version:card.lock_version,request_id:request()},{bytes:Buffer.from('not an image'),name:'fake.jpg'})).status===422,'corrupt image');
 check((await admin.call('replace_image',{id:card.id,lock_version:card.lock_version,request_id:request()},{bytes:image,name:'image.png',type:'image/png'})).status===422,'MIME/extension mismatch');
 check((await admin.call('replace_image',{id:card.id,lock_version:card.lock_version,request_id:request()},{bytes:Buffer.alloc(10485761),name:'large.jpg'})).status===422,'upload size bound');
 const oversizedDimensions=Buffer.from(image),sof=[0xc0,0xc1,0xc2].map(marker=>oversizedDimensions.indexOf(Buffer.from([0xff,marker]))).find(offset=>offset>=0);
 assert.ok(sof>=0,'fixture JPEG has a size marker');oversizedDimensions.writeUInt16BE(60000,sof+5);oversizedDimensions.writeUInt16BE(60000,sof+7);
 check((await admin.call('replace_image',{id:card.id,lock_version:card.lock_version,request_id:request()},{bytes:oversizedDimensions,name:'dimensions.jpg'})).status===422,'40-million-pixel bound');
 const original=await reader.call('preview_image',{id:card.id});check(crypto.createHash('sha256').update(original.body).digest('hex')===card.image.sha256,'original intact');
 check((await reader.call('preview_image',{id:card.id,variant:'thumbnail'})).status===422,'thumbnail variant removed');
 const oldImage=card.image.id;
 uploaded=await admin.call('replace_image',{id:card.id,lock_version:card.lock_version,request_id:request()},file);check(uploaded.status===201,'explicit replacement');card=uploaded.body.data;
 check((await reader.call('preview_image',{id:card.id,file_id:oldImage})).status===404,'noncurrent image unavailable');
 check(Number(sql(`SELECT COUNT(*) FROM emcore_business_card_files WHERE card_id=${card.id}`))===2,'previous file retained');
 check(Number(sql(`SELECT COUNT(*) FROM emcore_business_card_files WHERE current_card_id=${card.id}`))===1,'one current image');
 const beforeAudit=Number(sql('SELECT COUNT(*) FROM emcore_audit_log'));
 await reader.call('download_image',{id:card.id});check(Number(sql('SELECT COUNT(*) FROM emcore_business_card_download_log'))===1,'download logged');
 check(Number(sql('SELECT COUNT(*) FROM emcore_audit_log'))===beforeAudit,'preview/download do not mutate card');
 await admin.call('create',{...p,request_id:request()});check((await admin.call('duplicate_candidates',{id:card.id})).body.data.length>=1,'duplicate warning');
 const filter=await admin.call('list',{has_image:1,related_unit:'آزمون'});check(filter.body.summary.total===1&&filter.body.data.length===1,'shared filter counts and no child-join duplication');
 let removed=await deleter.call('delete_image',{id:card.id,lock_version:card.lock_version});check(removed.status===200&&!removed.body.data.image,'delete image creates image-less card');card=removed.body.data;
 const other=new Client('admin');await other.call('lookups');const parallel=await Promise.all([admin.call('upload_image',{id:card.id,lock_version:card.lock_version,request_id:request()},file),other.call('upload_image',{id:card.id,lock_version:card.lock_version,request_id:request()},file)]);
 check(parallel.filter(x=>x.status===201).length===1&&parallel.filter(x=>x.status===409).length===1,'concurrent uploads serialize');card=(await admin.call('get',{id:card.id})).body.data;
 let constraint=false;try{sql(`INSERT INTO emcore_business_card_files (card_id,original_filename,stored_filename,thumbnail_filename,mime_type,size_bytes,width,height,sha256,thumbnail_sha256,request_id,payload_hash,uploaded_by_usr_uid) SELECT card_id,original_filename,stored_filename,thumbnail_filename,mime_type,size_bytes,width,height,sha256,thumbnail_sha256,'${request()}',payload_hash,uploaded_by_usr_uid FROM emcore_business_card_files WHERE current_card_id=${card.id}`);}catch(e){constraint=true;}check(constraint,'database independently forbids two current images');
 sql('UPDATE fixture_flags SET fail_audit=1 WHERE id=1');
 const storedFileCount=()=>Number(execFileSync('docker',['exec','emcore-bc-test-api','php','-r',"echo count(glob('/tmp/business-cards-private/*'));"],{encoding:'utf8'}));
 const oldVersion=card.lock_version,oldFiles=Number(sql('SELECT COUNT(*) FROM emcore_business_card_files')),oldStoredFiles=storedFileCount();
 check((await admin.call('replace_image',{id:card.id,lock_version:card.lock_version,request_id:request()},file)).status===500,'forced audit failure');
 sql('UPDATE fixture_flags SET fail_audit=0 WHERE id=1');card=(await admin.call('get',{id:card.id})).body.data;
 check(card.lock_version===oldVersion&&Number(sql('SELECT COUNT(*) FROM emcore_business_card_files'))===oldFiles,'audit rollback retains old image and version');
 check(storedFileCount()===oldStoredFiles,'audit rollback removes newly staged original');
 const sourceCardId=Number(sql('SELECT card_id FROM emcore_business_card_source_records ORDER BY id LIMIT 1'));
 let sourceCard=(await admin.call('get',{id:sourceCardId})).body.data;
 const sourceEdit=await admin.call('update',{...payload(sourceCard),notes:'USER EDIT MUST SURVIVE REIMPORT',id:sourceCardId,lock_version:sourceCard.lock_version});check(sourceEdit.status===200,'historical values can be preserved during an unrelated edit');
 execFileSync('docker',['exec','emcore-bc-test-api','php','-d','memory_limit=512M','tools/import_business_cards.php','--manifest=dataset/business_cards_manifest.json','--source-root=cart','--actor=00000000000000000000000000000001','--commit'],{stdio:['ignore','pipe','pipe']});
 check((await admin.call('get',{id:sourceCardId})).body.data.notes==='USER EDIT MUST SURVIVE REIMPORT','seed retry preserves user edits');
 const deleted=await deleter.call('delete',{id:card.id,lock_version:card.lock_version});check(deleted.status===200,'soft delete');check((await reader.call('get',{id:card.id})).status===404,'deleted card hidden');check((await reader.call('preview_image',{id:card.id})).status===404,'deleted card image hidden');
 console.log(`Business-card integration passed: ${checks} checks, seed fidelity, permissions, replay, concurrency, image lifecycle and audit rollback.`);
})().catch(e=>{console.error(e);process.exitCode=1;});
