<#
.SYNOPSIS
    The WinForms GUI itself. Reads projects only from config.json (via
    Get-EnabledProjects — nothing here hardcodes a project name), builds
    one row per project, and wires each button to the Engine/GitFlow
    functions defined elsewhere in launcher/*.ps1 — this file contains NO
    Git/Build/Transfer/Activation logic of its own, only UI plumbing.
#>

Set-StrictMode -Version Latest

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

# Must run before ANY WinForms Control is created on this thread (.NET
# throws "Thread exception mode cannot be changed once any Controls are
# created on the thread" otherwise) -- this file-level dot-source point,
# immediately after EnableVisualStyles() and before any function here
# has been invoked, is the earliest safe place. Pairs with the
# Application.ThreadException handler registered inside
# Show-SimpleDeployLauncher itself (registering the event handler has no
# such early-only restriction, only this mode switch does) -- together
# they are the last-resort safety net so no exception raised on the UI
# thread can ever surface as WinForms' own unhandled-exception crash
# dialog; every call site that can realistically throw already has its
# own try/catch, this only catches what those miss.
[System.Windows.Forms.Application]::SetUnhandledExceptionMode([System.Windows.Forms.UnhandledExceptionMode]::CatchException)

$script:LauncherFontFamily = 'Tahoma'

function New-LauncherInputDialog {
    <#
    .SYNOPSIS
        A tiny modal Form asking for one line of text (WinForms has no
        built-in InputBox). Returns the entered string, or $null if the
        user cancelled.
    #>
    param(
        [Parameter(Mandatory = $true)][string]$Prompt,
        [Parameter(Mandatory = $false)][string]$DefaultValue = '',
        [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy'
    )

    $dlg = New-Object System.Windows.Forms.Form
    $dlg.Text = $Title
    $dlg.RightToLeft = 'Yes'
    $dlg.RightToLeftLayout = $true
    $dlg.Font = New-Object System.Drawing.Font($script:LauncherFontFamily, 10)
    $dlg.FormBorderStyle = 'FixedDialog'
    $dlg.StartPosition = 'CenterParent'
    $dlg.MinimizeBox = $false
    $dlg.MaximizeBox = $false
    $dlg.ClientSize = New-Object System.Drawing.Size(420, 140)

    $lbl = New-Object System.Windows.Forms.Label
    $lbl.Text = $Prompt
    $lbl.SetBounds(12, 12, 396, 40)
    $dlg.Controls.Add($lbl)

    $txt = New-Object System.Windows.Forms.TextBox
    $txt.SetBounds(12, 55, 396, 24)
    $txt.Text = $DefaultValue
    $dlg.Controls.Add($txt)

    $okBtn = New-Object System.Windows.Forms.Button
    $okBtn.Text = 'تأیید'
    $okBtn.SetBounds(228, 95, 90, 30)
    $okBtn.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $dlg.Controls.Add($okBtn)

    $cancelBtn = New-Object System.Windows.Forms.Button
    $cancelBtn.Text = 'انصراف'
    $cancelBtn.SetBounds(318, 95, 90, 30)
    $cancelBtn.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    $dlg.Controls.Add($cancelBtn)

    $dlg.AcceptButton = $okBtn
    $dlg.CancelButton = $cancelBtn

    $result = $dlg.ShowDialog()
    if ($result -eq [System.Windows.Forms.DialogResult]::OK) {
        return $txt.Text
    }
    return $null
}

function Show-LauncherConfirm {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
    $result = [System.Windows.Forms.MessageBox]::Show($Text, $Title, [System.Windows.Forms.MessageBoxButtons]::YesNo, [System.Windows.Forms.MessageBoxIcon]::Question)
    return ($result -eq [System.Windows.Forms.DialogResult]::Yes)
}

function Show-LauncherInfo {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
    [void][System.Windows.Forms.MessageBox]::Show($Text, $Title, [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Information)
}

function Show-LauncherError {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
    [void][System.Windows.Forms.MessageBox]::Show($Text, $Title, [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Error)
}

function Get-LauncherStatusColor {
    param([Parameter(Mandatory = $true)][string]$ColorName)
    switch ($ColorName) {
        'Gray'   { return [System.Drawing.Color]::DimGray }
        'Blue'   { return [System.Drawing.Color]::RoyalBlue }
        'Green'  { return [System.Drawing.Color]::ForestGreen }
        'Red'    { return [System.Drawing.Color]::Firebrick }
        'Yellow' { return [System.Drawing.Color]::DarkGoldenrod }
        default  { return [System.Drawing.Color]::Black }
    }
}

<#
.SYNOPSIS
    Builds and runs the SimpleDeploy Launcher main window. Blocks
    (Application.Run) until the window is closed. Acquires the SAME
    singleton lock the SimpleDeploy.ps1 CLI uses (Enter-SimpleDeployLock,
    Global\SimpleDeployerSingleInstance) so the two entry points can never
    run a deploy at the same time either.

.NOTES
    Every per-window helper below (Append-Log, Set-RowStatus,
    Invoke-GitOnlyAction, ...) is a SCRIPTBLOCK-VALUED LOCAL VARIABLE
    ($Name = { ... }), never a nested `function Name { ... }` statement.
    This is a deliberate, empirically-forced choice, not a style
    preference: `.Add_Click({ ... }.GetNewClosure())` is required so a
    button's handler correctly captures its own per-row $row/$Row
    variable — but .GetNewClosure() detaches the scriptblock into a new,
    independent session state that copies only the VARIABLE table from
    the enclosing scope, never the FUNCTION table. A nested function
    defined via `function Name { ... }` inside THIS function's body lives
    only in this function's own local Function: scope, which a detached
    closure can never see — calling it later (e.g. on a real button
    click, dispatched through Application.Run's message loop) fails with
    "The term 'Name' is not recognized...". A plain scriptblock-valued
    VARIABLE does not have this problem: GetNewClosure() captures it like
    any other variable, and PowerShell scriptblocks inherently carry a
    reference to the lexical scope they were written in, so one
    scriptblock variable calling another via `& $Other` (neither one
    itself needing its own GetNewClosure()) resolves correctly as long as
    the top-level handler that started the chain was captured. Top-level,
    non-nested functions (Show-LauncherError, Get-DeploymentServiceDisplayStatus,
    Invoke-LauncherGitOnlyFlow, ...) are unaffected by any of this — they
    live in the session's global function table, which IS visible from a
    detached closure; only THIS function's own nested nested-function
    pattern needed to change. Confirmed empirically against this exact
    PowerShell 5.1 host before applying.
#>
function Show-SimpleDeployLauncher {
    param(
        [Parameter(Mandatory = $true)][string]$ToolRoot,
        [Parameter(Mandatory = $false)][string]$ConfigPath,
        # Test-only hook, invoked with ($form, $GuiState) right before
        # Application.Run($form) -- $null (the default) is the exact
        # pre-existing behavior for the real .bat/shortcut launch path
        # (SimpleDeployLauncher.ps1 never passes this). Exists so a real
        # test can drive the REAL form/engine through Application.Run +
        # PerformClick (its own Timer set up inside the hook clicks a
        # button, waits for completion, then closes the form) instead of
        # re-implementing a second, parallel GUI just for testing.
        [Parameter(Mandatory = $false)][scriptblock]$TestAutomationHook = $null
    )

    if (-not $ConfigPath) { $ConfigPath = Join-Path $ToolRoot 'config.json' }
    $stateDir = Join-Path $ToolRoot 'state'
    $logsRootDir = Join-Path $ToolRoot 'logs'

    $lockMutex = Enter-SimpleDeployLock
    if ($null -eq $lockMutex) {
        Show-LauncherError -Text 'یک نمونه دیگر از SimpleDeploy (رابط گرافیکی یا خط فرمان) در حال اجراست. برای جلوگیری از تداخل، این اجرا متوقف می‌شود.'
        return
    }

    # Pays the ~400-900ms Runspace-creation/library-dot-source cost now,
    # on a background operation, while the window is first appearing --
    # see the .NOTES on New-LauncherRunspace in Engine.ps1 for why this
    # used to happen on the UI thread on every click instead.
    Start-LauncherRunspaceWarmup -ToolRoot $ToolRoot

    try {
        # ============================================================
        # Main window skeleton
        # ============================================================
        $form = New-Object System.Windows.Forms.Form
        $form.Text = 'SimpleDeploy'
        $form.RightToLeft = 'Yes'
        $form.RightToLeftLayout = $true
        $form.Font = New-Object System.Drawing.Font($script:LauncherFontFamily, 9)
        $form.MinimumSize = New-Object System.Drawing.Size(760, 560)
        $form.ClientSize = New-Object System.Drawing.Size(900, 720)
        $form.StartPosition = 'CenterScreen'

        $topPanel = New-Object System.Windows.Forms.Panel
        $topPanel.Dock = 'Top'
        $topPanel.Height = 100
        $form.Controls.Add($topPanel)

        $titleLabel = New-Object System.Windows.Forms.Label
        $titleLabel.Text = 'SimpleDeploy'
        $titleLabel.Font = New-Object System.Drawing.Font($script:LauncherFontFamily, 18, [System.Drawing.FontStyle]::Bold)
        $titleLabel.SetBounds(12, 8, 400, 34)
        $topPanel.Controls.Add($titleLabel)

        $subtitleLabel = New-Object System.Windows.Forms.Label
        $subtitleLabel.Text = 'ارسال تغییرات لوکال به GitHub و سرور'
        $subtitleLabel.Font = New-Object System.Drawing.Font($script:LauncherFontFamily, 10)
        $subtitleLabel.ForeColor = [System.Drawing.Color]::DimGray
        $subtitleLabel.SetBounds(12, 44, 400, 22)
        $topPanel.Controls.Add($subtitleLabel)

        $serviceStatusLabel = New-Object System.Windows.Forms.Label
        $serviceStatusLabel.Text = 'Deployment Service: —'
        $serviceStatusLabel.SetBounds(12, 70, 350, 22)
        $topPanel.Controls.Add($serviceStatusLabel)

        $checkAllProgressLabel = New-Object System.Windows.Forms.Label
        $checkAllProgressLabel.Text = ''
        $checkAllProgressLabel.ForeColor = [System.Drawing.Color]::SteelBlue
        $checkAllProgressLabel.SetBounds(370, 70, 500, 22)
        $topPanel.Controls.Add($checkAllProgressLabel)

        $checkAllButton = New-Object System.Windows.Forms.Button
        $checkAllButton.Text = 'بررسی همه پروژه‌ها'
        $checkAllButton.SetBounds(430, 10, 160, 30)
        $topPanel.Controls.Add($checkAllButton)

        $reloadButton = New-Object System.Windows.Forms.Button
        $reloadButton.Text = 'بازخوانی پروژه‌ها'
        $reloadButton.SetBounds(600, 10, 150, 30)
        $topPanel.Controls.Add($reloadButton)

        $cancelButton = New-Object System.Windows.Forms.Button
        $cancelButton.Text = 'لغو عملیات جاری'
        $cancelButton.SetBounds(760, 10, 130, 30)
        $cancelButton.Enabled = $false
        $topPanel.Controls.Add($cancelButton)

        $bottomPanel = New-Object System.Windows.Forms.Panel
        $bottomPanel.Dock = 'Bottom'
        $bottomPanel.Height = 220
        $form.Controls.Add($bottomPanel)

        $logLabel = New-Object System.Windows.Forms.Label
        $logLabel.Text = 'گزارش زنده:'
        $logLabel.Dock = 'Top'
        $logLabel.Height = 20
        $bottomPanel.Controls.Add($logLabel)

        $logBox = New-Object System.Windows.Forms.RichTextBox
        $logBox.Dock = 'Fill'
        $logBox.ReadOnly = $true
        $logBox.BackColor = [System.Drawing.Color]::White
        $logBox.Font = New-Object System.Drawing.Font('Consolas', 9)
        $bottomPanel.Controls.Add($logBox)
        $logBox.BringToFront()

        $projectsPanel = New-Object System.Windows.Forms.FlowLayoutPanel
        $projectsPanel.Dock = 'Fill'
        $projectsPanel.FlowDirection = 'TopDown'
        $projectsPanel.WrapContents = $false
        $projectsPanel.AutoScroll = $true
        $form.Controls.Add($projectsPanel)
        $projectsPanel.BringToFront()

        # ============================================================
        # Shared mutable state -- ONE Hashtable (a reference type), never
        # $script:-prefixed scalars. This is not a style choice: every
        # button/timer handler below is registered via
        # `.Add_Click({...}.GetNewClosure())`, and EACH separate
        # .GetNewClosure() call detaches its scriptblock into its OWN,
        # independent session state -- which turns out to include its own
        # independent SCRIPT scope, not just its own function table (the
        # function-table half of this was already known; the script-scope
        # half was discovered while diagnosing the "بررسی همه پروژه‌ها"
        # freeze). Concretely: `$GuiState.CurrentHandle = ...` written
        # inside checkButton's closure is INVISIBLE to pollTimer's own,
        # separately-created closure -- pollTimer would keep reading its
        # own copy's original $null forever and never detect ANY operation
        # as started, regardless of how fast the underlying git command
        # runs. Confirmed empirically against this exact PowerShell 5.1
        # host before applying (see repro-script-scope-closure.ps1 /
        # repro-hashtable-crossclosure.ps1 in the accompanying diagnosis).
        # A Hashtable sidesteps this entirely: GetNewClosure() DOES
        # correctly copy a LOCAL variable's value into each closure's
        # table (proven all along by $row/$logBox/$cancelButton working),
        # and for a reference type that copied value is a pointer to the
        # SAME underlying object -- so `$GuiState.CurrentHandle = ...`
        # mutates the one real object every closure points at, regardless
        # of which closure's detached scope is currently executing.
        # ============================================================
        $GuiState = @{
            GuiConfig               = $null
            GuiProjects              = @()
            GuiRows                  = @{}
            CurrentHandle            = $null
            CurrentOnOutput          = $null
            CurrentOnComplete        = $null
            CurrentRunLog            = $null
            CurrentProjectKey        = $null
            PausedByThisRun          = $false
            PausedTaskNamesThisRun   = @()
            # "بررسی همه پروژه‌ها" batch state: a non-blocking queue
            # advanced one project at a time by the SAME pollTimer tick
            # that already detects single-operation completion -- never a
            # UI-thread wait loop. See the .NOTES on $AdvanceCheckAll
            # below for why the previous DoEvents()-based wait loop caused
            # the reported freeze.
            CheckAllQueue            = $null
            CheckAllTotal            = 0
            CheckAllCancelled        = $false
            PollTickBusy             = $false
        }

        # ============================================================
        # Per-window helpers — scriptblock-valued LOCAL variables, not
        # nested `function` statements. See the .NOTES on this function
        # for why (a nested function is unreachable from a
        # GetNewClosure()'d event handler; a scriptblock variable is not).
        # ============================================================

        $AppendLog = {
            param([string]$Text, [string]$ColorName = 'Gray')
            $logBox.SelectionStart = $logBox.TextLength
            $logBox.SelectionLength = 0
            $logBox.SelectionColor = Get-LauncherStatusColor -ColorName $ColorName
            $logBox.AppendText("$Text`r`n")
            $logBox.ScrollToCaret()
        }

        $UpdateServiceStatusLabel = {
            if (-not $GuiState.GuiConfig) { return }
            $info = Get-DeploymentServiceDisplayStatus -DeploymentServiceCfg $GuiState.GuiConfig.deployment_service -PausedByThisRun $GuiState.PausedByThisRun
            $serviceStatusLabel.Text = "Deployment Service: $($info.Text)"
            $serviceStatusLabel.ForeColor = Get-LauncherStatusColor -ColorName $info.Color
        }

        $SetRowStatus = {
            param([string]$ProjectKey, [string]$StatusKey, [string]$ExtraText = $null)
            if (-not $GuiState.GuiRows.ContainsKey($ProjectKey)) { return }
            $row = $GuiState.GuiRows[$ProjectKey]
            $info = Get-LauncherStatusInfo -StatusKey $StatusKey
            $text = $info.Text
            if ($ExtraText) { $text = "$text — $ExtraText" }
            $row.StatusValueLabel.Text = $text
            $row.StatusValueLabel.ForeColor = Get-LauncherStatusColor -ColorName $info.Color
        }

        $SetRowsEnabled = {
            param([bool]$Enabled)
            foreach ($key in $GuiState.GuiRows.Keys) {
                $row = $GuiState.GuiRows[$key]
                $row.CheckButton.Enabled = $Enabled
                $row.GitButton.Enabled = $Enabled
                $row.DeployButton.Enabled = $Enabled -and $row.ProjectInfo.IsDeployable
            }
            $checkAllButton.Enabled = $Enabled
            $reloadButton.Enabled = $Enabled
        }

        <#
        .SYNOPSIS
            Kicks off a background operation and wires the polling Timer
            to route its output/completion. $OnComplete receives the
            Complete-LauncherOperation hashtable.
        #>
        $StartTracked = {
            param(
                [scriptblock]$Operation,
                [hashtable]$Variables = @{},
                [string]$ProjectKey,
                [scriptblock]$OnOutput,
                [scriptblock]$OnComplete
            )
            $GuiState.CurrentProjectKey = $ProjectKey
            Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'RUNSPACE STARTED' -Detail "project=$ProjectKey"
            $GuiState.CurrentHandle = Start-LauncherOperation -ToolRoot $ToolRoot -Operation $Operation -Variables $Variables
            $GuiState.CurrentOnOutput = $OnOutput
            $GuiState.CurrentOnComplete = $OnComplete
            $cancelButton.Enabled = $true
            & $SetRowsEnabled -Enabled $false
        }

        <#
        .SYNOPSIS
            Advances the "بررسی همه پروژه‌ها" batch by exactly one project,
            then returns immediately -- never waits or loops. Called once
            when the batch starts ($InvokeCheckAllAction) and once more
            from pollTimer's own completion branch after EVERY single
            check finishes, so the whole batch is driven entirely by the
            polling Timer that already exists, with no UI-thread wait loop
            of any kind. The previous Application.DoEvents() +
            Start-Sleep busy-wait loop here was the root cause of the
            reported Refresh/Check freeze -- nested DoEvents() re-entering
            the WinForms message loop from inside a Click handler is
            unsafe and was stalling the UI even though each individual
            project's git check itself completed quickly.
        #>
        $AdvanceCheckAll = {
            if (-not $GuiState.CheckAllQueue) { return }
            if ($GuiState.CheckAllCancelled -or $GuiState.CheckAllQueue.Count -eq 0) {
                $wasCancelled = $GuiState.CheckAllCancelled
                $GuiState.CheckAllQueue = $null
                $GuiState.CheckAllCancelled = $false
                $checkAllProgressLabel.Text = ''
                & $AppendLog -Text $(if ($wasCancelled) { 'بررسی همه پروژه‌ها لغو شد.' } else { 'بررسی همه پروژه‌ها کامل شد.' }) -ColorName 'Gray'
                return
            }
            $nextKey = $GuiState.CheckAllQueue.Dequeue()
            $doneCount = $GuiState.CheckAllTotal - $GuiState.CheckAllQueue.Count
            $checkAllProgressLabel.Text = "بررسی همه: $doneCount از $($GuiState.CheckAllTotal) ($nextKey)"
            & $InvokeCheckAction -Row $GuiState.GuiRows[$nextKey]
        }

        $pollTimer = New-Object System.Windows.Forms.Timer
        $pollTimer.Interval = 150
        $pollTimer.Add_Tick({
            # Reentrancy guard. Show-LauncherConfirm/New-LauncherInputDialog
            # (called from deep inside an -OnComplete callback, e.g. the
            # Git-only and Deploy flows) block via MessageBox.Show()/
            # ShowDialog() -- but Windows' modal message pump STILL
            # services this SAME thread's WM_TIMER messages while blocked,
            # so pollTimer's own Tick can fire again, re-entrantly, nested
            # inside its own still-running (paused) invocation.
            # PowerShell scriptblock invocation is not safe against that
            # kind of true reentrancy: a nested invocation corrupts the
            # outer, still-paused invocation's own parameter/local scope.
            # Confirmed empirically -- this produced exactly "The variable
            # '$key' cannot be retrieved because it has not been set."
            # (thrown from inside the OUTER invocation once the nested one
            # returns and it resumes) and, separately, "Invoke-
            # LauncherDeployFlow is not recognized" style symptoms reported
            # for the Deploy flow; Git-only has the identical exposure,
            # just less likely to be hit depending on click timing.
            # Skipping any re-entrant tick outright is correct and
            # lossless: the SAME 150ms timer ticks again right after the
            # modal dialog closes and the outer invocation finishes, so no
            # output/completion is ever missed, only delayed by at most
            # one interval.
            if ($GuiState.PollTickBusy) { return }
            $GuiState.PollTickBusy = $true
            try {
                if (-not $GuiState.CurrentHandle) { return }
                $items = Receive-LauncherOperationOutput -Handle $GuiState.CurrentHandle
                foreach ($item in $items) {
                    if ($GuiState.CurrentOnOutput) {
                        try {
                            & $GuiState.CurrentOnOutput $item
                        } catch {
                            Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'ONOUTPUT ERROR' -Detail "project=$($GuiState.CurrentProjectKey) error=$($_.Exception.Message)"
                        }
                    }
                    $color = switch ($item.Type) {
                        'Error'   { 'Red' }
                        'Warn'    { 'Yellow' }
                        'Success' { 'Green' }
                        'Step'    { 'Blue' }
                        default   { 'Gray' }
                    }
                    & $AppendLog -Text $item.Text -ColorName $color
                }
                if (Test-LauncherOperationComplete -Handle $GuiState.CurrentHandle) {
                    Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'RUNSPACE COMPLETED' -Detail "project=$($GuiState.CurrentProjectKey)"
                    $completion = Complete-LauncherOperation -Handle $GuiState.CurrentHandle -ToolRoot $ToolRoot
                    $handledProjectKey = $GuiState.CurrentProjectKey
                    $onComplete = $GuiState.CurrentOnComplete
                    $GuiState.CurrentHandle = $null
                    $GuiState.CurrentOnOutput = $null
                    $GuiState.CurrentOnComplete = $null
                    $GuiState.CurrentProjectKey = $null
                    $cancelButton.Enabled = $false
                    & $SetRowsEnabled -Enabled $true
                    Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'GUI STATE RELEASED' -Detail "project=$handledProjectKey"
                    if ($onComplete) {
                        # Genuinely STOP the Timer (not just the
                        # PollTickBusy flag above) for the duration of the
                        # callback. Show-LauncherConfirm/New-LauncherInputDialog
                        # inside the Git-only/Deploy -OnComplete blocks show
                        # a real modal MessageBox.Show()/ShowDialog(), and
                        # Windows' modal message pump keeps dispatching
                        # this SAME thread's WM_TIMER while blocked. The
                        # PollTickBusy flag alone was NOT sufficient --
                        # confirmed empirically that a truly re-entrant
                        # invocation of this SAME closure instance can
                        # still corrupt the outer, paused invocation's own
                        # scope (that produced "The variable '$key' cannot
                        # be retrieved...") even with the flag set,
                        # because the corruption isn't limited to skipping
                        # logic -- it's PowerShell's scriptblock execution
                        # itself not being safe against true reentrancy.
                        # Stopping the Timer at the WinForms/OS level
                        # prevents WM_TIMER from being raised for
                        # pollTimer at all during this window, so this
                        # exact Tick handler can never be entered a second
                        # time nested inside itself -- not "handled
                        # gracefully if it happens", genuinely prevented.
                        $pollTimer.Stop()
                        try {
                            Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'ONCOMPLETE CALLED' -Detail "project=$handledProjectKey"
                            try {
                                & $onComplete $completion $handledProjectKey
                            } catch {
                                Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'ONCOMPLETE ERROR' -Detail "project=$handledProjectKey error=$($_.Exception.Message)"
                                Show-LauncherError -Text "پردازش نتیجه عملیات برای '$handledProjectKey' با خطای غیرمنتظره مواجه شد:`n$($_.Exception.Message)"
                            }
                        } finally {
                            $pollTimer.Start()
                        }
                    }
                    if ($GuiState.CheckAllQueue) { & $AdvanceCheckAll }
                }
            } finally {
                $GuiState.PollTickBusy = $false
            }
        }.GetNewClosure())
        $pollTimer.Start()

        $cancelButton.Add_Click({
            if ($GuiState.CurrentHandle) {
                $confirmText = if ($GuiState.CheckAllQueue) {
                    'آیا بررسی همه پروژه‌ها لغو شود؟ پروژه‌ای که همین الان در حال بررسی است تا پایان طبیعی خودش ادامه می‌یابد؛ پروژه‌های باقی‌مانده در صف بررسی نخواهند شد.'
                } else {
                    'آیا عملیات جاری لغو شود؟ عملیات Git/شبکه‌ای که همین الان در حال اجراست تا پایان طبیعی خودش ادامه می‌یابد و فقط مرحله بعدی شروع نمی‌شود.'
                }
                if (Show-LauncherConfirm -Text $confirmText) {
                    if ($GuiState.CheckAllQueue) {
                        $GuiState.CheckAllCancelled = $true
                        $GuiState.CheckAllQueue.Clear()
                    }
                    Stop-LauncherOperation -Handle $GuiState.CurrentHandle
                    & $AppendLog -Text 'درخواست لغو ارسال شد...' -ColorName 'Yellow'
                }
            }
        }.GetNewClosure())

        # ============================================================
        # Project row construction
        # ============================================================
        $NewProjectRow = {
            param($ProjectInfo, $InvokeCheckAction, $InvokeGitOnlyAction, $InvokeDeployAction, $InvokeViewLogAction)

            $cfg = $ProjectInfo.Config
            $card = New-Object System.Windows.Forms.Panel
            $card.Width = 850
            $card.Height = 150
            $card.BorderStyle = 'FixedSingle'
            $card.Margin = New-Object System.Windows.Forms.Padding(4)

            $nameLabel = New-Object System.Windows.Forms.Label
            $nameLabel.Text = $ProjectInfo.Key
            $nameLabel.Font = New-Object System.Drawing.Font($script:LauncherFontFamily, 12, [System.Drawing.FontStyle]::Bold)
            $nameLabel.SetBounds(10, 8, 500, 26)
            $card.Controls.Add($nameLabel)

            $pathLabel = New-Object System.Windows.Forms.Label
            $pathLabel.Text = "مسیر: $($cfg.local_path)      Branch: $($cfg.branch)"
            $pathLabel.SetBounds(10, 36, 700, 20)
            $card.Controls.Add($pathLabel)

            $statusLabel = New-Object System.Windows.Forms.Label
            $statusLabel.Text = 'وضعیت:'
            $statusLabel.SetBounds(10, 58, 60, 20)
            $card.Controls.Add($statusLabel)

            $statusValueLabel = New-Object System.Windows.Forms.Label
            $statusValueLabel.Text = 'بررسی نشده'
            $statusValueLabel.ForeColor = [System.Drawing.Color]::DimGray
            $statusValueLabel.SetBounds(75, 58, 400, 20)
            $card.Controls.Add($statusValueLabel)

            $deployableLabel = New-Object System.Windows.Forms.Label
            if ($ProjectInfo.IsDeployable) {
                $deployableLabel.Text = 'آماده Deploy'
                $deployableLabel.ForeColor = [System.Drawing.Color]::ForestGreen
            } else {
                $deployableLabel.Text = 'نیازمند تکمیل: ' + (($ProjectInfo.BlockingReasons | Select-Object -First 1) -join '; ')
                $deployableLabel.ForeColor = [System.Drawing.Color]::DarkGoldenrod
            }
            $deployableLabel.SetBounds(10, 80, 820, 20)
            $card.Controls.Add($deployableLabel)

            $lastResultLabel = New-Object System.Windows.Forms.Label
            $lastResultLabel.Text = 'آخرین نتیجه: —'
            $lastResultLabel.ForeColor = [System.Drawing.Color]::DimGray
            $lastResultLabel.SetBounds(10, 102, 820, 20)
            $card.Controls.Add($lastResultLabel)

            $checkButton = New-Object System.Windows.Forms.Button
            $checkButton.Text = 'بررسی'
            $checkButton.SetBounds(10, 126, 90, 26)
            $card.Controls.Add($checkButton)

            $gitButton = New-Object System.Windows.Forms.Button
            $gitButton.Text = 'ارسال به Git'
            $gitButton.SetBounds(108, 126, 120, 26)
            $card.Controls.Add($gitButton)

            $deployButton = New-Object System.Windows.Forms.Button
            $deployButton.Text = 'ارسال به Git و سرور'
            $deployButton.SetBounds(236, 126, 170, 26)
            $deployButton.Enabled = $ProjectInfo.IsDeployable
            if (-not $ProjectInfo.IsDeployable) {
                $toolTip = New-Object System.Windows.Forms.ToolTip
                $toolTip.SetToolTip($deployButton, ($ProjectInfo.BlockingReasons -join "`n"))
            }
            $card.Controls.Add($deployButton)

            $viewLogButton = New-Object System.Windows.Forms.Button
            $viewLogButton.Text = 'مشاهده گزارش'
            $viewLogButton.SetBounds(414, 126, 120, 26)
            $card.Controls.Add($viewLogButton)

            $row = @{
                ProjectInfo      = $ProjectInfo
                Card             = $card
                StatusValueLabel = $statusValueLabel
                DeployableLabel  = $deployableLabel
                LastResultLabel  = $lastResultLabel
                CheckButton      = $checkButton
                GitButton        = $gitButton
                DeployButton     = $deployButton
                ViewLogButton    = $viewLogButton
            }

            $checkButton.Add_Click({ & $InvokeCheckAction -Row $row }.GetNewClosure())
            $gitButton.Add_Click({ & $InvokeGitOnlyAction -Row $row }.GetNewClosure())
            $deployButton.Add_Click({ & $InvokeDeployAction -Row $row }.GetNewClosure())
            $viewLogButton.Add_Click({ & $InvokeViewLogAction -Row $row }.GetNewClosure())

            return $row
        }

        $InvokeCheckAction = {
            param($Row)
            $projectInfo = $Row.ProjectInfo
            & $SetRowStatus -ProjectKey $projectInfo.Key -StatusKey 'Checking'
            & $AppendLog -Text "بررسی پروژه '$($projectInfo.Key)'..." -ColorName 'Blue'
            & $StartTracked -ProjectKey $projectInfo.Key -Variables @{ ProjectInfo = $projectInfo } -Operation {
                $check = Get-ProjectCheckResult -ProjectInfo $ProjectInfo
                # 20s, not 10s -- a real, slowly-failing DNS lookup (e.g.
                # the resolver retrying before giving up) can legitimately
                # take longer than 10s to produce git's own classifiable
                # "Could not resolve host" text (confirmed empirically:
                # ~12.6s for a real unresolvable hostname on this network).
                # Before the Invoke-ExternalCommand timeout-kill fix (see
                # SimpleDeploy.Common.ps1), a too-short timeout here was
                # silently masked (the process was never actually killed,
                # so it always got the real complete error eventually,
                # just slower than the nominal timeout suggested); now
                # that timeouts are genuinely enforced, this value must be
                # realistic or a slow-but-real DNS failure gets cut off
                # before it can be classified correctly.
                $connectivity = if ($check.RemoteExists) { Test-GitConnectivity -RepositoryPath $ProjectInfo.Config.local_path -RemoteName $ProjectInfo.Config.remote -TimeoutSeconds 20 } else { $null }
                return [PSCustomObject]@{ Check = $check; Connectivity = $connectivity }
            } -OnComplete {
                param($completion, $key)
                if (-not $completion.Success) {
                    & $SetRowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $completion.Error
                    return
                }
                $data = $completion.Result
                $check = $data.Check
                if (-not $check.IsGitRepo -or -not $check.BranchMatches -or -not $check.RemoteExists) {
                    & $SetRowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $check.FailureReason
                } else {
                    & $SetRowStatus -ProjectKey $key -StatusKey $check.StatusKey
                }
                if ($data.Connectivity -and -not $data.Connectivity.Ok) {
                    & $AppendLog -Text "اتصال Git برای '$key' ناموفق بود ($($data.Connectivity.Category)): $($data.Connectivity.Message)" -ColorName 'Red'
                } elseif ($data.Connectivity -and $data.Connectivity.Ok) {
                    & $AppendLog -Text "اتصال Git برای '$key' موفق بود." -ColorName 'Green'
                }
                & $AppendLog -Text "بررسی '$key' کامل شد." -ColorName 'Gray'
            }
        }

        $InvokeGitOnlyAction = {
            param($Row)
            $projectInfo = $Row.ProjectInfo
            & $AppendLog -Text "در حال بررسی پروژه '$($projectInfo.Key)' قبل از ارسال..." -ColorName 'Blue'

            # The check itself is fast (local git only) — but still run it
            # off the UI thread so a slow disk/antivirus scan can never
            # freeze the window, matching "پنجره اصلی Freeze نشود" for
            # every operation, not just the visibly slow ones.
            & $StartTracked -ProjectKey $projectInfo.Key -Variables @{ ProjectInfo = $projectInfo } -Operation {
                return Get-ProjectCheckResult -ProjectInfo $ProjectInfo
            } -OnComplete {
                param($completion, $key)
                if (-not $completion.Success) {
                    Show-LauncherError -Text "بررسی پروژه با خطا مواجه شد: $($completion.Error)"
                    & $SetRowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $completion.Error
                    return
                }
                $check = $completion.Result
                if (-not $check.IsGitRepo -or -not $check.BranchMatches -or -not $check.RemoteExists) {
                    Show-LauncherError -Text "Preflight ناموفق بود: $($check.FailureReason)"
                    & $SetRowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $check.FailureReason
                    return
                }
                if (-not $check.HasUncommittedChanges -and -not $check.HasUnpushedCommit) {
                    & $SetRowStatus -ProjectKey $key -StatusKey 'NoChanges'
                    Show-LauncherInfo -Text "پروژه '$key' هیچ تغییری برای ارسال ندارد."
                    return
                }
                $commitMessage = $null
                if ($check.HasUncommittedChanges) {
                    $sensitive = Test-SensitiveFiles -RepositoryPath $GuiState.GuiRows[$key].ProjectInfo.Config.local_path -StatusLines $check.StatusLines
                    if (@($sensitive.BlockedFiles).Count -gt 0) {
                        Show-LauncherError -Text "فایل حساس/مسدود پیدا شد و ارسال متوقف شد:`n$($sensitive.BlockedFiles -join "`n")"
                        return
                    }
                    $fileList = ($check.StatusLines -join "`n")
                    $confirmed = Show-LauncherConfirm -Text "تغییرات پروژه '$key':`n$fileList`n`nآیا این تغییرات commit و push شوند؟"
                    if (-not $confirmed) { return }
                    $suggested = "SimpleDeploy: به‌روزرسانی خودکار $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
                    $commitMessage = New-LauncherInputDialog -Prompt 'پیام Commit را وارد کنید:' -DefaultValue $suggested
                    if ($null -eq $commitMessage) { return }
                } else {
                    $confirmed = Show-LauncherConfirm -Text "یک Commit محلی Push‌نشده برای '$key' وجود دارد. همین Commit Push شود؟"
                    if (-not $confirmed) { return }
                }

                $runLog = New-LauncherRunLog -ProjectKey $key -LogsRootDir $logsRootDir
                $GuiState.CurrentRunLog = $runLog

                & $StartTracked -ProjectKey $key -Variables @{ ProjectInfo = $GuiState.GuiRows[$key].ProjectInfo; Check = $check; CommitMessage = $commitMessage } -Operation {
                    return Invoke-LauncherGitOnlyFlow -ProjectInfo $ProjectInfo -Check $Check -CommitMessage $CommitMessage -ProgressCallback {
                        param($statusKey) $OutputQueue.Enqueue(@{ Type = 'StatusChange'; Text = $statusKey })
                    } -RetryNoticeCallback {
                        param($attempt, $delay, $category) $OutputQueue.Enqueue(@{ Type = 'Warn'; Text = "تلاش مجدد Push ($attempt) پس از $delay ثانیه — دسته خطا: $category" })
                    }
                } -OnOutput {
                    # $key (the FIRST -OnComplete's own param, above) must
                    # NOT be referenced here: -OnOutput is stored via
                    # $StartTracked into $GuiState.CurrentOnOutput and
                    # invoked LATER, repeatedly, from pollTimer's own tick
                    # handler ("& $GuiState.CurrentOnOutput $item") for as
                    # long as this SECOND (push) operation keeps producing
                    # output -- by which time the FIRST -OnComplete's own
                    # invocation (the only thing that ever gave $key a
                    # value) has already returned. Same root cause as the
                    # earlier "$Row cannot be retrieved" bug, just in a
                    # spot not yet audited then: -OnOutput is the ONLY
                    # -OnOutput callback anywhere in this file (Deploy's
                    # own & $StartTracked call never passes one), which is
                    # exactly why only Git-only hit this, never Deploy.
                    # $GuiState.CurrentProjectKey is already guaranteed
                    # correct for as long as -OnOutput can possibly fire
                    # (pollTimer only clears it once the operation is
                    # detected complete, which is also the point -OnOutput
                    # stops being called) -- no new parameter needed.
                    param($item)
                    if ($item.Type -eq 'StatusChange') {
                        & $SetRowStatus -ProjectKey $GuiState.CurrentProjectKey -StatusKey $item.Text
                    }
                } -OnComplete {
                    param($completion, $completedKey)
                    if ($completion.Success) {
                        $flowResult = $completion.Result
                        if ($flowResult.Status -eq 'Succeeded') {
                            & $SetRowStatus -ProjectKey $completedKey -StatusKey 'Completed'
                            $GuiState.GuiRows[$completedKey].LastResultLabel.Text = "آخرین نتیجه: Push موفق ($((Get-Date).ToString('HH:mm:ss'))) — commit $($flowResult.CommitSha.Substring(0,12))"
                            Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome 'Succeeded' -CommitSha $flowResult.CommitSha | Out-Null
                        } else {
                            & $SetRowStatus -ProjectKey $completedKey -StatusKey 'NoChanges'
                            Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome $flowResult.Status | Out-Null
                        }
                    } else {
                        & $SetRowStatus -ProjectKey $completedKey -StatusKey 'Failed' -ExtraText $completion.Error
                        $GuiState.GuiRows[$completedKey].LastResultLabel.Text = "آخرین نتیجه: ناموفق ($((Get-Date).ToString('HH:mm:ss')))"
                        Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome 'Failed' -ErrorText $completion.Error | Out-Null
                        Show-LauncherError -Text "ارسال به Git برای '$completedKey' ناموفق بود:`n$($completion.Error)"
                    }
                    $GuiState.CurrentRunLog = $null
                }
            }
        }

        $InvokeDeployAction = {
            param($Row)
            $projectInfo = $Row.ProjectInfo
            if (-not $projectInfo.IsDeployable) {
                Show-LauncherError -Text "پروژه '$($projectInfo.Key)' هنوز برای Deploy پیکربندی کامل ندارد:`n$($projectInfo.BlockingReasons -join "`n")"
                return
            }

            $confirmed = Show-LauncherConfirm -Text "این عملیات تغییرات پروژه '$($projectInfo.Key)' را commit/push کرده و در صورت موفقیت روی سرور واقعی Deploy می‌کند (Build، انتقال، فعال‌سازی، Health Check). ادامه می‌دهید؟"
            if (-not $confirmed) { return }

            $suggested = "SimpleDeploy: به‌روزرسانی خودکار $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
            $commitMessage = New-LauncherInputDialog -Prompt 'پیام Commit (در صورت وجود تغییرات جدید):' -DefaultValue $suggested
            if ($null -eq $commitMessage) { return }

            $runLog = New-LauncherRunLog -ProjectKey $projectInfo.Key -LogsRootDir $logsRootDir
            $GuiState.CurrentRunLog = $runLog
            $GuiState.PausedByThisRun = $true
            & $UpdateServiceStatusLabel

            & $StartTracked -ProjectKey $projectInfo.Key -Variables @{
                ProjectInfo   = $projectInfo
                Config        = $GuiState.GuiConfig
                ToolRoot      = $ToolRoot
                StateDir      = $stateDir
                CommitMessage = $commitMessage
            } -Operation {
                return Invoke-LauncherDeployFlow -ProjectInfo $ProjectInfo -Config $Config -ToolRoot $ToolRoot -StateDir $StateDir -CommitMessage $CommitMessage
            } -OnComplete {
                param($completion, $key)
                $GuiState.PausedByThisRun = $false
                & $UpdateServiceStatusLabel
                if ($completion.Success) {
                    $deployResult = $completion.Result
                    switch ($deployResult.Status) {
                        'Succeeded' {
                            & $SetRowStatus -ProjectKey $key -StatusKey 'Completed'
                            $GuiState.GuiRows[$key].LastResultLabel.Text = "آخرین نتیجه: موفق ($((Get-Date).ToString('HH:mm:ss')))" + $(if ($deployResult.CommitSha) { " — commit $($deployResult.CommitSha.Substring(0,12))" } else { '' })
                            Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome 'Succeeded' -CommitSha $deployResult.CommitSha | Out-Null
                        }
                        'Skipped' {
                            & $SetRowStatus -ProjectKey $key -StatusKey 'NoChanges' -ExtraText $deployResult.Reason
                            Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome 'Skipped' | Out-Null
                        }
                        default {
                            & $SetRowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $deployResult.Reason
                            $GuiState.GuiRows[$key].LastResultLabel.Text = "آخرین نتیجه: ناموفق ($((Get-Date).ToString('HH:mm:ss')))"
                            Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome 'Failed' -ErrorText $deployResult.Reason | Out-Null
                            Show-LauncherError -Text "Deploy برای '$key' ناموفق بود: $($deployResult.Reason)"
                        }
                    }
                } else {
                    & $SetRowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $completion.Error
                    $GuiState.GuiRows[$key].LastResultLabel.Text = "آخرین نتیجه: ناموفق ($((Get-Date).ToString('HH:mm:ss')))"
                    Complete-LauncherRunLog -RunLog $GuiState.CurrentRunLog -Outcome 'Failed' -ErrorText $completion.Error | Out-Null
                    Show-LauncherError -Text "Deploy برای '$key' ناموفق بود:`n$($completion.Error)"
                }
                $GuiState.CurrentRunLog = $null
            }
        }

        $InvokeViewLogAction = {
            param($Row)
            $key = $Row.ProjectInfo.Key
            if (-not (Test-Path -LiteralPath $logsRootDir)) {
                Show-LauncherInfo -Text 'هنوز هیچ گزارشی برای این پروژه ثبت نشده است.'
                return
            }
            $latest = Get-ChildItem -Path $logsRootDir -Recurse -Filter "$key-*.json" -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if (-not $latest) {
                Show-LauncherInfo -Text 'هنوز هیچ گزارشی برای این پروژه ثبت نشده است.'
                return
            }
            Start-Process -FilePath 'notepad.exe' -ArgumentList @("`"$($latest.FullName)`"")
        }

        $InvokeCheckAllAction = {
            $keys = @($GuiState.GuiRows.Keys)
            if ($keys.Count -eq 0) { return }
            # Sequential by design (status updates stay legible and only
            # one background operation is ever active) -- but driven by
            # $AdvanceCheckAll/pollTimer, never a UI-thread wait loop. This
            # call only enqueues the batch and starts project #1; every
            # subsequent project is started later, from pollTimer's own
            # completion branch.
            $GuiState.CheckAllQueue = New-Object System.Collections.Generic.Queue[string]
            foreach ($k in $keys) { $GuiState.CheckAllQueue.Enqueue($k) }
            $GuiState.CheckAllTotal = $keys.Count
            $GuiState.CheckAllCancelled = $false
            & $AdvanceCheckAll
        }

        $BuildProjectRows = {
            $projectsPanel.Controls.Clear()
            $GuiState.GuiRows = @{}
            $GuiState.GuiConfig = Get-SimpleDeployConfig -ConfigPath $ConfigPath
            $GuiState.GuiProjects = Get-EnabledProjects -Config $GuiState.GuiConfig
            foreach ($projectInfo in $GuiState.GuiProjects) {
                $row = & $NewProjectRow -ProjectInfo $projectInfo -InvokeCheckAction $InvokeCheckAction -InvokeGitOnlyAction $InvokeGitOnlyAction -InvokeDeployAction $InvokeDeployAction -InvokeViewLogAction $InvokeViewLogAction
                $GuiState.GuiRows[$projectInfo.Key] = $row
                $projectsPanel.Controls.Add($row.Card)
            }
            & $UpdateServiceStatusLabel
        }

        $checkAllButton.Add_Click({ & $InvokeCheckAllAction }.GetNewClosure())
        $reloadButton.Add_Click({
            & $BuildProjectRows
            & $AppendLog -Text 'فهرست پروژه‌ها از config.json دوباره بارگذاری شد.' -ColorName 'Gray'
        }.GetNewClosure())

        $form.Add_FormClosing({
            param($sender, $e)
            if ($GuiState.CurrentHandle) {
                $proceed = Show-LauncherConfirm -Text 'یک عملیات هنوز در حال اجراست. بستن برنامه صبر می‌کند تا مرحله فعلی به‌صورت امن به پایان برسد و سرویس Deployment بازگردانده شود. ادامه می‌دهید؟'
                if (-not $proceed) { $e.Cancel = $true; return }
                Stop-LauncherOperation -Handle $GuiState.CurrentHandle
                # Bounded (max 60s) and only reached on this one rare path
                # (closing while an operation is active) -- DoEvents() here
                # keeps the window repainting/responsive during the wait
                # instead of appearing hung, same reasoning as everywhere
                # else in this file that a wait is unavoidable.
                $waitDeadline = (Get-Date).AddSeconds(60)
                while (-not (Test-LauncherOperationComplete -Handle $GuiState.CurrentHandle) -and (Get-Date) -lt $waitDeadline) {
                    [System.Windows.Forms.Application]::DoEvents()
                    Start-Sleep -Milliseconds 100
                }
                if ($GuiState.CurrentHandle) {
                    Complete-LauncherOperation -Handle $GuiState.CurrentHandle | Out-Null
                    $GuiState.CurrentHandle = $null
                }
            }
        }.GetNewClosure())

        try {
            & $BuildProjectRows
        } catch {
            Show-LauncherError -Text "بارگذاری اولیه پیکربندی با خطا مواجه شد:`n$($_.Exception.Message)"
            return
        }
        & $AppendLog -Text 'SimpleDeploy Launcher آماده است.' -ColorName 'Gray'

        # The .bat launcher starts powershell.exe with -WindowStyle Hidden
        # so the console never flashes — but that hidden show-state is
        # otherwise inherited by the FIRST top-level window the process
        # creates too: without the explicit calls below, the form exists
        # (a real window handle, confirmed via EnumWindows) but renders
        # with IsWindowVisible=false, i.e. the user double-clicks the
        # shortcut and NOTHING appears at all. Confirmed empirically.
        $form.WindowState = [System.Windows.Forms.FormWindowState]::Normal
        $form.Show()
        $form.Activate()

        [System.Windows.Forms.Application]::add_ThreadException({
            param($sender, $e)
            Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'UNHANDLED THREAD EXCEPTION' -Detail "error=$($e.Exception.Message)"
            Show-LauncherError -Text "یک خطای غیرمنتظره رخ داد و مدیریت شد (برنامه بسته نمی‌شود):`n$($e.Exception.Message)"
        }.GetNewClosure())

        if ($TestAutomationHook) {
            & $TestAutomationHook $form $GuiState
        }

        [System.Windows.Forms.Application]::Run($form)
    } finally {
        Close-LauncherRunspace
        Exit-SimpleDeployLock -Mutex $lockMutex
    }
}
