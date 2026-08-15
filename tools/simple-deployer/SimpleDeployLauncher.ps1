<#
.SYNOPSIS
    SimpleDeploy Launcher — a small Windows GUI front-end for the
    existing SimpleDeploy tool (tools/simple-deployer/lib/*.ps1). Double-
    click SimpleDeployLauncher.bat (or the Desktop shortcut installed by
    Install-Shortcut.ps1) — no PowerShell console, no typed commands.

.DESCRIPTION
    This file only wires together the launcher/*.ps1 modules and starts
    the WinForms window; it contains no Git/Build/Transfer/Activation
    logic of its own — everything is the same lib/*.ps1 code the
    SimpleDeploy.ps1 CLI already uses.

.PARAMETER ConfigPath
    Optional override for config.json's location. Defaults to
    tools/simple-deployer/config.json (the real, gitignored config).
#>

[CmdletBinding()]
param([Parameter(Mandatory = $false)][string]$ConfigPath)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptRoot = $PSScriptRoot

# Wrapped in try/catch, top to bottom, deliberately: the .bat launcher
# runs this with -WindowStyle Hidden (no console window at all), so an
# unhandled exception ANYWHERE here — even a dot-sourcing/parse failure
# in one of the library files, before any of the Launcher's own error
# handling exists yet — would otherwise fail completely silently, with
# the user seeing nothing happen at all when they double-click the
# shortcut. Add-Type + MessageBox needs no other file in this project to
# already be loaded, so it is safe to use even if the very first
# dot-source below is what failed.
try {
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Common.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Config.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.ServiceControl.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Preflight.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Git.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Build.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.ArtifactValidation.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Transfer.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Timing.ps1')
    . (Join-Path $ScriptRoot 'lib\SimpleDeploy.Orchestration.ps1')
    . (Join-Path $ScriptRoot 'launcher\SimpleDeployLauncher.Status.ps1')
    . (Join-Path $ScriptRoot 'launcher\SimpleDeployLauncher.GitFlow.ps1')
    . (Join-Path $ScriptRoot 'launcher\SimpleDeployLauncher.Log.ps1')
    . (Join-Path $ScriptRoot 'launcher\SimpleDeployLauncher.Engine.ps1')
    . (Join-Path $ScriptRoot 'launcher\SimpleDeployLauncher.Gui.ps1')

    Show-SimpleDeployLauncher -ToolRoot $ScriptRoot -ConfigPath $ConfigPath
} catch {
    try {
        Add-Type -AssemblyName System.Windows.Forms -ErrorAction Stop
        [void][System.Windows.Forms.MessageBox]::Show(
            "SimpleDeploy Launcher در هنگام راه‌اندازی با خطا مواجه شد:`n`n$($_.Exception.Message)",
            'SimpleDeploy — خطای راه‌اندازی',
            [System.Windows.Forms.MessageBoxButtons]::OK,
            [System.Windows.Forms.MessageBoxIcon]::Error
        )
    } catch {
        # Even WinForms itself failed to load — last resort, write to a
        # file next to the script so double-clicking silently doing
        # nothing at least leaves evidence behind.
        $crashLogPath = Join-Path $ScriptRoot 'launcher-startup-error.log'
        "$(Get-Date -Format 'o')  $($_.Exception.Message)" | Add-Content -LiteralPath $crashLogPath -Encoding UTF8
    }
    exit 1
}
