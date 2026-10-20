'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const migration=read('database/migrations/016_emcore_procurement_favorites.sql');
assert.ok(migration.includes('PRIMARY KEY (usr_uid, procurement_id)'));
assert.ok(migration.includes('FOREIGN KEY (procurement_id)'));
const api=read('emcore_api/emcore_procurement_notices.php');
for(const text of ["'set_favorite' => 'read'",'fav.usr_uid=:favorite_actor','fav.usr_uid=:favorite_filter_actor',"':actor' => $actor['USR_UID']","emcore_procurement_row($db, $id, true)"])assert.ok(api.includes(text),text);
const action=api.slice(api.indexOf("if ($action === 'set_favorite')"),api.indexOf("if ($action === 'delete')"));
assert.ok(!action.includes('UPDATE emcore_procurement_notices') && !action.includes('emcore_pw_event('),'Personal markers must not alter business data or notify');
const panel=read('panels/emcore_procurement_notices_panel.html');
for(const text of ['role="tablist"','id="favoriteRecordsTab"',"'aria-pressed'",'favorites_only: favoritesOnly','accept:uploadAccept()']){
  assert.ok(panel.includes(text),text);
}
assert.ok(panel.includes('var base = $.extend({}, activeQuery || filterData())'),'Export retains favorite filter');
assert.ok(panel.includes('generation !== listGeneration'),'Out-of-order list responses ignored');
for(const script of panel.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi))if(script[1].trim())new vm.Script(script[1]);
assert.ok(read('emcore_api/_procurement_storage.php').includes('archive_signature_mismatch'));
console.log('Procurement favorites and archive release checks passed.');
