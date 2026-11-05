param([switch]$KeepRunning)
$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$dbName='emcore-mh-test-db'; $apiName='emcore-mh-test-api'; $networkName='emcore-mh-test-net'
function Invoke-MhDocker { & docker @args; if ($LASTEXITCODE -ne 0) { throw 'Mine-history fixture Docker operation failed.' } }
$existing = & docker ps -a --format '{{.Names}}'
if ($LASTEXITCODE -ne 0) { throw 'Docker must be available to start the disposable fixture.' }
if ($existing -contains $dbName -or $existing -contains $apiName) { throw 'Named mine-history fixtures already exist. Inspect them before starting a new disposable fixture.' }
$runtimeRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('emcore-mh-fixture-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
Invoke-WebRequest 'https://code.jquery.com/jquery-1.11.3.min.js' -OutFile (Join-Path $runtimeRoot 'jquery.js')
$createdDb=$false; $createdApi=$false; $createdNetwork=$false
try {
    Invoke-MhDocker build -q -t emcore-mine-history-php-test:local -f (Join-Path $repoRoot 'tools/fixtures/mine_history/Dockerfile') $repoRoot
    if (!((& docker network ls --format '{{.Name}}') -contains $networkName)) { Invoke-MhDocker network create $networkName; $createdNetwork=$true }
    Invoke-MhDocker run -d --name $dbName --network $networkName -e MYSQL_ROOT_PASSWORD=mh-fixture-only -e MYSQL_DATABASE=emcore_mine_history_fixture mysql:8.4
    $createdDb=$true; $ready=$false
    for ($attempt=0; $attempt -lt 100; $attempt++) {
        & docker exec $dbName mysqladmin ping --host=127.0.0.1 --protocol=TCP -uroot -pmh-fixture-only --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }; Start-Sleep -Milliseconds 500
    }
    if (!$ready) { throw 'Fixture MySQL did not become ready.' }
    Invoke-MhDocker run -d --name $apiName --network $networkName -p '127.0.0.1:33388:8080' --mount "type=bind,source=$repoRoot,target=/workspace,readonly" --mount "type=bind,source=$runtimeRoot,target=/fixture-runtime,readonly" -e EMCORE_MH_FIXTURE=1 -e 'EMCORE_DB_DSN=mysql:host=emcore-mh-test-db;dbname=emcore_mine_history_fixture;charset=utf8mb4' -e EMCORE_DB_USER=root -e EMCORE_DB_PASSWORD=mh-fixture-only -e EMCORE_MINE_HISTORY_STORAGE_ROOT=/tmp/mine-history-private -e EMCORE_FIXTURE_JQUERY=/fixture-runtime/jquery.js emcore-mine-history-php-test:local
    $createdApi=$true
    Invoke-MhDocker exec $apiName mkdir -p /tmp/mine-history-private
    Invoke-MhDocker exec $apiName php tools/fixtures/mine_history/setup.php
    Invoke-MhDocker exec $apiName php tools/test_mine_history_domain.php
    foreach ($file in @('_mine_history_domain.php','_mine_history_storage.php','emcore_mine_history.php','emcore_mines.php')) { Invoke-MhDocker exec $apiName php -l "emcore_api/$file" }
    foreach ($test in @('test_mine_history_integration.js','test_mine_history_browser.js')) {
        & node (Join-Path $repoRoot "tools/$test"); if ($LASTEXITCODE -ne 0) { throw "Failed: $test" }
    }
    Invoke-MhDocker exec $apiName php tools/fixtures/mine_history/verify.php
} finally {
    if (!$KeepRunning) {
        if ($createdApi) { & docker rm -f $apiName | Out-Null }
        if ($createdDb) { & docker rm -f $dbName | Out-Null }
        if ($createdNetwork) { & docker network rm $networkName | Out-Null }
    }
}
