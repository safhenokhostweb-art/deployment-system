<#
.SYNOPSIS
    Runs every Test-*.ps1 in this directory as a genuinely separate
    subprocess (matching how the real tool itself is always invoked —
    `powershell.exe -File`, a fresh process each time) and aggregates
    pass/fail counts. No testing framework — plain scripts, real
    fixtures, matching this project's own PHP test-suite runners
    (local-agent/tests/run*.php).
#>

try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$testFiles = @(
    'Test-Config.ps1',
    'Test-Git.ps1',
    'Test-Transfer.ps1',
    'Test-Build.ps1',
    'Test-ArtifactValidation.ps1',
    'Test-ServiceControl.ps1',
    'Test-Preflight.ps1',
    'Test-ActivateRelease.ps1',
    'Test-Orchestration.ps1',
    'Test-DeploymentState.ps1',
    'Test-LauncherGitFlow.ps1',
    'Test-LauncherEngine.ps1',
    'Test-LauncherIntegration.ps1',
    'Test-LauncherGuiFlow.ps1'
)

$overallPass = 0
$overallFail = 0
$failedSuites = New-Object System.Collections.Generic.List[string]

foreach ($testFile in $testFiles) {
    $path = Join-Path $ScriptDir $testFile
    Write-Host ''
    Write-Host "========================================================" -ForegroundColor DarkCyan
    Write-Host " $testFile" -ForegroundColor DarkCyan
    Write-Host "========================================================" -ForegroundColor DarkCyan

    $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $path 2>&1
    $exitCode = $LASTEXITCODE
    $output | ForEach-Object { Write-Host $_ }

    $summaryLine = $output | Where-Object { $_ -match '^\[.+\]\s+\d+ passed,\s+\d+ failed\.$' } | Select-Object -Last 1
    if ($summaryLine -and ($summaryLine -match '(\d+) passed,\s+(\d+) failed')) {
        $overallPass += [int]$Matches[1]
        $overallFail += [int]$Matches[2]
        if ([int]$Matches[2] -gt 0) { $failedSuites.Add($testFile) }
    } elseif ($exitCode -ne 0) {
        # The suite crashed before printing its own summary line at all.
        $overallFail += 1
        $failedSuites.Add("$testFile (crashed — exit $exitCode, no summary line)")
    }
}

Write-Host ''
Write-Host "========================================================" -ForegroundColor DarkCyan
Write-Host " خلاصه کلی" -ForegroundColor DarkCyan
Write-Host "========================================================" -ForegroundColor DarkCyan
$color = if ($overallFail -eq 0) { 'Green' } else { 'Red' }
Write-Host "TOTAL: $overallPass passed, $overallFail failed (across $($testFiles.Count) suites)." -ForegroundColor $color
if ($failedSuites.Count -gt 0) {
    Write-Host 'Suites with failures:' -ForegroundColor Red
    foreach ($s in $failedSuites) { Write-Host "  - $s" -ForegroundColor Red }
}

if ($overallFail -gt 0) { exit 1 }
exit 0
