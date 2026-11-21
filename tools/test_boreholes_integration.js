'use strict';

const assert = require('node:assert/strict');
const { randomBytes } = require('node:crypto');

const base = new URL(process.env.EMCORE_BOREHOLES_TEST_URL || 'http://127.0.0.1:33426');
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Use the loopback fixture only');

function client(actor) {
  let cookie = '', token = '';
  return async function (endpoint, action, fields = {}, expected = 200, csrf = true) {
    const response = await fetch(new URL(`/emcore_api/${endpoint}.php`, base), {
      method: 'POST',
      headers: { Cookie: cookie, 'X-CSRF-Token': csrf ? token : '', 'X-Borehole-Fixture-Actor': actor },
      body: new URLSearchParams({ action, ...fields }),
    });
    const setCookie = response.headers.get('set-cookie');
    if (setCookie) cookie = setCookie.split(';')[0];
    const text = await response.text();
    assert.equal(response.status, expected, `${endpoint}/${action}: ${text}`);
    const json = JSON.parse(text);
    if (json.csrf_token) token = json.csrf_token;
    return json;
  };
}

async function run() {
  const admin = client('admin'), reader = client('reader'), anonymous = client('anonymous');
  const registry = 'emcore_boreholes', reports = 'emcore_drilling_reports', analytics = 'emcore_drilling_analytics';
  const boreholeCode = 'Fixture_' + randomBytes(5).toString('hex');
  const mine = 1, otherMine = 2;
  const basic = { mine_id: mine, borehole_code: boreholeCode };
  const initial = await admin(registry, 'lookups');
  assert.ok(initial.data.mines.some((row) => Number(row.id) === mine));
  assert.ok(!initial.data.mines.some((row) => Number(row.id) === 3), 'Retired mine must not appear');
  await anonymous(registry, 'list', {}, 401);
  await admin(registry, 'create', basic, 403, false);
  await admin(registry, 'create', { ...basic, mine_id: 3 }, 422);
  await admin(registry, 'create', { ...basic, profile_completion_date_fa: '1403/01/03' }, 422);
  await admin(registry, 'create', { ...basic, dip_deg: 91 }, 422);
  await admin(registry, 'create', { ...basic, profile_start_date_fa: '1403/07/31' }, 422);

  const empty = await admin(registry, 'create', basic, 201);
  await admin(registry, 'create', basic, 409);
  const other = await admin(registry, 'create', { ...basic, mine_id: otherMine }, 201);
  const get = async (who = admin) => (await who(registry, 'get', { id: empty.id })).data;
  const emptyProfile = await get();
  assert.equal(emptyProfile.profile_depth_m, null);
  assert.deepEqual(emptyProfile.daily_report_summary, { report_count: 0, first_report_date_fa: null,
    last_report_date_fa: null, first_start_depth: null, latest_end_depth: null });
  assert.deepEqual((await admin(registry, 'list', { mine_id: mine, search: boreholeCode })).data.map((row) => Number(row.id)), [empty.id]);
  assert.equal((await admin(registry, 'list', { search: '%_' })).meta.total, 0, 'Wildcard characters should be searched literally');
  assert.equal((await reader(registry, 'lookups')).permissions.can_update, 0);
  assert.equal((await get(reader)).daily_report_summary, null);
  await reader(registry, 'create', { ...basic, borehole_code: boreholeCode + '-denied' }, 403);
  await reader(analytics, 'progression_lookups', {}, 403);

  const reportLookups = await admin(reports, 'lookups');
  const rigId = reportLookups.data.rigs[0].id;
  const present = (lookup, id = empty.id) => lookup.data.boreholes.some((row) => Number(row.id) === id);
  assert.ok(present(reportLookups));
  assert.ok(!present(await admin(analytics, 'progression_lookups')));

  const profile = { ...basic, profile_depth_m: '۲۲۰٫۵', collar_x: '-0.2500', collar_y: '0', azimuth_deg: 0, dip_deg: 90,
    profile_start_date_fa: '۱۴۰۳/۰۱/۰۱', profile_completion_date_fa: '1403/01/03', profile_drilling_status: 'completed' };
  const firstProfile = await admin(registry, 'update', { id: empty.id, lock_version: 1, ...profile, completion_confirmed: '1' });
  assert.equal(firstProfile.lock_version, 2);
  await admin(registry, 'update', { id: empty.id, lock_version: 1, ...profile }, 409);

  const report = { borehole_id: empty.id, rig_id: rigId, report_date_fa: '1403/01/02', shift: 'NIGHT',
    operation_state: 'drilling', drill_start_depth: 200, drill_end_depth: 215.7, start_time: '19:00' };
  const firstLinked = await admin(reports, 'create', report, 201);
  const earlier = await admin(reports, 'create', { ...report, start_time: '07:00', shift: 'DAY', drill_end_depth: 211 }, 201);
  assert.ok(present(await admin(analytics, 'progression_lookups')));
  const scope = { mine_id: mine, borehole_id: empty.id };
  const current = await get();
  assert.equal(Number(current.profile_depth_m), 220.5);
  assert.equal(current.profile_completion_date_fa, '1403/01/03');
  assert.equal(Number(current.collar_y), 0);
  assert.equal(current.daily_report_summary.latest_end_depth, 215.7, 'Latest uses time order, even when an earlier shift was entered later');
  assert.equal(current.daily_report_summary.report_count, 2);
  assert.equal((await admin(analytics, 'borehole_progression', scope)).data.summary.latest_end_depth, 215.7);
  assert.equal(Number((await admin(analytics, 'dashboard', scope)).data.boreholes[0].latest_end_depth), 215.7);
  assert.equal((await get(reader)).daily_report_summary, null, 'Profile read grants no access to report figures');

  assert.equal((await admin(registry, 'update', { id: empty.id, lock_version: 2, ...profile, mine_id: otherMine }, 409)).details.mine_id, 'locked_by_report');
  const revised = await admin(reports, 'update', { id: firstLinked.id, lock_version: 1, ...report, drill_end_depth: 217 });
  const afterReport = await get();
  assert.equal(afterReport.daily_report_summary.latest_end_depth, 217);
  assert.equal(afterReport.profile_completion_date_fa, '1403/01/03');
  assert.equal(Number(afterReport.profile_depth_m), 220.5);

  const inactive = await admin(registry, 'update', { id: empty.id, lock_version: 2, ...profile, status: 'inactive' });
  assert.ok(!present(await admin(reports, 'lookups')));
  await admin(reports, 'create', report, 422);
  assert.ok(present(await admin(analytics, 'progression_lookups')));
  await admin(reports, 'delete', { id: firstLinked.id, lock_version: revised.lock_version });
  assert.equal((await get()).daily_report_summary.latest_end_depth, 211);
  await admin(reports, 'update', { ...report, id: earlier.id, lock_version: 1, borehole_id: other.id });
  assert.equal((await get()).daily_report_summary.report_count, 0);
  assert.ok(!present(await admin(analytics, 'progression_lookups')));
  assert.equal((await admin(registry, 'update', { id: empty.id, lock_version: inactive.lock_version, ...profile, mine_id: otherMine }, 409)).details.mine_id, 'locked_by_report');
  assert.equal(Number((await admin(registry, 'get', { id: other.id })).data.mine_locked), 1, 'Reassigned report locks the new owner as well');
  assert.equal((await admin('emcore_audit_log_api', 'list', { module_key: 'boreholes' })).data.filter((row) => String(row.entity_id) === String(empty.id)).length, 3);
  console.log('Borehole integration checks passed.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; });
