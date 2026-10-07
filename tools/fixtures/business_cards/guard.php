<?php
function bc_fixture_guard() {
    $dsns=['mysql:host=emcore-bc-test-db;dbname=emcore_business_cards_fixture;charset=utf8mb4',
        'mysql:host=emcore-bc-test-db;dbname=emcore_business_cards_package_fixture;charset=utf8mb4'];
    if (getenv('EMCORE_BC_FIXTURE')!=='1' || !in_array(getenv('EMCORE_DB_DSN'),$dsns,true)) throw new RuntimeException('Disposable business-card fixture only');
}
