param(
    [Parameter(Mandatory=$true)][string]$PhpRoot,
    [switch]$Initialize
)
$ErrorActionPreference='Stop'
# An already-running disposable MariaDB instance must listen on loopback 33379.
# The fixture uses no production data or credentials. Never deploy its router.
$env:EMCORE_DB_DSN='mysql:host=127.0.0.1;port=33379;dbname=emcore_procurement_fixture;charset=utf8mb4'
$env:EMCORE_DB_USER='root'
$env:EMCORE_DB_PASSWORD='emcore-isolated-fixture'
$env:EMCORE_PROCUREMENT_WORKFLOW_ENABLED='true'
$env:EMCORE_PROCUREMENT_WORKFLOW_OPERATOR='1'*32
$env:EMCORE_PROCUREMENT_WORKFLOW_MANAGER='2'*32
$env:EMCORE_PROCUREMENT_WORKFLOW_PROCESS='3'*32
$env:EMCORE_PROCUREMENT_WORKFLOW_ACTIVATION_TASK='4'*32
$env:EMCORE_PROCUREMENT_WORKFLOW_FOLLOW_UP_TASK='5'*32
$env:EMCORE_PROCUREMENT_WORKFLOW_RESULT_TASK='6'*32
$env:EMCORE_PROCUREMENT_WORKFLOW_START_URL='/native/start'
$env:EMCORE_PROCUREMENT_WORKFLOW_CASE_URL_PREFIX='/native/case?APP_UID='
$env:EMCORE_PROCUREMENT_STORAGE_ROOT=Join-Path $env:TEMP 'emcore-procurement-test-storage'
New-Item -ItemType Directory -Path $env:EMCORE_PROCUREMENT_STORAGE_ROOT -Force | Out-Null
$env:EMCORE_FIXTURE_JQUERY=Join-Path $env:TEMP 'emcore-fixture-jquery1113.js'
if(!(Test-Path $env:EMCORE_FIXTURE_JQUERY)) {
    Invoke-WebRequest 'https://code.jquery.com/jquery-1.11.3.min.js' -OutFile $env:EMCORE_FIXTURE_JQUERY
}
$php=Join-Path $PhpRoot 'php.exe'
$extensionPath=(Get-Item (Join-Path $PhpRoot 'ext')).FullName.Replace('\','/')
$phpFlags=@('-n','-d',('extension_dir="'+$extensionPath+'"'),'-d','extension=pdo_mysql','-d','extension=mbstring','-d','extension=fileinfo')
if($Initialize) {
    & $php @phpFlags tools/fixtures/procurement_setup.php
    if($LASTEXITCODE -ne 0) { throw 'Fixture schema initialization failed.' }
}
$listener=Start-Process -FilePath $php -ArgumentList ($phpFlags+@('-d','upload_max_filesize=50M','-d','post_max_size=55M','-S','127.0.0.1:33380','-t','tools/fixtures','tools/fixtures/procurement_router.php')) -WindowStyle Hidden -PassThru -RedirectStandardError (Join-Path $env:TEMP 'emcore-fixture-api-errors.log')
try {
    $ready=$false
    for($attempt=0;$attempt -lt 30;$attempt++) {
        try { Invoke-WebRequest 'http://127.0.0.1:33380/panel' -TimeoutSec 1 | Out-Null; $ready=$true; break }
        catch { Start-Sleep -Milliseconds 100 }
    }
    if(!$ready) { throw 'Fixture HTTP listener did not start.' }
    & node tools/test_procurement_workflow_integration.js
    if($LASTEXITCODE -ne 0) { throw 'Workflow integration suite failed.' }
    if($env:EMCORE_TEST_NODE_MODULES) {
        & node tools/test_procurement_workflow_browser.js
        if($LASTEXITCODE -ne 0) { throw 'Workflow browser suite failed.' }
    }
} finally {
    # Stop only the process created by this invocation, never other PHP servers.
    if(!$listener.HasExited) { Stop-Process -Id $listener.Id }
}
