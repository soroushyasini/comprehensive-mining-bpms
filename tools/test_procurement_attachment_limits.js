'use strict';
// Exercise the actual panel validator without allocating large browser files.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const read=file=>fs.readFileSync(path.join(__dirname,'..',file),'utf8');
const panel=read('panels/emcore_procurement_notices_panel.html');
const extract=name=>panel.match(new RegExp('  function '+name+'\\([^\\n]*\\) \\{[\\s\\S]*?\\n  \\}'))[0].trim();
const context=vm.createContext({faNumber:value=>Number(value).toLocaleString('fa-IR')});
vm.runInContext(extract('fileSize')+'\n'+extract('workflowAttachmentError'),context);
const error=files=>context.workflowAttachmentError(files);
const files=(count,bytes)=>Array.from({length:count},(_,index)=>({size:Math.floor(bytes/count)+(index<bytes%count?1:0)}));
let checks=0;function check(value,label){checks++;assert.ok(value,label);}
for(const [count,bytes] of [[0,0],[11,55*1048576],[11,200*1048576],[20,200*1048576]])check(error(files(count,bytes))==='','valid count/total '+count+'/'+bytes);
const tooLarge=error(files(11,200*1048576+1));
check(tooLarge.includes('۲۰۰ مگابایت') && tooLarge.includes('۱۱') && tooLarge.includes('مجموع حجم'),'one byte over limit has a specific total-size error');
const tooMany=error(files(21,1048576));
check(tooMany.includes('۲۱') && tooMany.includes('حداکثر ۲۰') && !tooMany.includes('مجموع حجم'),'count has a separate error');
const api=read('emcore_api/_procurement_workflow_actions.php');
check(Number(api.match(/if \(\$total > (\d+)\)/)[1])===200*1048576,'API total ceiling matches the panel exactly');
check(api.includes("count($raw['name']) > 20"),'API retains 20-file limit');
check(panel.includes("var attachmentError=workflowAttachmentError(files)") && !panel.includes('حداکثر ۲۰ پیوست با مجموع اندازهٔ ۵۰'),'publication uses new validator and old ambiguous message is gone');
console.log('Procurement attachment boundaries: '+checks+' assertions passed.');
