<?php
define('EMCORE_NATIVE_CONTEXT', true);
require_once __DIR__ . '/../emcore_api/_procurement_storage.php';
$checks = 0;
function upload_check($condition, $message) {
    global $checks;
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
}
upload_check(in_array('rar', emcore_procurement_allowed_extensions(), true), 'RAR extension');
upload_check(in_array('zip', emcore_procurement_allowed_extensions(), true), 'ZIP extension');
upload_check(in_array('application/vnd.rar', emcore_procurement_allowed_mimes('rar'), true), 'Registered RAR MIME');
upload_check(emcore_procurement_archive_signature_matches('rar', "Rar!\x1a\x07\x00\xcf"), 'RAR4 signature');
upload_check(emcore_procurement_archive_signature_matches('rar', "Rar!\x1a\x07\x01\x00"), 'RAR5 signature');
upload_check(!emcore_procurement_archive_signature_matches('rar', "Rar!\x1a\x07\x01"), 'Truncated RAR5 rejected');
upload_check(!emcore_procurement_archive_signature_matches('rar', 'MZfake!!'), 'Renamed executable rejected');
upload_check(!emcore_procurement_archive_signature_matches('rar', "PK\x03\x04test"), 'ZIP renamed RAR rejected');
upload_check(emcore_procurement_archive_signature_matches('zip', "PK\x03\x04test"), 'ZIP local header');
upload_check(emcore_procurement_archive_signature_matches('zip', "PK\x05\x06test"), 'Empty ZIP header');
upload_check(!emcore_procurement_archive_signature_matches('zip', 'fake ZIP'), 'Fake ZIP rejected');
echo "Procurement upload policy: {$checks} checks passed.\n";
