<?php
// Pure domain tests. No ProcessMaker session, credentials or database required.
require_once __DIR__ . '/../emcore_api/_procurement_workflow_policy.php';
$checks = 0;
function check($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
function rejected($callback, $message) {
    try { $callback(); } catch (DomainException $error) { check(true, $message); return; }
    check(false, $message);
}
$base = ['participation_status' => 'registered', 'workflow_stage' => null, 'sync_state' => 'ready'];
rejected(function () use ($base) { emcore_procurement_transition($base, 'operator', 'activate'); }, 'operator cannot approve interest');
$active = emcore_procurement_transition($base, 'manager', 'activate');
check($active['participation_status'] === 'interested' && $active['workflow_stage'] === 'follow_up', 'interest starts follow-up');
rejected(function () use ($active) { emcore_procurement_transition($active, 'manager', 'activate'); }, 'cannot activate twice');
$submitted = emcore_procurement_transition($active, 'operator', 'record_submission');
check($submitted['participation_status'] === 'documents_submitted', 'operator records documents');
$proposal = emcore_procurement_transition($active, 'operator', 'propose_result', 'won');
check($proposal['participation_status'] === 'interested' && $proposal['pending_result'] === 'won', 'proposal does not finalize business result');
rejected(function () use ($proposal) { emcore_procurement_transition($proposal, 'operator', 'approve_result'); }, 'operator cannot approve result');
$returned = emcore_procurement_transition($proposal, 'manager', 'return_follow_up');
check($returned['workflow_stage'] === 'follow_up' && $returned['pending_result'] === null, 'return clears proposal');
$closed = emcore_procurement_transition($proposal, 'manager', 'approve_result');
check($closed['participation_status'] === 'won' && $closed['workflow_stage'] === 'completed', 'manager finalizes result');
rejected(function () use ($closed) { emcore_procurement_transition($closed, 'manager', 'activate'); }, 'closed records cannot reopen');
$stopped = emcore_procurement_transition($active, 'manager', 'request_stop');
check($stopped['workflow_stage'] === 'stopped' && $stopped['participation_status'] === 'withdrawn', 'stop is terminal');
$pending = $active; $pending['sync_state'] = 'pending';
rejected(function () use ($pending) { emcore_procurement_transition($pending, 'operator', 'propose_result', 'lost'); }, 'pending routing blocks next transition');
rejected(function () use ($active) { emcore_procurement_transition($active, 'operator', 'propose_result', 'other'); }, 'proposal enum validation');
check(emcore_procurement_role('op', ['operator' => 'op', 'manager' => 'mgr']) === 'operator', 'operator identity');
check(emcore_procurement_role('stranger', ['operator' => 'op', 'manager' => 'mgr']) === null, 'no implicit administrator role');
check(!emcore_procurement_can_edit($proposal, 'operator'), 'pending result freezes core details');
check(!emcore_procurement_can_edit($closed, 'operator'), 'closed record freezes core details');
echo "Procurement workflow policy: {$checks} checks passed.\n";
