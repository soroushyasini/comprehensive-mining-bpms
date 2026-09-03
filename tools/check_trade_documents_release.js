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
    if (lineComment) {
      if (current === '\n') lineComment = false;
      continue;
    }
    if (blockComment) {
      if (current === '*' && next === '/') { blockComment = false; index += 1; }
      continue;
    }
    if (quote) {
      if (current === '\\') { index += 1; continue; }
      if (current === quote) quote = '';
      continue;
    }
    if (current === '/' && next === '/') { lineComment = true; index += 1; continue; }
    if (current === '#') { lineComment = true; continue; }
    if (current === '/' && next === '*') { blockComment = true; index += 1; continue; }
    if (current === "'" || current === '"') { quote = current; continue; }
    if (current === '(' || current === '[' || current === '{') stack.push(current);
    if (pairs[current] && stack.pop() !== pairs[current]) {
      failures.push(label + ' has mismatched delimiters');
      return;
    }
  }
  if (quote || blockComment || stack.length) failures.push(label + ' has an unterminated string, comment, or delimiter');
}

const migration = read('database/migrations/007_emcore_trade_documents.sql');
const endpoint = read('emcore_api/emcore_trade_documents.php');
const storage = read('emcore_api/_trade_storage.php');
const panel = read('panels/emcore_trade_documents_panel.html');
read('docs/TRADE_DOCUMENTS_MODULE.md');
read('docs/TRADE_DOCUMENTS_DEPLOYMENT.md');
checkPhpDelimiters(endpoint, 'trade endpoint PHP');
checkPhpDelimiters(storage, 'trade storage PHP');

[
  'emcore_trade_issuers', 'emcore_trade_cases', 'emcore_trade_documents',
  'emcore_trade_document_versions', 'emcore_trade_templates',
  'emcore_trade_attachments', 'emcore_trade_download_log',
].forEach((name) => requireText(migration, `CREATE TABLE IF NOT EXISTS ${name}`, `migration does not create ${name}`));

requireText(migration, "('emidco', 'امیدکو', 'EMIDCO', 'EMDEX', 21, 1)", 'EMIDCO cutover counter is not 21');
requireText(migration, "('emidco_metal', 'امیدکو متال', 'EMIDCO METAL', 'EMDMET', 44, 1)", 'EMIDCO METAL cutover counter is not 44');
requireText(migration, "VALUES ('trade_documents'", 'trade_documents module registration is missing');
requireText(migration, 'document_date DATE', 'official document date is missing');

[
  "'lookups' => 'read'", "'list' => 'read'", "'get' => 'read'", "'download' => 'read'",
  "'create' => 'create'", "'update' => 'update'", "'set_document_status' => 'update'",
  "'upload_document' => 'update'", "'upload_attachment' => 'update'", "'upload_template' => 'update'",
  "'delete_file' => 'delete'", "'delete' => 'delete'",
].forEach((mapping) => requireText(endpoint, mapping, `missing capability mapping ${mapping}`));

requireText(endpoint, 'emcore_require_csrf();', 'write CSRF enforcement is missing');
requireText(endpoint, 'FOR UPDATE', 'counter/version locking is missing');
requireText(endpoint, 'emcore_trade_assert_document_prerequisite', 'PI to CI to PL prerequisite is missing');
requireText(endpoint, "document_status IN ('approved', 'issued')", 'case completion guard is missing');

requireText(storage, "getenv('EMCORE_TRADE_STORAGE_ROOT')", 'storage environment configuration is missing');
requireText(storage, "$_SERVER['DOCUMENT_ROOT']", 'web-root containment check is missing');
requireText(storage, 'is_uploaded_file', 'uploaded-file validation is missing');
requireText(storage, 'new finfo(FILEINFO_MIME_TYPE)', 'MIME detection is missing');
requireText(storage, "hash_file('sha256'", 'SHA-256 hashing is missing');
requireText(storage, 'emcore_trade_download_log', 'download logging is missing');

requireText(panel, "var API = '/emcore_api/emcore_trade_documents.php'", 'panel API URL is wrong');
requireText(panel, "['pi', 'ci', 'pl']", 'panel does not render all three template types');
requireText(panel, 'new FormData()', 'multipart uploads are missing');
requireText(panel, 'HTMLFormElement.prototype.submit.call(form)', 'downloads must bypass the ProcessMaker Dynaform submit listener');
if (/downloadForm[^\n]*\.trigger\(['"]submit['"]\)/.test(panel)) failures.push('download form triggers the ProcessMaker Dynaform submit listener');
if (/\son(?:click|change|submit)\s*=/.test(panel)) failures.push('panel contains an inline event handler');
if (/\.html\s*\(/.test(panel)) failures.push('panel uses .html() for generated content');

const scripts = [...panel.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
if (!scripts.length) failures.push('panel script block is missing');
scripts.forEach((script, index) => {
  try {
    // Parse only. The function is intentionally not executed because jQuery is supplied by ProcessMaker.
    new Function(script);
  } catch (error) {
    failures.push(`panel script ${index + 1} syntax error: ${error.message}`);
  }
});

if (failures.length) {
  console.error('Trade-document release checks failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('Trade-document release checks passed.');
