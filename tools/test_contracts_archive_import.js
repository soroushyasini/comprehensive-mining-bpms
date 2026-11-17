'use strict';
// Optional full import against the local fixture. Stage the approved test archive first.
const assert = require('node:assert/strict');
const {randomBytes} = require('node:crypto');
const hash = process.env.EMCORE_CONTRACTS_TEST_MANIFEST;
assert.match(hash || '',/^[a-f0-9]{64}$/,'Set EMCORE_CONTRACTS_TEST_MANIFEST to the staged fixture hash');
const requestId = () => randomBytes(16).toString('hex');
const url = 'http://127.0.0.1:33418/emcore_api/emcore_contracts.php';
let token, cookie;

async function call(action, payload = {}, status = 200) {
    const response = await fetch(url,{
        method:'POST',headers:{Cookie:cookie || '','X-CSRF-Token':token || ''},
        body:new URLSearchParams({action,payload:JSON.stringify(payload)})
    });
    cookie=response.headers.get('set-cookie')?.split(';')[0] || cookie;
    const json=await response.json();
    assert.equal(response.status,status,action+': '+JSON.stringify(json).slice(0,500));
    token=json.csrf_token || token;
    return json.data;
}

(async () => {
    const params={manifest_hash:hash};
    const preview=await call('import_preview',params);
    const initial=await call('import_status',params);
    let cursor=initial.batch ? Number(initial.batch.cursor) : 0;
    const start=cursor;
    while (cursor<preview.total) {
        const payload={...params,cursor,request_id:requestId()};
        const next=await call('import_apply_batch',payload);
        assert.ok(next.cursor>cursor && next.cursor-cursor<=25);
        if (cursor===start) {
            assert.deepEqual(await call('import_apply_batch',payload),next);
            await call('import_apply_batch',{...payload,request_id:requestId()},409);
        }
        cursor=next.cursor;
    }
    const end=await call('import_status',params);
    assert.equal(Number(end.batch.cursor),preview.total);
    const counts=Object.fromEntries(end.counts.map(entry=>[entry.status,Number(entry.total)]));
    assert.equal(Object.values(counts).reduce((sum,n)=>sum+n,0),preview.total);
    const candidate=end.items.find(row=>row.status==='imported');
    if (start===0 && candidate) {
        const caseData=await call('get',{contract_id:candidate.contract_id});
        assert.equal(caseData.contract.review_status,'provisional');
        assert.equal(caseData.contract.ends_on,null);
        assert.equal(caseData.contract.amount,null);
    }
    console.log(JSON.stringify({items:preview.total,cursor,expected_bundles:preview.bundles,counts,started_at:start}));
})().catch(error=>{console.error(error);process.exitCode=1;});
