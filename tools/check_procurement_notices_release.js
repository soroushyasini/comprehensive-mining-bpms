'use strict';

const fs = require('fs');
const path = require('path');

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

if (failures.length) {
  console.error('Procurement-notices release checks failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}
console.log('Procurement-notices release checks passed.');
