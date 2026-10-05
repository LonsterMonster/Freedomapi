param(
    [string]$Php = 'php',
    [string]$CorePath = (Join-Path $PSScriptRoot '..\..\api-platform2'),
    [string]$ExtensionDir = ''
)
$ErrorActionPreference = 'Stop'
$env:FREEDOMAPI_CORE_PATH = (Resolve-Path -LiteralPath $CorePath).Path
$phpArgs = @()
if ($ExtensionDir) { $phpArgs = @('-d', "extension_dir=$ExtensionDir", '-d', 'extension=openssl') }
foreach ($test in @('core.php', 'runtime.php', 'connections.php', 'workflow.php', 'dependency.php')) {
    & $Php @phpArgs (Join-Path $PSScriptRoot $test)
    if ($LASTEXITCODE -ne 0) { throw "Failed: $test" }
}
& $Php @phpArgs (Join-Path $PSScriptRoot 'dependency.php') incompatible
if ($LASTEXITCODE -ne 0) { throw 'Failed: incompatible Core' }
$files = @(Get-ChildItem (Join-Path $PSScriptRoot '..\freedomapi-ai') -Recurse -Filter '*.php')
$files += @('modules/helpers.php','modules/helpers/extensions.php','modules/helpers/endpoint-schema.php','modules/helpers/gateway.php','modules/helpers/openapi.php','modules/frontend/classes/class-edit-api.php') | ForEach-Object { Get-Item (Join-Path $CorePath $_) }
foreach ($file in $files) {
    & $Php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { throw "Syntax error: $($file.FullName)" }
}
node --check (Join-Path $PSScriptRoot '..\freedomapi-ai\assets\editor.js')
if ($LASTEXITCODE -ne 0) { throw 'JavaScript syntax error' }
Write-Output 'All automated checks passed.'
