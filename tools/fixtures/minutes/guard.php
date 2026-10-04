<?php
// Test harness only. No production schema/session is accepted.
function minutes_fixture_guard()
{
    if (getenv('EMCORE_MINUTES_FIXTURE') !== '1'
        || getenv('EMCORE_DB_DSN') !== 'mysql:host=emcore-minutes-test-db;dbname=emcore_minutes_fixture;charset=utf8mb4') {
        http_response_code(404); exit('Isolated minutes fixture required.');
    }
}
