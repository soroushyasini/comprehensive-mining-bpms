param([switch]$KeepRunning)
$ErrorActionPreference='Stop'
$repoRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$dbName='emcore-minutes-test-db';$apiName='emcore-minutes-test-api';$networkName='emcore-minutes-test-net'
# Fixed, synthetic credentials; no production configuration is loaded.
function Invoke-Docker { & docker @args; if($LASTEXITCODE -ne 0){throw "Docker operation failed."} }
if((& docker ps -a --format '{{.Names}}') -contains $dbName -or (& docker ps -a --format '{{.Names}}') -contains $apiName){throw 'Minutes fixture containers already exist. Inspect/remove only these named disposable containers before rerunning.'}
$runtimeRoot=Join-Path ([System.IO.Path]::GetTempPath()) 'emcore-minutes-fixture-runtime'
New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
if(!(Test-Path -LiteralPath (Join-Path $runtimeRoot 'jquery.js'))){Invoke-WebRequest 'https://code.jquery.com/jquery-1.11.3.min.js' -OutFile (Join-Path $runtimeRoot 'jquery.js')}
$createdNetwork=$false;$createdDb=$false;$createdApi=$false
try {
    Invoke-Docker build -q -t emcore-minutes-php-test:local -f (Join-Path $repoRoot 'tools/fixtures/minutes/Dockerfile') $repoRoot
    if(!((& docker network ls --format '{{.Name}}') -contains $networkName)){Invoke-Docker network create $networkName;$createdNetwork=$true}
    Invoke-Docker run -d --name $dbName --network $networkName -e MYSQL_ROOT_PASSWORD=minutes-fixture-only -e MYSQL_DATABASE=emcore_minutes_fixture mysql:8.4 --log-bin-trust-function-creators=1
    $createdDb=$true
    $ready=$false
    # TCP excludes MySQL's temporary initialization server (Unix socket only).
    for($attempt=0;$attempt -lt 60;$attempt++){& docker exec $dbName mysqladmin ping --host=127.0.0.1 --protocol=TCP -uroot -pminutes-fixture-only --silent 2>$null | Out-Null;if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 500}
    if(!$ready){throw 'Fixture database did not become ready.'}
    Invoke-Docker run -d --name $apiName --network $networkName -p '127.0.0.1:33382:8080' --mount "type=bind,source=$repoRoot,target=/workspace,readonly" --mount "type=bind,source=$runtimeRoot,target=/fixture-runtime,readonly" -e EMCORE_MINUTES_FIXTURE=1 -e 'EMCORE_DB_DSN=mysql:host=emcore-minutes-test-db;dbname=emcore_minutes_fixture;charset=utf8mb4' -e EMCORE_DB_USER=root -e EMCORE_DB_PASSWORD=minutes-fixture-only -e EMCORE_MINUTES_STORAGE_ROOT=/tmp/minutes-private -e PHP_CLI_SERVER_WORKERS=4 emcore-minutes-php-test:local
    $createdApi=$true
    Invoke-Docker exec $apiName mkdir -p /tmp/minutes-private
    Invoke-Docker exec $apiName php tools/fixtures/minutes/setup.php
    Invoke-Docker exec $apiName php -l emcore_api/_minutes_domain.php
    Invoke-Docker exec $apiName php -l emcore_api/_minutes_storage.php
    Invoke-Docker exec $apiName php -l emcore_api/emcore_meeting_minutes.php
    & node (Join-Path $repoRoot 'tools/check_meeting_minutes_release.js');if($LASTEXITCODE -ne 0){throw 'Minutes release checks failed.'}
    & node (Join-Path $repoRoot 'tools/test_meeting_minutes_integration.js');if($LASTEXITCODE -ne 0){throw 'Minutes API integration tests failed.'}
    & node (Join-Path $repoRoot 'tools/test_meeting_minutes_browser.js');if($LASTEXITCODE -ne 0){throw 'Minutes browser tests failed.'}
    Invoke-Docker exec $apiName php tools/check_meeting_minutes_deployment.php --web-root=tools/fixtures/minutes/public
} finally {
    if(!$KeepRunning){
        if($createdApi){& docker rm -f $apiName | Out-Null}
        if($createdDb){& docker rm -f $dbName | Out-Null}
        if($createdNetwork){& docker network rm $networkName | Out-Null}
    }
}
