[CmdletBinding(SupportsShouldProcess=$true)]
param(
    [string]$ApiDirectory='C:\pmlearning\bpms\workflow\public_html\emcore_api',
    [string]$BackupDirectory
)
$ErrorActionPreference='Stop'
$repoRoot=(Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$sourceRoot=Join-Path $repoRoot 'emcore_api'
$targetRoot=(Resolve-Path -LiteralPath $ApiDirectory).Path
if((Split-Path -Leaf $targetRoot) -ne 'emcore_api'){throw 'Target must be the actual emcore_api directory.'}
if($targetRoot -eq $sourceRoot){throw 'Source and running API directory are the same; no copy is needed.'}
foreach($dependency in @('_bootstrap.php','_module_permissions.php','_audit.php')){
    if(!(Test-Path -LiteralPath (Join-Path $targetRoot $dependency) -PathType Leaf)){throw "Target is missing shared API dependency: $dependency"}
}
$files=@('_minutes_domain.php','_minutes_storage.php','emcore_meeting_minutes.php')
$domainText=Get-Content -LiteralPath (Join-Path $sourceRoot '_minutes_domain.php') -Raw
if($domainText -notmatch "EMCORE_MINUTES_DOMAIN_REVISION = '2026-10-04.2'" -or $domainText.Contains('Jalali conversion function disagrees')){throw 'Repository API is outdated. Pull main before synchronizing.'}
$hashes=@{}
foreach($name in $files){$hashes[$name]=(Get-FileHash -LiteralPath (Join-Path $sourceRoot $name) -Algorithm SHA256).Hash}
$stamp=[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')+'-'+[Guid]::NewGuid().ToString('N')
if(!$BackupDirectory){$BackupDirectory=Join-Path $repoRoot ('.codex-build\minutes-api-backups\'+$stamp)}
$backupRoot=[System.IO.Path]::GetFullPath($BackupDirectory)
$webRoot=Split-Path -Parent $targetRoot
if($backupRoot -eq $webRoot -or $backupRoot.StartsWith($webRoot+[System.IO.Path]::DirectorySeparatorChar,[System.StringComparison]::OrdinalIgnoreCase)){throw 'Backup must be outside the public web root.'}
if(Test-Path -LiteralPath $backupRoot){throw 'Choose a new backup directory; existing backups are never overwritten.'}
if(!$PSCmdlet.ShouldProcess($targetRoot,'Back up, synchronize and verify the three meeting minutes API files')){return}
New-Item -ItemType Directory -Path $backupRoot | Out-Null
$originals=@{};$temporary=@{};$changed=New-Object 'System.Collections.Generic.List[string]'
try {
    # Prepare all files before replacing anything. Temporary PHP files stay PHP.
    foreach($name in $files){
        $target=Join-Path $targetRoot $name
        $originals[$name]=Test-Path -LiteralPath $target -PathType Leaf
        if($originals[$name]){Copy-Item -LiteralPath $target -Destination (Join-Path $backupRoot $name)}
        $temporary[$name]=Join-Path $targetRoot ('_minutes-deploy-'+$stamp+'-'+$name)
        Copy-Item -LiteralPath (Join-Path $sourceRoot $name) -Destination $temporary[$name]
        if((Get-FileHash -LiteralPath $temporary[$name] -Algorithm SHA256).Hash -ne $hashes[$name]){throw "Staging checksum mismatch: $name"}
    }
    foreach($name in $files){
        $changed.Add($name)
        Move-Item -LiteralPath $temporary[$name] -Destination (Join-Path $targetRoot $name) -Force
        if((Get-FileHash -LiteralPath (Join-Path $targetRoot $name) -Algorithm SHA256).Hash -ne $hashes[$name]){throw "Deployed checksum mismatch: $name"}
    }
    $manifest=[ordered]@{revision='2026-10-04.2';target=$targetRoot;utc=[DateTime]::UtcNow.ToString('o');sha256=$hashes}
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $backupRoot 'deployment.json') -Encoding UTF8
    Write-Output "API files synchronized and SHA-256 verified. Revision: 2026-10-04.2"
    Write-Output "Backup: $backupRoot"
    Write-Output 'This only verifies files on disk. Refresh the web PHP OPcache/restart its PHP service if the running response still shows an older revision. CLI opcache_reset does not reset the web PHP cache.'
} catch {
    foreach($name in $changed){
        $target=Join-Path $targetRoot $name
        if($originals[$name]){Copy-Item -LiteralPath (Join-Path $backupRoot $name) -Destination $target -Force}
        elseif(Test-Path -LiteralPath $target){Remove-Item -LiteralPath $target}
    }
    throw
} finally {
    foreach($temporaryPath in $temporary.Values){if(Test-Path -LiteralPath $temporaryPath){Remove-Item -LiteralPath $temporaryPath}}
}
