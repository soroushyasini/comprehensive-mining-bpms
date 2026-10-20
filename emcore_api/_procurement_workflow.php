<?php
require_once __DIR__ . '/_procurement_storage.php';
require_once __DIR__ . '/_procurement_workflow_policy.php';

function emcore_pw_settings()
{
    static $settings;
    if ($settings !== null) return $settings;
    $file = __DIR__ . '/emcore_config.php';
    $local = is_file($file) ? require $file : [];
    if (!is_array($local)) throw new RuntimeException('Invalid EMCORE local configuration.');
    $settings = [];
    foreach (['enabled', 'operator', 'manager', 'operator_managed'] as $key) {
        $env = getenv('EMCORE_PROCUREMENT_WORKFLOW_' . strtoupper($key));
        $settings[$key] = $env !== false ? $env : ($local['procurement_workflow_' . $key] ?? '');
    }
    $settings['enabled'] = filter_var($settings['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($settings['enabled'] === null) throw new RuntimeException('Invalid procurement workflow enable flag.');
    $settings['operator_managed'] = filter_var($settings['operator_managed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($settings['operator_managed'] === null) throw new RuntimeException('Invalid procurement operator-managed flag.');
    if ($settings['enabled']) {
        foreach (['operator', 'manager'] as $key) {
            if (!preg_match('/^[a-zA-Z0-9]{32}$/', (string)$settings[$key])) throw new RuntimeException('Incomplete procurement participant configuration: ' . $key);
        }
        if ($settings['operator'] === $settings['manager']) throw new RuntimeException('Procurement operator and manager must differ.');
    }
    return $settings;
}

function emcore_pw_enabled() { return emcore_pw_settings()['enabled']; }
function emcore_pw_operator_managed() { return emcore_pw_settings()['operator_managed']; }
function emcore_pw_can_update()
{
    static $allowed;
    if ($allowed === null) $allowed = (bool)emcore_module_permissions('procurement_notices')['can_update'];
    return $allowed;
}

function emcore_pw_user_role()
{
    $role = emcore_procurement_role(emcore_current_user()['USR_UID'], emcore_pw_settings());
    if (!$role) throw new EmcoreHttpException(403, 'نقش ثبت یا تصمیم‌گیری فراخوان برای شما تعریف نشده است.');
    return $role;
}

function emcore_pw_scope($alias = 'p')
{
    if (!emcore_pw_enabled()) return ['sql' => '1 = 1', 'params' => []];
    emcore_pw_user_role();
    $settings = emcore_pw_settings();
    return ['sql' => $alias . '.owner_usr_uid = :pw_owner AND ' . $alias . '.manager_usr_uid = :pw_manager',
        'params' => [':pw_owner' => $settings['operator'], ':pw_manager' => $settings['manager']]];
}

function emcore_pw_record($db, $id, $lock = false)
{
    $scope = emcore_pw_scope();
    $stmt = $db->prepare("SELECT p.*, w.app_uid, w.workflow_stage, w.sync_state, w.pending_result
        FROM emcore_procurement_notices p LEFT JOIN emcore_procurement_workflows w ON w.procurement_id = p.id
        WHERE p.id = :pw_id AND p.deleted_at IS NULL AND {$scope['sql']}" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($scope['params'] + [':pw_id' => $id]);
    $row = $stmt->fetch();
    if (!$row) throw new EmcoreHttpException(404, 'فراخوان یافت نشد یا دسترسی ندارید.');
    $row['sync_state'] = $row['sync_state'] ?: 'ready';
    return $row;
}

function emcore_pw_version($row, $version)
{
    if ((int)$row['lock_version'] !== (int)$version) throw new EmcoreHttpException(409, 'اطلاعات تغییر کرده است؛ دوباره بارگذاری کنید.');
}

function emcore_pw_assert_panel($row)
{
    if (!empty($row['app_uid'])) throw new EmcoreHttpException(409, 'این سابقه به گردش کار بومی قدیمی متصل است؛ تبدیل آن به پیگیری داخل پنل نیازمند بررسی جداگانه است.');
}

function emcore_pw_assert_edit($db, $id, $delete = false)
{
    if (!emcore_pw_enabled()) return;
    $row = emcore_pw_record($db, $id, true);
    if (!emcore_procurement_can_edit($row, emcore_pw_user_role(), emcore_pw_operator_managed()) || ($delete && $row['workflow_stage'] !== null)) {
        throw new EmcoreHttpException(403, 'ویرایش یا حذف این مورد در مرحلهٔ جاری مجاز نیست.');
    }
}

function emcore_pw_permissions($row = null)
{
    if (!emcore_pw_enabled()) return ['enabled' => false];
    $role = emcore_pw_user_role();
    $actions = [];
    if ($row && empty($row['app_uid'])) {
        foreach (['activate', 'not_interested', 'record_submission', 'propose_result', 'approve_result', 'return_follow_up', 'request_stop', 'record_result'] as $action) {
            if ($role === 'operator' && emcore_pw_operator_managed() && $action === 'propose_result') continue;
            try {
                emcore_procurement_transition($row, $role, $action, in_array($action, ['propose_result','record_result'], true) ? 'won' : null, emcore_pw_operator_managed());
                if ($role !== 'operator' || emcore_pw_can_update()) $actions[] = $action;
            } catch (DomainException $ignored) { /* Forbidden actions are not offered. */ }
        }
    }
    return ['enabled' => true, 'mode' => 'panel', 'role' => $role, 'operator_managed' => emcore_pw_operator_managed(),
        'can_create' => $role === 'operator', 'allowed_actions' => $actions,
        'can_edit' => $row ? emcore_procurement_can_edit($row, $role, emcore_pw_operator_managed()) : false,
        'can_update_stage' => $row ? emcore_procurement_can_update_stage($row, $role) && emcore_pw_can_update() : false,
        'can_decide' => in_array('activate', $actions, true) || in_array('not_interested', $actions, true),
        'can_stop' => in_array('request_stop', $actions, true)];
}

function emcore_pw_request_id()
{
    $id = emcore_string('request_id', true, 32);
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new EmcoreHttpException(422, 'شناسهٔ درخواست معتبر نیست.');
    return $id;
}

function emcore_pw_snapshot($row)
{
    unset($row['legacy_source_data'], $row['files'], $row['is_favorite']);
    return $row;
}

function emcore_pw_audit($db, $actor, $id, $entity, $before, $after, $request)
{
    $db->prepare("INSERT INTO emcore_audit_log
        (request_id,actor_usr_uid,module_key,action,entity_type,entity_id,before_data,after_data,metadata)
        VALUES (:request,:actor,'procurement_notices','update',:entity,:id,:before,:after,:meta)")
        ->execute([':request' => $request, ':actor' => $actor, ':entity' => $entity, ':id' => (string)$id,
            ':before' => emcore_audit_json($before), ':after' => emcore_audit_json($after),
            ':meta' => emcore_audit_json(['source' => 'procurement_panel_workflow'])]);
}

function emcore_pw_event($db, $row, $actor, $role, $type, $body, $request, $before = null, $after = null, $corrects = null)
{
    $db->prepare("INSERT INTO emcore_procurement_events
        (procurement_id,request_id,actor_usr_uid,actor_role,event_type,body,corrects_event_id,before_data,after_data)
        VALUES (:notice,:request,:actor,:role,:type,:body,:corrects,:before,:after)")
        ->execute([':notice' => $row['id'], ':request' => $request, ':actor' => $actor, ':role' => $role,
            ':type' => $type, ':body' => $body, ':corrects' => $corrects,
            ':before' => emcore_audit_json($before), ':after' => emcore_audit_json($after)]);
    $id = (int)$db->lastInsertId();
    foreach (array_unique([$row['owner_usr_uid'], $row['manager_usr_uid']]) as $recipient) {
        if ($recipient && $recipient !== $actor) {
            $db->prepare('INSERT INTO emcore_procurement_notifications (event_id,recipient_usr_uid) VALUES (:event,:recipient)')
                ->execute([':event' => $id, ':recipient' => $recipient]);
        }
    }
    emcore_pw_audit($db, $actor, $id, 'procurement_event', null, ['procurement_id' => $row['id'], 'event_type' => $type, 'body' => $body], $request);
    return $id;
}

function emcore_pw_business_update($db, $row, $next, $actor, $reason, $request, $action)
{
    $update = $db->prepare("UPDATE emcore_procurement_notices SET participation_status = :status,
        interest_reason = CASE WHEN :is_interest = 1 THEN :reason ELSE interest_reason END,
        workflow_history_only = 0, updated_by_usr_uid = :actor, lock_version = lock_version + 1
        WHERE id = :id AND lock_version = :version");
    $update->execute([':status' => $next['participation_status'], ':is_interest' => $action === 'activate' ? 1 : 0,
        ':reason' => $reason, ':actor' => $actor, ':id' => $row['id'], ':version' => $row['lock_version']]);
    if ($update->rowCount() !== 1) throw new EmcoreHttpException(409, 'نسخهٔ فراخوان تغییر کرده است.');
    emcore_pw_audit($db, $actor, $row['id'], 'procurement_notice', emcore_pw_snapshot($row), $next, $request);
}

function emcore_pw_next($row, $role, $action, $result = null)
{
    try { return emcore_procurement_transition($row, $role, $action, $result, emcore_pw_operator_managed()); }
    catch (DomainException $error) { throw new EmcoreHttpException(422, $error->getMessage()); }
}

// The caller holds the notice lock and owns the transaction. A command, state
// update, event and notifications commit together; there is no prepared phase.
function emcore_pw_apply($db, $row, $actor, $role, $type, $body, $result, $request, $version, $trackingStage = null)
{
    emcore_pw_assert_panel($row);
    if ($role === 'operator' && !emcore_pw_can_update()) throw new EmcoreHttpException(403, 'مجوز ویرایش فراخوان ندارید.');
    $payload = ['body' => $body, 'result' => $result];
    if ($type === 'set_follow_up_stage') $payload['follow_up_stage'] = $trackingStage;
    $hash = hash('sha256', emcore_audit_json([$row['id'], $actor, $type, $version, $payload]));
    $q = $db->prepare('SELECT * FROM emcore_procurement_commands WHERE request_id=:request FOR UPDATE');
    $q->execute([':request' => $request]);
    $existing = $q->fetch();
    if ($existing) {
        if ($existing['payload_hash'] !== $hash || $existing['command_state'] !== 'completed' || $existing['app_uid'] !== null) {
            throw new EmcoreHttpException(409, 'شناسهٔ درخواست قبلاً استفاده شده است؛ اطلاعات را تازه کنید.');
        }
        return ['request_id' => $request, 'command_type' => $type, 'command_state' => 'completed', 'replayed' => true];
    }
    $q = $db->prepare('SELECT id FROM emcore_procurement_events WHERE request_id=:request');
    $q->execute([':request' => $request]);
    if ($q->fetchColumn()) throw new EmcoreHttpException(409, 'شناسهٔ درخواست برای رویداد دیگری استفاده شده است.');
    emcore_pw_version($row, $version);
    if ($type === 'set_follow_up_stage') {
        try { $next = emcore_procurement_stage_transition($row, $role, $trackingStage); }
        catch (DomainException $error) { throw new EmcoreHttpException(422, $error->getMessage()); }
    } else $next = emcore_pw_next($row, $role, $type, $result);
    $db->prepare("INSERT INTO emcore_procurement_commands
        (request_id,procurement_id,actor_usr_uid,command_type,expected_version,payload,payload_hash,command_state,completed_at)
        VALUES (:request,:id,:actor,:type,:version,:payload,:hash,'completed',NOW())")
        ->execute([':request' => $request, ':id' => $row['id'], ':actor' => $actor, ':type' => $type,
            ':version' => $version, ':payload' => emcore_audit_json($payload), ':hash' => $hash]);
    if ($type === 'set_follow_up_stage') {
        $update = $db->prepare('UPDATE emcore_procurement_notices SET follow_up_stage=:stage,updated_by_usr_uid=:actor,lock_version=lock_version+1 WHERE id=:id AND lock_version=:version');
        $update->execute([':stage'=>$trackingStage, ':actor'=>$actor, ':id'=>$row['id'], ':version'=>$version]);
        if ($update->rowCount() !== 1) throw new EmcoreHttpException(409, 'نسخهٔ فراخوان تغییر کرده است.');
        emcore_pw_audit($db, $actor, $row['id'], 'procurement_notice', emcore_pw_snapshot($row), $next, $request);
    } else emcore_pw_business_update($db, $row, $next, $actor, $body, $request, $type);
    if ($type !== 'set_follow_up_stage' && $next['workflow_stage'] !== null) {
        $db->prepare("INSERT INTO emcore_procurement_workflows (procurement_id,app_uid,workflow_stage,pending_result,sync_state)
            VALUES (:id,NULL,:stage,:result,'ready') ON DUPLICATE KEY UPDATE
            workflow_stage=VALUES(workflow_stage),pending_result=VALUES(pending_result),sync_state='ready',last_sync_error=NULL")
            ->execute([':id' => $row['id'], ':stage' => $next['workflow_stage'], ':result' => $next['pending_result']]);
    }
    if ($type === 'activate') $next['interest_reason'] = $body;
    if ($type !== 'set_follow_up_stage' && $role === 'operator' && emcore_pw_operator_managed()) $next['operator_managed'] = true;
    $event = emcore_pw_event($db, $row, $actor, $role, $type === 'set_follow_up_stage' ? 'stage_changed' : $type, $body, $request, emcore_pw_snapshot($row), $next);
    return ['request_id' => $request, 'command_type' => $type, 'command_state' => 'completed', 'event_id' => $event, 'replayed' => false];
}

function emcore_pw_workflow($db, $row)
{
    return ['mode' => 'panel', 'workflow_stage' => $row['workflow_stage'], 'pending_result' => $row['pending_result'],
        'legacy_native_link' => !empty($row['app_uid']), 'permissions' => emcore_pw_permissions($row),
        'lock_version' => (int)$row['lock_version']];
}
