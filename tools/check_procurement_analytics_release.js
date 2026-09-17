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

function requireText(source, needle, message) {
  if (!source.includes(needle)) failures.push(message || `missing text: ${needle}`);
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
    if (pairs[current] && stack.pop() !== pairs[current]) {
      failures.push(`${label} has mismatched delimiters`);
      return;
    }
  }
  if (quote || blockComment || stack.length) failures.push(`${label} has an unterminated token`);
}

function checkPanel(source) {
  if (/\son(?:click|change|submit)\s*=/.test(source)) failures.push('panel contains an inline event handler');
  if (/\sstyle\s*=/.test(source)) failures.push('panel contains inline presentation styles');
  if (/\.html\s*\(/.test(source)) failures.push('panel uses .html() for generated content');
  if (/<script[^>]+src=["']https?:/i.test(source)) failures.push('panel depends on a remote script');
  if (/fallback\s*(?:data|json)|sample\s*data/i.test(source)) failures.push('panel contains fabricated fallback data');
  const ids = [...source.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1]);
  const duplicates = [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))];
  if (duplicates.length) failures.push(`panel contains duplicate ids: ${duplicates.join(', ')}`);
  const scripts = [...source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
  if (!scripts.length) failures.push('panel script block is missing');
  scripts.forEach((script, index) => {
    try { new Function(script); } catch (error) { failures.push(`panel script ${index + 1} syntax error: ${error.message}`); }
  });
}

const api = read('emcore_api/emcore_procurement_analytics.php');
const panel = read('panels/emcore_procurement_analytics_panel.html');
const docs = read('docs/PROCUREMENT_ANALYTICS.md');
const panelRegistry = read('panels/README.md');

checkPhpDelimiters(api, 'procurement analytics API');
checkPanel(panel);

requireText(api, "emcore_action(['lookups', 'dashboard'])", 'API action allow-list is missing');
requireText(api, "emcore_require_permission(EMCORE_PROCUREMENT_ANALYTICS_MODULE, 'read')", 'read permission is missing');
requireText(api, 'p.deleted_at IS NULL', 'soft-deleted notices are not excluded');
requireText(api, ':date_from_en', 'start-date filter is not parameterized');
requireText(api, ':date_to_en', 'end-date filter is not parameterized');
requireText(api, ':notice_type', 'notice-type filter is not parameterized');
requireText(api, ':participation_status', 'participation-status filter is not parameterized');
requireText(api, "DATEDIFF(p.response_deadline_en, CURDATE())", 'deadline state is not calculated live');
requireText(api, "'category_hierarchy'", 'hierarchical category result is missing');
requireText(api, "'quality'", 'data-quality metadata is missing');
requireText(api, "'semantics'", 'metric semantics are missing');
requireText(api, 'LIMIT 1001', 'hierarchy cardinality guard is missing');
requireText(api, "LOWER(TRIM(p.category_name)) = 'nan'", 'legacy NaN categories are not normalized as unknown');
if (/prc_db_mozayedat_monaghesat_copy1|LEFT_DAYS/.test(api)) failures.push('analytics API reads legacy runtime data');

requireText(panel, "var API = '/emcore_api/emcore_procurement_analytics.php'", 'panel API URL is wrong');
requireText(panel, '<svg', 'panel lacks self-contained SVG charts');
requireText(panel, 'id="authorityChart"', 'authority type chart is missing');
requireText(panel, 'id="unitChart"', 'responsible-unit chart is missing');
requireText(panel, 'id="statusChart"', 'participation-status chart is missing');
requireText(panel, 'id="categoryChart"', 'category chart is missing');
requireText(panel, 'id="hierarchyChart"', 'hierarchy drill-down chart is missing');
requireText(panel, 'id="monthlyChart"', 'monthly trend chart is missing');
requireText(panel, 'id="deadlineChart"', 'deadline-health chart is missing');
requireText(panel, 'id="qualityHost"', 'quality disclosure is missing');
requireText(panel, 'id="authorityTableHost"', 'accessible authority data table is missing');
requireText(panel, 'id="monthlyTableHost"', 'accessible monthly data table is missing');
requireText(panel, 'id="deadlineTableHost"', 'accessible deadline data table is missing');
requireText(panel, 'id="unitTableHost"', 'accessible responsible-unit data table is missing');
requireText(panel, 'id="statusTableHost"', 'accessible participation-status data table is missing');
requireText(panel, 'id="categoryTableHost"', 'accessible category data table is missing');
requireText(panel, 'id="hierarchyTableHost"', 'accessible hierarchy data table is missing');
requireText(panel, 'function renderEmpty', 'honest empty-state renderer is missing');
requireText(panel, 'function renderHierarchy', 'hierarchy renderer is missing');
requireText(panel, 'function setBusy', 'loading-state handling is missing');
requireText(panel, 'function toLatinDigits', 'Persian date digits are not normalized');
requireText(panel, "app.find('[role=\"tabpanel\"]')", 'tab switching is not scoped to the analytics panel');
if (/\$\('\.tab'\)/.test(panel)) failures.push('panel uses an unscoped generic tab selector');
requireText(panel, '[hidden] { display: none !important; }', 'hidden states can consume layout space');
requireText(panel, '@media (max-width: 760px)', 'mobile layout is missing');
requireText(panel, 'نوع نامشخص', 'unknown notice types are not labelled');
requireText(panel, 'وضعیت نامشخص', 'unknown participation statuses are not labelled');

requireText(docs, 'پنج معنای اصلی گزارش قدیمی', 'legacy metric parity is not documented');
requireText(docs, 'هرگز: خواندن runtime از جدول legacy', 'legacy isolation boundary is not documented');
requireText(docs, 'file_coverage_count', 'file-coverage semantics are not documented');
requireText(panelRegistry, 'emcore_procurement_analytics_panel.html', 'panel registry is missing procurement analytics');

if (failures.length) {
  console.error('Procurement analytics release checks failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}
console.log('Procurement analytics release checks passed.');
