<?php

// Side-effect-free policy shared by HTTP handlers, native triggers and tests.
function emcore_procurement_role($uid, $settings)
{
    foreach (['operator', 'manager'] as $role) {
        if (!empty($settings[$role]) && hash_equals($settings[$role], (string)$uid)) return $role;
    }
    return null;
}

function emcore_procurement_can_edit($record, $role)
{
    return $role === 'operator'
        && ($record['sync_state'] ?? 'ready') === 'ready'
        && !in_array($record['workflow_stage'] ?? null, ['result_review', 'completed', 'stopped'], true)
        && !in_array($record['participation_status'], ['won', 'lost', 'withdrawn'], true);
}

function emcore_procurement_transition($record, $role, $action, $result = null)
{
    if (($record['sync_state'] ?? 'ready') !== 'ready') throw new DomainException('ارجاع قبلی هنوز تکمیل نشده است.');
    $status = $record['participation_status'];
    $stage = $record['workflow_stage'] ?? null;
    $next = ['participation_status' => $status, 'workflow_stage' => $stage, 'pending_result' => $record['pending_result'] ?? null];
    if ($action === 'activate' && $role === 'manager' && $stage === null
        && in_array($status, ['registered', 'not_interested', 'interested', 'documents_submitted'], true)) {
        $next['participation_status'] = in_array($status, ['interested', 'documents_submitted'], true) ? $status : 'interested';
        $next['workflow_stage'] = 'follow_up';
    } elseif ($action === 'not_interested' && $role === 'manager' && $stage === null
        && in_array($status, ['registered', 'not_interested'], true)) {
        $next['participation_status'] = 'not_interested';
    } elseif ($action === 'record_submission' && $role === 'operator' && $stage === 'follow_up' && $status === 'interested') {
        $next['participation_status'] = 'documents_submitted';
    } elseif ($action === 'propose_result' && $role === 'operator' && $stage === 'follow_up'
        && in_array($result, ['won', 'lost'], true)) {
        $next['workflow_stage'] = 'result_review';
        $next['pending_result'] = $result;
    } elseif ($action === 'return_follow_up' && $role === 'manager' && $stage === 'result_review') {
        $next['workflow_stage'] = 'follow_up';
        $next['pending_result'] = null;
    } elseif ($action === 'approve_result' && $role === 'manager' && $stage === 'result_review'
        && in_array($next['pending_result'], ['won', 'lost'], true)) {
        $next['participation_status'] = $next['pending_result'];
        $next['workflow_stage'] = 'completed';
        $next['pending_result'] = null;
    } elseif ($action === 'request_stop' && $role === 'manager' && in_array($stage, ['follow_up', 'result_review'], true)) {
        $next['participation_status'] = 'withdrawn';
        $next['workflow_stage'] = 'stopped';
        $next['pending_result'] = null;
    } else {
        throw new DomainException('این تغییر وضعیت در مرحلهٔ جاری یا برای نقش شما مجاز نیست.');
    }
    return $next;
}
