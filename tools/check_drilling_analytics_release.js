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
requireText(analyticsApi, "emcore_action(['dashboard', 'attendance_lookups', 'attendance_matrix', 'progression_lookups', 'borehole_progression'])", 'analytics action allow-list is missing progression or attendance actions');
requireText(analyticsApi, 'SUM(r.drill_amount)', 'analytics does not use reported production');
requireText(analyticsApi, 'SUM(c.worked_hours)', 'analytics does not aggregate actual worked hours');
requireText(analyticsApi, "'people' => $people", 'analytics does not return per-person hours');
requireText(analyticsApi, 'r.deleted_at IS NULL', 'analytics includes deleted reports');
requireText(analyticsApi, 'b.mine_id = :mine_id', 'analytics mine filter is not parameterized by id');
requireText(analyticsApi, 'r.borehole_id = :borehole_id', 'analytics borehole filter is not parameterized by id');
requireText(analyticsApi, 'missing_worked_hours', 'analytics does not expose missing-hour coverage');
requireText(analyticsApi, "if ($action === 'attendance_lookups')", 'attendance period lookup endpoint is missing');
requireText(analyticsApi, "if ($action === 'attendance_matrix')", 'attendance matrix endpoint is missing');
requireText(analyticsApi, 'emcore_drilling_report_crew c', 'attendance does not use canonical crew assignments');
requireText(analyticsApi, 'MAX(c.worked_hours)', 'attendance does not protect worked hours from duplicate assignments');
requireText(analyticsApi, ':attendance_period', 'attendance period filter is not parameterized');
requireText(analyticsApi, 'duplicate_assignments', 'attendance does not disclose duplicate assignments');
requireText(analyticsApi, 'hours_over_12', 'attendance does not flag implausible daily/shift hours');
requireText(analyticsApi, 'reports_with_invalid_shift', 'attendance does not disclose invalid legacy shifts');
requireText(analyticsApi, "if ($action === 'borehole_progression')", 'borehole progression endpoint is missing');
requireText(analyticsApi, "if ($action === 'progression_lookups')", 'progression-specific historical borehole lookup is missing');
requireText(analyticsApi, 'JOIN emcore_drilling_reports r ON r.borehole_id = b.id AND r.deleted_at IS NULL', 'progression lookups do not include historical boreholes with reports');
requireText(analyticsApi, 'b.id = :progress_borehole_id', 'progression borehole filter is not parameterized by id');
requireText(analyticsApi, 'b.mine_id = :progress_mine_id', 'progression does not validate the selected mine/borehole relationship');
requireText(analyticsApi, "'date_range' => 'all_non_deleted_reports_for_selected_borehole'", 'progression lifecycle semantics are not explicit');
requireText(analyticsApi, "'cumulative_drilled'", 'progression does not return cumulative reported drilling');
requireText(analyticsApi, "'first_start_depth'", 'progression does not disclose its first recorded depth');
requireText(analyticsApi, "'latest_end_depth'", 'progression does not return latest physical ending depth');
requireText(analyticsApi, 'negative_drill_reports', 'progression does not disclose negative legacy drilling values');

requireText(reportsPanel, 'crew-hours', 'report panel lacks worked-hours input');
requireText(reportsPanel, 'lock_version', 'report panel does not send the edit version');
requireText(reportsPanel, 'openView', 'report panel lacks read-only detail mode');
requireText(analyticsPanel, "var API = '/emcore_api/emcore_drilling_analytics.php'", 'analytics panel API URL is wrong');
requireText(analyticsPanel, '<svg', 'analytics panel lacks self-contained SVG charts');
requireText(analyticsPanel, 'actual_worked_hours', 'analytics panel does not render actual worked hours');
requireText(analyticsPanel, "data.people || []", 'analytics panel lacks per-person worked-hours visualization');
requireText(analyticsPanel, 'id="peopleTableHost"', 'analytics panel lacks the complete per-person hours table');
requireText(analyticsPanel, 'missing_worked_hours', 'analytics panel does not disclose missing hours');
requireText(analyticsPanel, 'id="attendanceTab"', 'analytics panel lacks an attendance tab');
requireText(analyticsPanel, 'id="attendanceView"', 'analytics panel lacks an attendance view');
requireText(analyticsPanel, "request(API, 'attendance_matrix'", 'analytics panel does not request attendance matrix data');
requireText(analyticsPanel, 'id="attendanceHost"', 'analytics panel lacks the attendance matrix host');
requireText(analyticsPanel, 'متراژ حفاری در شیفت‌های حضور', 'attendance production semantics are not disclosed');
requireText(analyticsPanel, 'ساعت کارکرد واقعی', 'attendance matrix lacks actual worked hours');
requireText(analyticsPanel, '/lib/xlsx.full.min_2.js', 'attendance export does not use the same-origin Excel library');
requireText(analyticsPanel, 'id="progressMine"', 'progression module lacks a dedicated mine selector');
requireText(analyticsPanel, 'id="progressBorehole"', 'progression module lacks a dedicated borehole selector');
requireText(analyticsPanel, 'id="boreholeProgressChart"', 'progression module lacks the coordinated lifecycle SVG');
requireText(analyticsPanel, 'id="progressTableHost"', 'progression module lacks an accessible data table');
requireText(analyticsPanel, "request(API, 'borehole_progression'", 'progression module does not request the lifecycle endpoint');
requireText(analyticsPanel, "request(API, 'progression_lookups'", 'progression module does not request historical borehole lookups');
requireText(analyticsPanel, 'حفاری روزانه بر حسب شیفت', 'progression module lacks the daily panel');
requireText(analyticsPanel, 'حفاری تجمعی ثبت‌شده', 'progression module lacks the cumulative panel');
requireText(analyticsPanel, 'بدون محدودیت بازه زمانی', 'progression module does not disclose its full-lifecycle time semantics');
requireText(analyticsPanel, 'id="overviewScope"', 'overview lacks a borehole-centered scope selector');
requireText(analyticsPanel, 'تمام شاخص‌های این تب برای کل سابقه گمانه انتخابی محاسبه می‌شوند', 'overview does not disclose its full-lifecycle borehole scope');
requireText(analyticsPanel, 'function overviewFilters()', 'overview does not have a dedicated borehole-only request scope');
requireText(analyticsPanel, "mine_id: $('#progressMine').val(), borehole_id: $('#progressBorehole').val()", 'overview is not driven by the shared project and borehole selectors');
requireText(analyticsPanel, 'loadOverviewForSelection()', 'overview charts and progression are not refreshed from one selection flow');
['qMine', 'qBorehole', 'qRig', 'qShift', 'qFrom', 'qTo', 'applyBtn', 'resetBtn'].forEach((id) => {
  if (analyticsPanel.includes(`id="${id}"`)) failures.push(`overview still renders obsolete filter control: ${id}`);
  if (analyticsPanel.includes(`#${id}`)) failures.push(`overview script still references obsolete filter control: ${id}`);
});
if (analyticsPanel.includes('aria-label="فیلترهای داشبورد"')) failures.push('overview still renders the obsolete dashboard filter section');
if (/prc_db_gozaresh_ruzane_copy2|@#/.test(analyticsApi + analyticsPanel)) failures.push('attendance implementation contains legacy ProcessMaker SQL or variables');

requireText(docs, 'ساعت کارکرد واقعی', 'analytics documentation lacks actual worked-hours semantics');
requireText(docs, 'داده نامشخص', 'analytics documentation does not define missing historical hours');
requireText(docs, 'ماتریس حضور و کارکرد نفرات', 'analytics documentation lacks the attendance matrix contract');
requireText(docs, 'متراژ حفاری در شیفت‌های حضور', 'analytics documentation lacks attendance-production semantics');
requireText(docs, 'نمودار پیشرفت گمانه', 'analytics documentation lacks the full-lifecycle progression contract');
requireText(docs, 'all_non_deleted_reports_for_selected_borehole', 'analytics documentation does not state progression date-range semantics');

if (failures.length) {
  console.error('Drilling analytics release checks failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('Drilling analytics release checks passed.');
