'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const failures = [];

function read(relative) {
  const absolute = path.join(root, relative);
  if (!fs.existsSync(absolute)) {
    failures.push(`missing file: ${relative}`);
    return '';
  }
  return fs.readFileSync(absolute, 'utf8');
}

function requireText(source, needle, label) {
  if (!source.includes(needle)) failures.push(label || `missing text: ${needle}`);
}

function checkPhpDelimiters(source, label) {
  const pairs = { ')': '(', ']': '[', '}': '{' };
  const stack = [];
  let quote = '';
  let lineComment = false;
  let blockComment = false;
  for (let index = 0; index < source.length; index += 1) {
    const current = source[index];
    const next = source[index + 1] || '';
    if (lineComment) { if (current === '\n') lineComment = false; continue; }
    if (blockComment) { if (current === '*' && next === '/') { blockComment = false; index += 1; } continue; }
    if (quote) { if (current === '\\') { index += 1; continue; } if (current === quote) quote = ''; continue; }
    if (current === '/' && next === '/') { lineComment = true; index += 1; continue; }
    if (current === '#') { lineComment = true; continue; }
    if (current === '/' && next === '*') { blockComment = true; index += 1; continue; }
    if (current === "'" || current === '"') { quote = current; continue; }
    if (current === '(' || current === '[' || current === '{') stack.push(current);
    if (pairs[current] && stack.pop() !== pairs[current]) { failures.push(`${label} has mismatched delimiters`); return; }
  }
  if (quote || blockComment || stack.length) failures.push(`${label} has an unterminated string, comment, or delimiter`);
}

const migration = read('database/migrations/010_emcore_procurement_notices.sql');
const endpoint = read('emcore_api/emcore_procurement_notices.php');
const storage = read('emcore_api/_procurement_storage.php');
const panel = read('panels/emcore_procurement_notices_panel.html');
const importer = read('tools/import_legacy_procurement_notices.php');
read('docs/PROCUREMENT_NOTICES_MODULE.md');
read('docs/PROCUREMENT_NOTICES_DEPLOYMENT.md');
checkPhpDelimiters(endpoint, 'procurement endpoint PHP');
checkPhpDelimiters(storage, 'procurement storage PHP');
checkPhpDelimiters(importer, 'procurement importer PHP');

[
  'emcore_procurement_notices', 'emcore_procurement_files',
  'emcore_procurement_download_log', 'emcore_procurement_import_batches',
].forEach((table) => requireText(migration, `CREATE TABLE IF NOT EXISTS ${table}`, `migration does not create ${table}`));
requireText(migration, "VALUES ('procurement_notices'", 'module registration is missing');
requireText(migration, 'legacy_source_data JSON', 'lossless legacy source payload is missing');
requireText(migration, 'lock_version INT UNSIGNED', 'optimistic concurrency column is missing');
requireText(migration, "record_origin ENUM('managed', 'legacy')", 'legacy/managed boundary is missing');
requireText(migration, 'response_deadline_en DATE', 'derived Gregorian response deadline is missing');

[
  "'lookups' => 'read'", "'list' => 'read'", "'get' => 'read'", "'download_file' => 'read'",
  "'create' => 'create'", "'update' => 'update'", "'upload_file' => 'update'",
  "'delete_file' => 'delete'", "'delete' => 'delete'",
].forEach((mapping) => requireText(endpoint, mapping, `missing capability mapping ${mapping}`));
requireText(endpoint, 'emcore_require_csrf();', 'write CSRF enforcement is missing');
requireText(endpoint, 'FOR UPDATE', 'write locking is missing');
requireText(endpoint, 'lock_version = lock_version + 1', 'optimistic version increment is missing');
requireText(endpoint, "throw new EmcoreHttpException(409", 'concurrency/file conflicts do not return 409');
requireText(endpoint, 'shamsi_slash_to_gregorian_date', 'server-side Jalali conversion is missing');
requireText(endpoint, 'DATEDIFF(p.response_deadline_en, CURDATE())', 'deadline state is not derived live');
requireText(endpoint, ": 'created_at';", 'list API does not default to newest records');
requireText(endpoint, ": 'desc';", 'list API default sort direction is not descending');
[
  "'notice_type' => 'p.notice_type'",
  "'contracting_authority' => 'p.contracting_authority",
  "'responsible_unit' => 'p.responsible_unit",
  "'days_left' => 'p.response_deadline_en",
  "'participation_status' => 'p.participation_status'",
  "'file_count' => 'file_count'",
].forEach((mapping) => requireText(endpoint, mapping, `missing safe list sort mapping ${mapping}`));
if (/\bLEFT_DAYS\b/.test(endpoint)) failures.push('endpoint depends on the legacy stored LEFT_DAYS column');

requireText(storage, "getenv('EMCORE_PROCUREMENT_STORAGE_ROOT')", 'storage environment configuration is missing');
requireText(storage, "$_SERVER['DOCUMENT_ROOT']", 'web-root containment check is missing');
requireText(storage, 'is_uploaded_file', 'uploaded-file validation is missing');
requireText(storage, 'new finfo(FILEINFO_MIME_TYPE)', 'MIME detection is missing');
requireText(storage, "hash_file('sha256'", 'SHA-256 hashing is missing');
requireText(storage, 'emcore_procurement_download_log', 'download logging is missing');

requireText(importer, "--commit", 'importer does not default to a dry-run workflow');
requireText(importer, "'prc_db_mozayedat_monaghesat_copy1'", 'importer does not use the authoritative legacy table');
requireText(importer, 'information_schema.COLUMNS', 'importer does not verify the legacy table schema');
requireText(importer, 'legacy_source_data', 'importer does not preserve the source row');
requireText(importer, "'unknown'", 'importer silently guesses missing legacy enums');
requireText(importer, 'legacy_reference', 'importer does not preserve old file references');
requireText(importer, "p.module_key = 'procurement_notices'", 'import actor permission check is missing');
if (/fgetcsv|--csv|\.csv\b/i.test(importer)) failures.push('importer still contains a CSV migration path');

requireText(panel, "var API = '/emcore_api/emcore_procurement_notices.php'", 'panel API URL is wrong');
requireText(panel, 'new FormData()', 'multipart upload UI is missing');
requireText(panel, "HTMLFormElement.prototype.submit.call", 'controlled file download form is missing');
requireText(panel, "data-editable", 'read-only field handling is missing');
requireText(panel, "response_deadline_fa", 'response deadline form field is not submitted');
requireText(panel, "lock_version", 'panel does not send an optimistic lock version');
requireText(panel, "var sortBy = 'created_at'", 'panel does not request newest records by default');
requireText(panel, "var sortOrder = 'desc'", 'panel default sort direction is not descending');
requireText(panel, 'function sortableHeader', 'clickable table-header sorting is missing');
requireText(panel, "attr('aria-sort'", 'sortable headers do not expose their state accessibly');
requireText(panel, 'sort-button', 'sortable header controls are missing');
requireText(panel, 'id="fileInput"', 'file picker is missing');
requireText(panel, 'multiple', 'file picker does not support selecting multiple files');
requireText(panel, 'id="uploadProgressBar"', 'upload progress bar is missing');
requireText(panel, "xhr.upload.addEventListener('progress'", 'upload progress is not connected to XMLHttpRequest upload events');
requireText(panel, 'function uploadNext', 'multiple files are not uploaded as a controlled queue');
requireText(panel, 'uploadInProgress', 'dialog does not guard an active upload queue');
requireText(panel, 'var jalaali', 'local Jalali conversion helper is missing');
requireText(panel, 'function openJalaliDatepicker', 'Jalali datepicker behavior is missing');
requireText(panel, 'jalaali.toDate', 'datepicker does not return a JavaScript Date through the Jalali helper');
requireText(panel, "['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']", 'datepicker week does not start on Saturday');
requireText(panel, "aria-label=\"ماه قبل\"", 'datepicker previous-month control is missing');
requireText(panel, "aria-label=\"ماه بعد\"", 'datepicker next-month control is missing');
requireText(panel, "role=\"grid\"", 'datepicker calendar grid semantics are missing');
requireText(panel, "event.key === 'ArrowRight'", 'datepicker keyboard navigation is missing');
requireText(panel, '@supports (-webkit-touch-callout: none)', 'iOS form-control zoom protection is missing');
requireText(panel, '@media (prefers-reduced-motion: reduce)', 'reduced-motion support is missing');
if (/<script[^>]+src=["']https?:\/\//i.test(panel)) failures.push('panel introduces a remote JavaScript dependency');
if (/\son(?:click|change|submit)\s*=/.test(panel)) failures.push('panel contains an inline event handler');
if (/\.html\s*\(/.test(panel)) failures.push('panel uses .html() for generated content');
const panelIds = [...panel.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1]);
const duplicatePanelIds = [...new Set(panelIds.filter((id, index) => panelIds.indexOf(id) !== index))];
if (duplicatePanelIds.length) failures.push(`panel contains duplicate ids: ${duplicatePanelIds.join(', ')}`);
const scripts = [...panel.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
if (!scripts.length) failures.push('panel script block is missing');
scripts.forEach((script, index) => {
  try { new Function(script); } catch (error) { failures.push(`panel script ${index + 1} syntax error: ${error.message}`); }
});

const jalaaliStart = panel.indexOf('var jalaali =');
const jalaaliEndMarker = '  }());';
const jalaaliEnd = panel.indexOf(jalaaliEndMarker, jalaaliStart);
if (jalaaliStart >= 0 && jalaaliEnd > jalaaliStart) {
  try {
    const context = {};
    const helperSource = panel.slice(jalaaliStart, jalaaliEnd + jalaaliEndMarker.length);
    vm.runInNewContext(`${helperSource}\nresult = jalaali;`, context);
    const nowruz = context.result.toGregorian(1403, 1, 1);
    if (nowruz.gy !== 2024 || nowruz.gm !== 3 || nowruz.gd !== 20) failures.push('Jalali helper converts 1403/01/01 incorrectly');
    const roundTrip = context.result.toJalaali(2026, 3, 21);
    if (roundTrip.jy !== 1405 || roundTrip.jm !== 1 || roundTrip.jd !== 1) failures.push('Jalali helper converts 2026-03-21 incorrectly');
    const selectedDate = context.result.toDate(1405, 1, 1);
    if (Object.prototype.toString.call(selectedDate) !== '[object Date]' || selectedDate.getFullYear() !== 2026 || selectedDate.getMonth() !== 2 || selectedDate.getDate() !== 21) failures.push('Jalali helper does not return the expected Date');
    if (!context.result.isValidJalaaliDate(1399, 12, 30) || context.result.isValidJalaaliDate(1400, 12, 30)) failures.push('Jalali leap-year validation is incorrect');
  } catch (error) {
    failures.push(`Jalali helper runtime error: ${error.message}`);
  }
}

if (failures.length) {
  console.error('Procurement-notices release checks failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}
console.log('Procurement-notices release checks passed.');
