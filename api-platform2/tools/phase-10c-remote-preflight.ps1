[CmdletBinding()]
param(
    [ValidateSet('ReadOnly', 'Mutation')]
    [string] $Mode = 'ReadOnly',
    [switch] $ConfirmDevelopmentTarget,
    [switch] $SkipGateway
)

$ErrorActionPreference = 'Stop'

function Read-LocalConfig {
    param([string] $Path)
    $values = @{}
    if (-not (Test-Path -LiteralPath $Path)) { return $values }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
        if ($trimmed -notmatch '^([A-Z0-9_]+)=(.*)$') {
            throw "Invalid configuration line in $Path. Use KEY=value only."
        }
        $values[$Matches[1]] = $Matches[2].Trim()
    }
    return $values
}

function Get-Setting {
    param([string] $Name, [hashtable] $Config)
    $processValue = [Environment]::GetEnvironmentVariable($Name, 'Process')
    if (-not [string]::IsNullOrWhiteSpace($processValue)) { return $processValue.Trim() }
    if ($Config.ContainsKey($Name)) { return $Config[$Name] }
    return ''
}

function Mask-Value {
    param([string] $Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return '(not set)' }
    if ($Value.Length -le 2) { return '**' }
    return $Value.Substring(0, 1) + ('*' * [Math]::Min(10, $Value.Length - 2)) + $Value.Substring($Value.Length - 1, 1)
}

function Mask-Url {
    param([string] $Value)
    try {
        $uri = [uri]$Value
        return "$($uri.Scheme)://$(Mask-Value $uri.Host)"
    } catch {
        return '(invalid URL)'
    }
}

function Invoke-SafeGet {
    param([string] $Label, [string] $Url)
    try {
        $response = Invoke-WebRequest -Uri $Url -Method Get -MaximumRedirection 3 -TimeoutSec 20 -UseBasicParsing
        return [pscustomobject]@{ Test = $Label; Url = (Mask-Url $Url); HttpStatus = [int]$response.StatusCode; Result = 'PASS'; Detail = 'Reachable GET completed.' }
    } catch {
        $status = 0
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        $result = if ($status -ge 400 -and $status -lt 500) { 'PASS' } else { 'FAIL' }
        $detail = if ($result -eq 'PASS') { 'Reachable controlled HTTP response.' } else { 'GET failed; inspect the target locally without exposing response bodies or credentials.' }
        return [pscustomobject]@{ Test = $Label; Url = (Mask-Url $Url); HttpStatus = $status; Result = $result; Detail = $detail }
    }
}

function Test-FreedomApiNamespace {
    param([string] $Url)
    try {
        $response = Invoke-WebRequest -Uri $Url -Method Get -MaximumRedirection 3 -TimeoutSec 20 -UseBasicParsing
        $found = $response.Content -match 'platform/v1'
        return [pscustomobject]@{ Test = 'FreedomAPI REST namespace discovery'; Url = (Mask-Url $Url); HttpStatus = [int]$response.StatusCode; Result = $(if ($found) { 'PASS' } else { 'FAIL' }); Detail = $(if ($found) { 'Namespace listed in WordPress REST index.' } else { 'WordPress REST index did not list the FreedomAPI namespace.' }) }
    } catch {
        $status = 0
        if ($_.Exception.Response) { $status = [int]$_.Exception.Response.StatusCode }
        return [pscustomobject]@{ Test = 'FreedomAPI REST namespace discovery'; Url = (Mask-Url $Url); HttpStatus = $status; Result = 'FAIL'; Detail = 'REST index request failed.' }
    }
}

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$config = Read-LocalConfig (Join-Path $scriptRoot 'phase-10c-runtime.env')
$target = (Get-Setting 'WORDPRESS_TEST_TARGET' $config).ToLowerInvariant()
$baseUrl = Get-Setting 'WORDPRESS_TEST_URL' $config
$user = Get-Setting 'WORDPRESS_TEST_USER' $config
$account = Get-Setting 'WORDPRESS_TEST_ACCOUNT' $config

if ($target -notin @('development', 'test', 'staging', 'production')) {
    throw 'WORDPRESS_TEST_TARGET must be development, test, staging, or production.'
}
if ([string]::IsNullOrWhiteSpace($baseUrl) -or -not [uri]::IsWellFormedUriString($baseUrl, [UriKind]::Absolute)) {
    throw 'WORDPRESS_TEST_URL must be an absolute URL in the local configuration or process environment.'
}

Write-Host 'Phase 10C remote certification preflight'
Write-Host "Target: $target"
Write-Host "URL: $(Mask-Url $baseUrl)"
Write-Host "User: $(Mask-Value $user)"
Write-Host "Account: $(Mask-Value $account)"
Write-Host "Mode: $Mode"

if ($Mode -eq 'Mutation') {
    $dedicated = Get-Setting 'WORDPRESS_TEST_CONFIRM_DEDICATED' $config
    if ($target -notin @('development', 'test') -or -not $ConfirmDevelopmentTarget -or $dedicated -ne 'YES') {
        throw 'Mutation mode is refused. It requires a development/test target, -ConfirmDevelopmentTarget, and local WORDPRESS_TEST_CONFIRM_DEDICATED=YES.'
    }
    Write-Host 'Mutation authorization confirmed. This preflight intentionally sends no mutation requests.'
}

$baseUrl = $baseUrl.TrimEnd('/')
$restIndex = "$baseUrl/wp-json/"
$results = @(
    Invoke-SafeGet 'WordPress REST index' $restIndex
    Test-FreedomApiNamespace $restIndex
)

if (-not $SkipGateway) {
    foreach ($setting in @('WORDPRESS_TEST_PERSONAL_GATEWAY_PATH', 'WORDPRESS_TEST_ORGANIZATION_GATEWAY_PATH', 'WORDPRESS_TEST_PORTAL_PATH')) {
        $path = Get-Setting $setting $config
        if (-not [string]::IsNullOrWhiteSpace($path)) {
            if (-not $path.StartsWith('/')) { throw "$setting must start with /." }
            $results += Invoke-SafeGet $setting "$baseUrl$path"
        }
    }
}

$results | Format-Table -AutoSize
$failed = @($results | Where-Object { $_.Result -eq 'FAIL' }).Count
if ($failed -gt 0) { exit 1 }