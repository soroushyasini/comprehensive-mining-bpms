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
    if (pairs[current] && stack.pop() !== pairs[current]) { failures.push(label + ' has mismatched delimiters'); return; }
  }
  if (quote || blockComment || stack.length) failures.push(label + ' has an unterminated string, comment, or delimiter');
}

function checkPanel(source, label) {
  if (/\son(?:click|change|submit)\s*=/.test(source)) failures.push(label + ' contains an inline event handler');
  if (/\.html\s*\(/.test(source)) failures.push(label + ' uses .html() for generated content');
  if (/<script[^>]+src=["']https?:/i.test(source)) failures.push(label + ' depends on a remote script');
  const ids = [...source.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1]);
  const duplicates = ids.filter((id, index) => ids.indexOf(id) !== index);
  if (duplicates.length) failures.push(label + ' contains duplicate ids: ' + [...new Set(duplicates)].join(', '));
  const scripts = [...source.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
  if (!scripts.length) failures.push(label + ' script block is missing');
  scripts.forEach((script, index) => {
    try { new Function(script); } catch (error) { failures.push(`${label} script ${index + 1} syntax error: ${error.message}`); }
  });
}

const migration = read('database/migrations/009_emcore_drilling_analytics_and_worked_hours.sql');
const reportsApi = read('emcore_api/emcore_drilling_reports.php');
const analyticsApi = read('emcore_api/emcore_drilling_analytics.php');
const reportsPanel = read('panels/emcore_drilling_reports_panel.html');
const analyticsPanel = read('panels/emcore_drilling_analytics_panel.html');
const docs = read('docs/DRILLING_ANALYTICS.md');

checkPhpDelimiters(reportsApi, 'drilling reports API');
checkPhpDelimiters(analyticsApi, 'drilling analytics API');
checkPanel(reportsPanel, 'drilling reports panel');
checkPanel(analyticsPanel, 'drilling analytics panel');

requireText(migration, 'ADD COLUMN worked_hours DECIMAL(5,2) NULL', 'worked-hours schema is missing');
requireText(migration, 'ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1', 'optimistic-lock schema is missing');
requireText(migration, 'chk_emcore_drilling_crew_worked_hours', 'worked-hours database check is missing');
requireText(migration, 'worked_hours IS NULL OR (worked_hours > 0 AND worked_hours <= 12)', 'worked-hours bounds are missing');

requireText(reportsApi, 'c.worked_hours', 'report detail does not return worked hours');
requireText(reportsApi, ':worked_hours', 'report writes do not persist worked hours');
requireText(reportsApi, 'FOR UPDATE', 'report update/delete locking is missing');
requireText(reportsApi, 'lock_version = lock_version + 1', 'report optimistic locking is missing');
requireText(reportsApi, 'DELETE rc FROM emcore_drilling_report_checklist rc', 'inactive checklist history is not preserved');
if (/SELECT\s+r\.\*/i.test(reportsApi)) failures.push('report detail exposes every report column');
requireText(reportsPanel, "$('#checklist input').prop('checked', false);", 'new reports do not start with an unchecked safety checklist');

requireText(analyticsApi, "emcore_require_permission(EMCORE_DRILLING_MODULE, 'read')", 'analytics read permission is missing');
requireText(analyticsApi, "emcore_action(['dashboard'])", 'analytics action allow-list is missing');
requireText(analyticsApi, 'SUM(r.drill_amount)', 'analytics does not use reported production');
requireText(analyticsApi, 'SUM(c.worked_hours)', 'analytics does not aggregate actual worked hours');
requireText(analyticsApi, "'people' => $people", 'analytics does not return per-person hours');
requireText(analyticsApi, 'r.deleted_at IS NULL', 'analytics includes deleted reports');
requireText(analyticsApi, 'b.mine_id = :mine_id', 'analytics mine filter is not parameterized by id');
requireText(analyticsApi, 'r.borehole_id = :borehole_id', 'analytics borehole filter is not parameterized by id');
requireText(analyticsApi, 'missing_worked_hours', 'analytics does not expose missing-hour coverage');

requireText(reportsPanel, 'crew-hours', 'report panel lacks worked-hours input');
requireText(reportsPanel, 'lock_version', 'report panel does not send the edit version');
requireText(reportsPanel, 'openView', 'report panel lacks read-only detail mode');
requireText(analyticsPanel, "var API = '/emcore_api/emcore_drilling_analytics.php'", 'analytics panel API URL is wrong');
requireText(analyticsPanel, '<svg', 'analytics panel lacks self-contained SVG charts');
requireText(analyticsPanel, 'actual_worked_hours', 'analytics panel does not render actual worked hours');
requireText(analyticsPanel, "data.people || []", 'analytics panel lacks per-person worked-hours visualization');
requireText(analyticsPanel, 'id="peopleTableHost"', 'analytics panel lacks the complete per-person hours table');
requireText(analyticsPanel, 'missing_worked_hours', 'analytics panel does not disclose missing hours');

requireText(docs, 'ساعت کارکرد واقعی', 'analytics documentation lacks actual worked-hours semantics');
requireText(docs, 'داده نامشخص', 'analytics documentation does not define missing historical hours');

if (failures.length) {
  console.error('Drilling analytics release checks failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('Drilling analytics release checks passed.');
