<?php
// Compatibility tombstone: do not connect to the database or modify old cases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
echo json_encode(['state' => 'retired', 'mode' => 'panel',
    'message' => 'No reconciliation is required. Remove the old procurement Task Scheduler job.']) . PHP_EOL;
