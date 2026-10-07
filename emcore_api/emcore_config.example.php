<?php

// Copy to emcore_config.php (kept out of Git), or set equivalent environment
// variables in the PHP runtime.
return [
    'db_dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=wf_pishro;charset=utf8mb4',
    'db_user' => 'emcore_app',
    'db_password' => 'replace-with-a-secret',
    // Must exist, be writable by PHP, and remain outside every web-served directory.
    'trade_storage_root' => 'C:\\pmlearning\\emcore-private\\trade-documents',
    'trade_max_upload_bytes' => 52428800,
    'procurement_storage_root' => 'C:\\pmlearning\\emcore-private\\procurement-notices',
    'procurement_max_upload_bytes' => 52428800,
    'minutes_storage_root' => 'C:\\pmlearning\\emcore-private\\meeting-minutes',
    'minutes_max_upload_bytes' => 52428800,
    'business_cards_storage_root' => 'C:\\pmlearning\\emcore-private\\business-cards',
    'business_cards_max_upload_bytes' => 10485760,
    // Keep disabled until migration 012, participant initialization and native
    // process installation are complete. Never guess user/task/process UIDs.
    'procurement_workflow_enabled' => false,
    'procurement_workflow_operator' => '',
    'procurement_workflow_manager' => '',
    'procurement_workflow_process' => '',
    'procurement_workflow_activation_task' => '',
    'procurement_workflow_follow_up_task' => '',
    'procurement_workflow_result_task' => '',
    // Same-origin URLs verified in your installation. Case UID is appended.
    'procurement_workflow_start_url' => '',
    'procurement_workflow_case_url_prefix' => '',
];
