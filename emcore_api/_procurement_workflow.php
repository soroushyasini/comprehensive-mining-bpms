<?php
require_once __DIR__ . '/_procurement_storage.php';
require_once __DIR__ . '/_procurement_workflow_policy.php';

function emcore_pw_settings()
{
    static $settings;
    if ($settings !== null) return $settings;
    $file = __DIR__ . '/emcore_config.php';
    $local = is_file($file) ? require $file : [];
    $keys = ['enabled','operator','manager','process','activation_task','follow_up_task','result_task','start_url','case_url_prefix'];
    $settings = [];
    foreach ($keys as $key) {
        $env = getenv('EMCORE_PROCUREMENT_WORKFLOW_' . strtoupper($key));
        $settings[$key] = $env !== false ? $env : ($local['procurement_workflow_' . $key] ?? '');
    }
    $settings['enabled'] = filter_var($settings['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($settings['enabled'] === null) throw new RuntimeException('Invalid procurement workflow enable flag.');
    if ($settings['enabled']) {
        foreach (['operator','manager','process','activation_task','follow_up_task','result_task'] as $key) {
            if (!preg_match('/^[a-zA-Z0-9]{32}$/', (string)$settings[$key])) throw new RuntimeException('Incomplete procurement workflow configuration: ' . $key);
        }
        if ($settings['operator'] === $settings['manager']) throw new RuntimeException('Procurement operator and manager must differ.');
        if (count(array_unique([$settings['activation_task'],$settings['follow_up_task'],$settings['result_task']])) !== 3) throw new RuntimeException('Procurement workflow requires three distinct tasks.');
        foreach (['start_url','case_url_prefix'] as $key) {
            if (substr($settings[$key], 0, 1) !== '/' || substr($settings[$key], 0, 2) === '//'
                || preg_match('/[\r\n\\\\]/', $settings[$key])) throw new RuntimeException('Invalid same-origin workflow URL.');
        }
    }
    return $settings;
}

function emcore_pw_enabled() { return emcore_pw_settings()['enabled']; }

function emcore_pw_user_role()
{
    $role = emcore_procurement_role(emcore_current_user()['USR_UID'], emcore_pw_settings());
    if (!$role) throw new EmcoreHttpException(403, 'نقش ثبت یا تصمیم‌گیری فراخوان برای شما تعریف نشده است.');
    return $role;
}

function emcore_pw_scope($alias = 'p')
{
    if (!emcore_pw_enabled()) return ['sql' => '1 = 1', 'params' => []];
    $role = emcore_pw_user_role();
    $settings = emcore_pw_settings();
    return ['sql' => $alias . '.owner_usr_uid = :pw_owner AND ' . $alias . '.manager_usr_uid = :pw_manager',
        'params' => [':pw_owner' => $settings['operator'], ':pw_manager' => $settings['manager']]];
}

function emcore_pw_record($db, $id, $lock = false)
{
    $scope = emcore_pw_scope();
    $stmt = $db->prepare("SELECT p.*, w.app_uid, w.workflow_stage, w.sync_state, w.pending_result, w.last_del_index
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

function emcore_pw_assert_edit($db, $id, $delete = false)
{
    if (!emcore_pw_enabled()) return;
    $row = emcore_pw_record($db, $id, true);
    if (!emcore_procurement_can_edit($row, emcore_pw_user_role()) || ($delete && $row['app_uid'])) {
        throw new EmcoreHttpException(403, 'ویرایش یا حذف این مورد در مرحلهٔ جاری مجاز نیست.');
    }
}

function emcore_pw_permissions($row = null)
{
    if (!emcore_pw_enabled()) return ['enabled' => false];
    $role = emcore_pw_user_role();
    return ['enabled' => true, 'role' => $role,
        'can_create' => $role === 'operator',
        'can_edit' => $row ? emcore_procurement_can_edit($row, $role) : false,
        'can_decide' => $role === 'manager' && (!$row || !$row['app_uid']),
        'can_stop' => $role === 'manager' && $row && in_array($row['workflow_stage'], ['follow_up','result_review'], true) && $row['sync_state'] === 'ready',
        'start_url' => emcore_pw_settings()['start_url']];
}

function emcore_pw_request_id()
{
    $id = emcore_string('request_id', true, 32);
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new EmcoreHttpException(422, 'شناسه درخواست معتبر نیست.');
    return $id;
}

function emcore_pw_snapshot($row)
{
    unset($row['legacy_source_data'], $row['files']);
    return $row;
}

function emcore_pw_audit($db, $actor, $id, $entity, $before, $after, $request)
{
    // Actor comes only from a validated HTTP/native context or persisted command.
    $db->prepare("INSERT INTO emcore_audit_log
        (request_id,actor_usr_uid,module_key,action,entity_type,entity_id,before_data,after_data,metadata)
        VALUES (:request,:actor,'procurement_notices','update',:entity,:id,:before,:after,:meta)")
        ->execute([':request' => $request, ':actor' => $actor, ':entity' => $entity, ':id' => (string)$id,
            ':before' => emcore_audit_json($before), ':after' => emcore_audit_json($after),
            ':meta' => emcore_audit_json(['source' => 'procurement_workflow'])]);
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

function emcore_pw_business_update($db, $row, $next, $actor, $reason, $request)
{
    $update = $db->prepare("UPDATE emcore_procurement_notices SET participation_status = :status,
        interest_reason = CASE WHEN :is_interest = 1 THEN :reason ELSE interest_reason END,
        workflow_history_only = 0, updated_by_usr_uid = :actor, lock_version = lock_version + 1
        WHERE id = :id AND lock_version = :version");
    $update->execute([':status' => $next['participation_status'], ':is_interest' => $row['app_uid'] && $row['workflow_stage'] === 'activation' ? 1 : 0,
            ':reason' => $reason, ':actor' => $actor, ':id' => $row['id'], ':version' => $row['lock_version']]);
    if ($update->rowCount() !== 1) throw new EmcoreHttpException(409, 'نسخهٔ فراخوان تغییر کرده است.');
    emcore_pw_audit($db, $actor, $row['id'], 'procurement_notice', emcore_pw_snapshot($row), $next, $request);
}

function emcore_pw_next($row, $role, $action, $result = null)
{
    try { return emcore_procurement_transition($row, $role, $action, $result); }
    catch (DomainException $error) { throw new EmcoreHttpException(422, $error->getMessage()); }
}

function emcore_pw_case_state($db, $app)
{
    $stmt = $db->prepare('SELECT PRO_UID, APP_STATUS FROM APPLICATION WHERE APP_UID = :app');
    $stmt->execute([':app' => $app]);
    $case = $stmt->fetch();
    if (!$case || $case['PRO_UID'] !== emcore_pw_settings()['process']) throw new EmcoreHttpException(409, 'پرونده با فرایند پیگیری فراخوان سازگار نیست.');
    $stmt = $db->prepare("SELECT DEL_INDEX,TAS_UID,USR_UID FROM APP_DELEGATION
        WHERE APP_UID = :app AND DEL_THREAD_STATUS = 'OPEN' AND DEL_FINISH_DATE IS NULL ORDER BY DEL_INDEX DESC");
    $stmt->execute([':app' => $app]);
    $case['tasks'] = $stmt->fetchAll();
    if (count($case['tasks']) > 1) throw new EmcoreHttpException(409, 'فرایند پیگیری نباید چند گام موازی فعال داشته باشد.');
    return $case;
}

function emcore_pw_native_cancel_guard($db, $app, $process)
{
    if (!emcore_pw_enabled() || $process !== emcore_pw_settings()['process'] || emcore_pw_user_role() !== 'manager') {
        throw new EmcoreHttpException(403, 'لغو این پرونده فقط برای مدیر تصمیم‌گیرنده مجاز است.');
    }
    emcore_require_permission('procurement_notices', 'read');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT procurement_id FROM emcore_procurement_workflows WHERE app_uid=:app');$q->execute([':app'=>$app]);$id=$q->fetchColumn();
        if (!$id) throw new EmcoreHttpException(409, 'پیش‌نویس بدون پیگیری را حذف کنید؛ توقف فقط برای پروندهٔ فعال است.');
        $row=emcore_pw_record($db,$id,true);
        $q=$db->prepare("SELECT * FROM emcore_procurement_commands WHERE procurement_id=:id AND command_type='request_stop' AND command_state='pending' FOR UPDATE");
        $q->execute([':id'=>$id]);$commands=$q->fetchAll();
        if (count($commands)!==1 || $commands[0]['actor_usr_uid']!==emcore_current_user()['USR_UID']) throw new EmcoreHttpException(409, 'ابتدا دلیل توقف را در پنل ثبت کنید.');
        emcore_pw_version($row,$commands[0]['expected_version']);
        $payload=json_decode($commands[0]['payload'],true);
        if (!trim($payload['body']??'')) throw new EmcoreHttpException(422, 'دلیل توقف الزامی است.');
        $case=emcore_pw_case_state($db,$app);
        if (!in_array($case['APP_STATUS'],['TO_DO','DRAFT'],true)) throw new EmcoreHttpException(409, 'پرونده در وضعیت قابل توقف نیست.');
        $db->commit();
    } catch(Throwable $error) {if($db->inTransaction())$db->rollBack();throw $error;}
}

function emcore_pw_prepare($db, $row, $actor, $role, $type, $body, $result, $request, $version)
{
    $payload = ['body' => $body, 'result' => $result];
    $hash = hash('sha256', emcore_audit_json([$row['id'], $actor, $type, $version, $payload]));
    $stmt = $db->prepare('SELECT * FROM emcore_procurement_commands WHERE request_id = :request FOR UPDATE');
    $stmt->execute([':request' => $request]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['payload_hash'] !== $hash) throw new EmcoreHttpException(409, 'شناسه درخواست قبلاً برای دادهٔ دیگری استفاده شده است.');
        if (in_array($existing['command_state'], ['abandoned','error'], true)) throw new EmcoreHttpException(409,'این درخواست جایگزین یا متوقف شده است؛ فرمان تازه آماده کنید.');
        return $existing;
    }
    emcore_pw_version($row, $version);
    $next = emcore_pw_next($row, $role, $type, $result);
    // Only the latest unsubmitted intention remains selectable in native forms.
    $db->prepare("UPDATE emcore_procurement_commands SET command_state='abandoned',completed_at=NOW()
        WHERE procurement_id=:id AND actor_usr_uid=:actor AND command_state='prepared'")
        ->execute([':id'=>$row['id'],':actor'=>$actor]);
    $app = $row['app_uid'];
    $index = null;
    if ($app) {
        $case = emcore_pw_case_state($db, $app);
        $task = $case['tasks'][0] ?? null;
        $expectedTask = emcore_pw_settings()[$row['workflow_stage'] === 'result_review' ? 'result_task' : 'follow_up_task'];
        if (!$task || $task['TAS_UID'] !== $expectedTask || !in_array($case['APP_STATUS'], ['TO_DO','DRAFT'], true)) throw new EmcoreHttpException(409, 'گام واقعی پرونده با پنل همگام نیست.');
        if ($type !== 'request_stop' && $task['USR_UID'] !== $actor) throw new EmcoreHttpException(403, 'این گام به شما ارجاع نشده است.');
        $index = (int)$task['DEL_INDEX'];
    }
    $db->prepare("INSERT INTO emcore_procurement_commands
        (request_id,procurement_id,actor_usr_uid,app_uid,source_del_index,command_type,expected_version,payload,payload_hash,command_state)
        VALUES (:request,:notice,:actor,:app,:idx,:type,:version,:payload,:hash,:state)")
        ->execute([':request' => $request, ':notice' => $row['id'], ':actor' => $actor, ':app' => $app, ':idx' => $index,
            ':type' => $type, ':version' => $version, ':payload' => emcore_audit_json($payload), ':hash' => $hash,
            ':state' => $type === 'request_stop' ? 'pending' : 'prepared']);
    if ($type === 'request_stop') {
        $db->prepare("UPDATE emcore_procurement_workflows SET sync_state = 'pending' WHERE procurement_id = :id")->execute([':id' => $row['id']]);
    }
    return ['request_id' => $request, 'command_type' => $type, 'command_state' => $type === 'request_stop' ? 'pending' : 'prepared'];
}

function emcore_pw_workflow($db, $row)
{
    $stmt = $db->prepare("SELECT request_id,command_type,command_state FROM emcore_procurement_commands
        WHERE procurement_id = :id AND command_state = 'pending' ORDER BY created_at LIMIT 1");
    $stmt->execute([':id' => $row['id']]);
    return ['app_uid' => $row['app_uid'], 'workflow_stage' => $row['workflow_stage'], 'sync_state' => $row['sync_state'],
        'pending_result' => $row['pending_result'], 'pending_command' => $stmt->fetch() ?: null,
        'case_url' => emcore_pw_case_url($row),
        'permissions' => emcore_pw_permissions($row), 'lock_version' => (int)$row['lock_version']];
}

function emcore_pw_case_url($row)
{
    if (!$row['app_uid']) return null;
    return emcore_pw_settings()['case_url_prefix'] . rawurlencode($row['app_uid'])
        . '&DEL_INDEX=' . max(1,(int)($row['last_del_index']??1)) . '&action=draft';
}

function emcore_pw_native_before_route($db, $request, $context)
{
    // $context is supplied by installed PHP triggers, never by HTTP POST.
    if (!emcore_pw_enabled()) throw new RuntimeException('Procurement workflow is disabled.');
    emcore_require_permission('procurement_notices', 'read');
    $actor = emcore_current_user()['USR_UID'];
    $settings = emcore_pw_settings();
    if ($context['process'] !== $settings['process'] || $context['user'] !== $actor) throw new EmcoreHttpException(403, 'زمینه فرایند معتبر نیست.');
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT procurement_id FROM emcore_procurement_commands WHERE request_id = :request');
        $q->execute([':request' => $request]);
        $id = $q->fetchColumn();
        if (!$id) throw new EmcoreHttpException(422, 'فرمان معتبر انتخاب نشده است.');
        $row = emcore_pw_record($db, $id, true);
        $q = $db->prepare('SELECT * FROM emcore_procurement_commands WHERE request_id = :request FOR UPDATE');
        $q->execute([':request' => $request]); $command = $q->fetch();
        if ($command['actor_usr_uid'] !== $actor || !in_array($command['command_state'], ['prepared','pending'], true)) throw new EmcoreHttpException(403, 'فرمان قابل اجرا نیست.');
        $case = emcore_pw_case_state($db, $context['app']);
        $task = $case['tasks'][0] ?? null;
        if (!$task || $task['USR_UID'] !== $actor || (int)$task['DEL_INDEX'] !== (int)$context['index'] || $task['TAS_UID'] !== $context['task']) throw new EmcoreHttpException(409, 'گام جاری تغییر کرده است.');
        $type = $command['command_type'];
        $expected = $type === 'activate' ? $settings['activation_task'] : ($type === 'propose_result' ? $settings['follow_up_task'] : $settings['result_task']);
        if ($context['task'] !== $expected || !in_array($type,['activate','propose_result','approve_result','return_follow_up'],true)) throw new EmcoreHttpException(403, 'این فرمان در این گام قابل اجرا نیست.');
        if ($command['command_state'] === 'prepared') {
            emcore_pw_version($row, $command['expected_version']);
            $payload = json_decode($command['payload'], true);
            emcore_pw_next($row, emcore_pw_user_role(), $type, $payload['result']);
            if ($type === 'activate') {
                $db->prepare("INSERT INTO emcore_procurement_workflows (procurement_id,app_uid,workflow_stage,sync_state,last_del_index)
                    VALUES (:id,:app,'activation','pending',:idx)")->execute([':id' => $id, ':app' => $context['app'], ':idx' => $context['index']]);
            } else {
                if ($row['app_uid'] !== $context['app'] || (int)$command['source_del_index'] !== (int)$context['index']) throw new EmcoreHttpException(409, 'فرمان متعلق به پروندهٔ دیگری است.');
                $db->prepare("UPDATE emcore_procurement_workflows SET sync_state = 'pending' WHERE procurement_id = :id")->execute([':id' => $id]);
            }
            $db->prepare("UPDATE emcore_procurement_commands SET app_uid = :app,source_del_index = :idx,command_state = 'pending' WHERE request_id = :request")
                ->execute([':app' => $context['app'], ':idx' => $context['index'], ':request' => $request]);
        } elseif ($command['app_uid'] !== $context['app'] || (int)$command['source_del_index'] !== (int)$context['index']) {
            throw new EmcoreHttpException(409, 'فرمان در گام دیگری در حال اجراست.');
        }
        $db->commit();
        return ['operator_uid' => $row['owner_usr_uid'], 'manager_uid' => $row['manager_usr_uid'], 'route' => $type === 'approve_result' ? 'finish' : ($type === 'return_follow_up' ? 'return' : 'continue')];
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function emcore_pw_reconcile_one($db, $id)
{
    $db->beginTransaction();
    try {
        // Background reconciliation has no user session. Stored participants are
        // checked against the configured pair instead of manufacturing a session.
        $q = $db->prepare('SELECT p.*,w.app_uid,w.workflow_stage,w.sync_state,w.pending_result FROM emcore_procurement_notices p JOIN emcore_procurement_workflows w ON w.procurement_id=p.id WHERE p.id=:id FOR UPDATE');
        $q->execute([':id' => $id]); $row = $q->fetch();
        $settings = emcore_pw_settings();
        if (!$row || $row['owner_usr_uid'] !== $settings['operator'] || $row['manager_usr_uid'] !== $settings['manager']) throw new RuntimeException('Unconfigured workflow participants.');
        $case = emcore_pw_case_state($db, $row['app_uid']);
        $q = $db->prepare("SELECT * FROM emcore_procurement_commands WHERE procurement_id=:id AND command_state='pending' ORDER BY created_at FOR UPDATE");
        $q->execute([':id' => $id]); $commands = $q->fetchAll();
        if (count($commands) > 1) throw new RuntimeException('More than one pending command.');
        $command = $commands[0] ?? null;
        $cancelled = in_array($case['APP_STATUS'], ['CANCELED','CANCELLED'], true);
        if (!$command && !$cancelled) {
            $actual = $case['tasks'][0] ?? null;
            $stage = $row['workflow_stage'];
            if ($stage === 'completed' && $case['APP_STATUS'] === 'COMPLETED' && !$actual) {
                $db->commit(); return 'unchanged';
            }
            $expectedTask = $settings[$stage === 'result_review' ? 'result_task' : 'follow_up_task'];
            $expectedActor = $stage === 'result_review' ? $row['manager_usr_uid'] : $row['owner_usr_uid'];
            if (!$actual || $actual['TAS_UID'] !== $expectedTask || $actual['USR_UID'] !== $expectedActor) {
                throw new RuntimeException('Native case moved without an approved command; inspect workflow history.');
            }
            $db->commit(); return 'unchanged';
        }
        if (!$command && $row['workflow_stage'] === 'stopped') { $db->commit(); return 'unchanged'; }
        if (!$command) {
            $actor = str_repeat('0', 32); $role = 'system';
            $type = 'request_stop'; $request = md5('procurement-native-cancel:' . $row['app_uid']);
            $body = 'پرونده در ProcessMaker لغو شد. دلیل و عامل لغو در سابقهٔ بومی ProcessMaker قابل بررسی است.';
            $result = null;
        } else {
            $actor = $command['actor_usr_uid']; $role = emcore_procurement_role($actor, $settings);
            $type = $command['command_type']; $request = $command['request_id'];
            $payload = json_decode($command['payload'], true); $body = $payload['body']; $result = $payload['result'];
            emcore_pw_version($row, $command['expected_version']);
        }
        $ready = $row; $ready['sync_state'] = 'ready';
        if ($type === 'activate') $ready['workflow_stage'] = null;
        if ($role === 'system') $roleForPolicy = 'manager'; else $roleForPolicy = $role;
        if ($cancelled && $type !== 'request_stop') throw new RuntimeException('Case cancelled during another pending transition; inspect before reconciliation.');
        $next = emcore_pw_next($ready, $roleForPolicy, $type, $result);
        $task = $case['tasks'][0] ?? null;
        if ($next['workflow_stage'] === 'stopped') $matched = $cancelled;
        elseif ($next['workflow_stage'] === 'completed') $matched = $case['APP_STATUS'] === 'COMPLETED' && !$task;
        else {
            $expectedTask = $settings[$next['workflow_stage'] === 'follow_up' ? 'follow_up_task' : 'result_task'];
            $expectedActor = $next['workflow_stage'] === 'follow_up' ? $row['owner_usr_uid'] : $row['manager_usr_uid'];
            $matched = $task && $task['TAS_UID'] === $expectedTask && $task['USR_UID'] === $expectedActor
                && (int)$task['DEL_INDEX'] > (int)$command['source_del_index'];
        }
        if (!$matched) {
            if ($case['APP_STATUS'] === 'COMPLETED' || ($task && (int)$task['DEL_INDEX'] > (int)$command['source_del_index'])) {
                throw new RuntimeException('Native routing reached an unexpected task or assignee; inspect process configuration.');
            }
            $db->commit(); return 'pending';
        }
        emcore_pw_business_update($db, $row, $next, $actor, $body, $request);
        $db->prepare("UPDATE emcore_procurement_workflows SET workflow_stage=:stage,pending_result=:result,sync_state='ready',last_sync_error=NULL,last_del_index=:idx WHERE procurement_id=:id")
            ->execute([':stage' => $next['workflow_stage'], ':result' => $next['pending_result'], ':idx' => $task ? $task['DEL_INDEX'] : ($command['source_del_index'] ?? 1), ':id' => $id]);
        emcore_pw_event($db, $row, $actor, $role, $type, $body, $request, emcore_pw_snapshot($row), $next);
        if ($command) $db->prepare("UPDATE emcore_procurement_commands SET command_state='completed',completed_at=NOW() WHERE request_id=:request")->execute([':request' => $request]);
        $db->commit(); return 'completed';
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        $db->prepare("UPDATE emcore_procurement_workflows SET sync_state='error',last_sync_error=:error WHERE procurement_id=:id")
            ->execute([':error' => mb_substr($error->getMessage(), 0, 1000), ':id' => $id]);
        throw $error;
    }
}
