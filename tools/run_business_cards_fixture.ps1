param([switch]$KeepRunning, [string]$Manifest = 'dataset/business_cards_manifest.json')
$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if (!(Test-Path -LiteralPath (Join-Path $repoRoot $Manifest))) { throw 'Generate the private manifest with tools/build_business_cards_manifest.py first.' }
if ($Manifest -match '(^[A-Za-z]:|^/|\.\.)') { throw 'Fixture manifest must be a repository-relative path.' }
$dbName='emcore-bc-test-db'; $apiName='emcore-bc-test-api'; $networkName='emcore-bc-test-net'
function Invoke-BcDocker { & docker @args; if ($LASTEXITCODE -ne 0) { throw 'Business-card fixture Docker operation failed.' } }
$existing = & docker ps -a --format '{{.Names}}'
if ($existing -contains $dbName -or $existing -contains $apiName) { throw 'Named business-card fixtures already exist. Inspect them before starting a new disposable fixture.' }
$runtimeRoot = Join-Path ([System.IO.Path]::GetTempPath()) 'emcore-bc-fixture-runtime'
New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
if (!(Test-Path -LiteralPath (Join-Path $runtimeRoot 'jquery.js'))) { Invoke-WebRequest 'https://code.jquery.com/jquery-1.11.3.min.js' -OutFile (Join-Path $runtimeRoot 'jquery.js') }
$createdDb=$false; $createdApi=$false; $createdNetwork=$false
try {
    Invoke-BcDocker build -q -t emcore-business-cards-php-test:local -f (Join-Path $repoRoot 'tools/fixtures/business_cards/Dockerfile') $repoRoot
    if (!((& docker network ls --format '{{.Name}}') -contains $networkName)) { Invoke-BcDocker network create $networkName; $createdNetwork=$true }
    Invoke-BcDocker run -d --name $dbName --network $networkName -e MYSQL_ROOT_PASSWORD=bc-fixture-only -e MYSQL_DATABASE=emcore_business_cards_fixture mysql:8.4
    $createdDb=$true; $ready=$false
    for ($attempt=0; $attempt -lt 100; $attempt++) {
        & docker exec $dbName mysqladmin ping --host=127.0.0.1 --protocol=TCP -uroot -pbc-fixture-only --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }; Start-Sleep -Milliseconds 500
    }
    if (!$ready) { throw 'Fixture MySQL did not become ready.' }
    Invoke-BcDocker run -d --name $apiName --network $networkName -p '127.0.0.1:33383:8080' --mount "type=bind,source=$repoRoot,target=/workspace,readonly" --mount "type=bind,source=$runtimeRoot,target=/fixture-runtime,readonly" -e EMCORE_BC_FIXTURE=1 -e 'EMCORE_DB_DSN=mysql:host=emcore-bc-test-db;dbname=emcore_business_cards_fixture;charset=utf8mb4' -e EMCORE_DB_USER=root -e EMCORE_DB_PASSWORD=bc-fixture-only -e EMCORE_BUSINESS_CARDS_STORAGE_ROOT=/tmp/business-cards-private -e PHP_CLI_SERVER_WORKERS=4 emcore-business-cards-php-test:local
    $createdApi=$true
    Invoke-BcDocker exec $apiName mkdir -p /tmp/business-cards-private
    Invoke-BcDocker exec $apiName php tools/fixtures/business_cards/setup.php
    foreach ($file in @('_business_cards_domain.php','_business_cards_storage.php','emcore_business_cards.php')) { Invoke-BcDocker exec $apiName php -l "emcore_api/$file" }
    $importArgs=@('exec',$apiName,'php','-d','memory_limit=512M','tools/import_business_cards.php',"--manifest=$Manifest",'--source-root=cart','--actor=00000000000000000000000000000001')
    Invoke-BcDocker @importArgs
    Invoke-BcDocker @importArgs --commit
    Invoke-BcDocker @importArgs --commit
    Invoke-BcDocker exec $apiName php tools/fixtures/business_cards/verify_sources.php $Manifest
    Invoke-BcDocker exec $apiName php tools/fixtures/business_cards/check_changed_source.php $Manifest
    foreach ($test in @('check_business_cards_release.js','test_business_cards_integration.js','test_business_cards_browser.js')) {
        & node (Join-Path $repoRoot "tools/$test"); if ($LASTEXITCODE -ne 0) { throw "Failed: $test" }
    }
} finally {
    if (!$KeepRunning) {
        if ($createdApi) { & docker rm -f $apiName | Out-Null }
        if ($createdDb) { & docker rm -f $dbName | Out-Null }
        if ($createdNetwork) { & docker network rm $networkName | Out-Null }
    }
}
