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
#>
function Show-SimpleDeployLauncher {
    param(
        [Parameter(Mandatory = $true)][string]$ToolRoot,
        [Parameter(Mandatory = $false)][string]$ConfigPath
    )

    if (-not $ConfigPath) { $ConfigPath = Join-Path $ToolRoot 'config.json' }
    $stateDir = Join-Path $ToolRoot 'state'
    $logsRootDir = Join-Path $ToolRoot 'logs'

    $lockMutex = Enter-SimpleDeployLock
    if ($null -eq $lockMutex) {
        Show-LauncherError -Text 'یک نمونه دیگر از SimpleDeploy (رابط گرافیکی یا خط فرمان) در حال اجراست. برای جلوگیری از تداخل، این اجرا متوقف می‌شود.'
        return
    }

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
        # Shared mutable state (closures below capture these by reference
        # via script-scope variables, since WinForms event handlers run
        # later, on the same UI thread, after this function's own local
        # scope would otherwise have been torn down)
        # ============================================================
        $script:GuiConfig = $null
        $script:GuiProjects = @()
        $script:GuiRows = @{}
        $script:CurrentHandle = $null
        $script:CurrentRunLog = $null
        $script:CurrentProjectKey = $null
        $script:PausedByThisRun = $false
        $script:PausedTaskNamesThisRun = @()

        function Append-Log {
            param([string]$Text, [string]$ColorName = 'Gray')
            $logBox.SelectionStart = $logBox.TextLength
            $logBox.SelectionLength = 0
            $logBox.SelectionColor = Get-LauncherStatusColor -ColorName $ColorName
            $logBox.AppendText("$Text`r`n")
            $logBox.ScrollToCaret()
        }

        function Update-ServiceStatusLabel {
            if (-not $script:GuiConfig) { return }
            $info = Get-DeploymentServiceDisplayStatus -DeploymentServiceCfg $script:GuiConfig.deployment_service -PausedByThisRun $script:PausedByThisRun
            $serviceStatusLabel.Text = "Deployment Service: $($info.Text)"
            $serviceStatusLabel.ForeColor = Get-LauncherStatusColor -ColorName $info.Color
        }

        function Set-RowStatus {
            param([string]$ProjectKey, [string]$StatusKey, [string]$ExtraText = $null)
            if (-not $script:GuiRows.ContainsKey($ProjectKey)) { return }
            $row = $script:GuiRows[$ProjectKey]
            $info = Get-LauncherStatusInfo -StatusKey $StatusKey
            $text = $info.Text
            if ($ExtraText) { $text = "$text — $ExtraText" }
            $row.StatusValueLabel.Text = $text
            $row.StatusValueLabel.ForeColor = Get-LauncherStatusColor -ColorName $info.Color
        }

        function Set-RowsEnabled {
            param([bool]$Enabled)
            foreach ($key in $script:GuiRows.Keys) {
                $row = $script:GuiRows[$key]
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
        function Start-Tracked {
            param(
                [scriptblock]$Operation,
                [hashtable]$Variables = @{},
                [string]$ProjectKey,
                [scriptblock]$OnOutput,
                [scriptblock]$OnComplete
            )
            $script:CurrentProjectKey = $ProjectKey
            $script:CurrentHandle = Start-LauncherOperation -ToolRoot $ToolRoot -Operation $Operation -Variables $Variables
            $script:CurrentOnOutput = $OnOutput
            $script:CurrentOnComplete = $OnComplete
            $cancelButton.Enabled = $true
            Set-RowsEnabled -Enabled $false
        }

        $pollTimer = New-Object System.Windows.Forms.Timer
        $pollTimer.Interval = 150
        $pollTimer.Add_Tick({
            if (-not $script:CurrentHandle) { return }
            $items = Receive-LauncherOperationOutput -Handle $script:CurrentHandle
            foreach ($item in $items) {
                if ($script:CurrentOnOutput) { & $script:CurrentOnOutput $item }
                $color = switch ($item.Type) {
                    'Error'   { 'Red' }
                    'Warn'    { 'Yellow' }
                    'Success' { 'Green' }
                    'Step'    { 'Blue' }
                    default   { 'Gray' }
                }
                Append-Log -Text $item.Text -ColorName $color
            }
            if (Test-LauncherOperationComplete -Handle $script:CurrentHandle) {
                $completion = Complete-LauncherOperation -Handle $script:CurrentHandle
                $handledProjectKey = $script:CurrentProjectKey
                $onComplete = $script:CurrentOnComplete
                $script:CurrentHandle = $null
                $script:CurrentOnOutput = $null
                $script:CurrentOnComplete = $null
                $script:CurrentProjectKey = $null
                $cancelButton.Enabled = $false
                Set-RowsEnabled -Enabled $true
                if ($onComplete) { & $onComplete $completion $handledProjectKey }
            }
        })
        $pollTimer.Start()

        $cancelButton.Add_Click({
            if ($script:CurrentHandle) {
                if (Show-LauncherConfirm -Text 'آیا عملیات جاری لغو شود؟ عملیات Git/شبکه‌ای که همین الان در حال اجراست تا پایان طبیعی خودش ادامه می‌یابد و فقط مرحله بعدی شروع نمی‌شود.') {
                    Stop-LauncherOperation -Handle $script:CurrentHandle
                    Append-Log -Text 'درخواست لغو ارسال شد...' -ColorName 'Yellow'
                }
            }
        })

        # ============================================================
        # Project row construction
        # ============================================================
        function New-ProjectRow {
            param($ProjectInfo)

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

            $checkButton.Add_Click({ Invoke-CheckAction -Row $row }.GetNewClosure())
            $gitButton.Add_Click({ Invoke-GitOnlyAction -Row $row }.GetNewClosure())
            $deployButton.Add_Click({ Invoke-DeployAction -Row $row }.GetNewClosure())
            $viewLogButton.Add_Click({ Invoke-ViewLogAction -Row $row }.GetNewClosure())

            return $row
        }

        function Invoke-CheckAction {
            param($Row)
            $projectInfo = $Row.ProjectInfo
            Set-RowStatus -ProjectKey $projectInfo.Key -StatusKey 'Checking'
            Append-Log -Text "بررسی پروژه '$($projectInfo.Key)'..." -ColorName 'Blue'
            Start-Tracked -ProjectKey $projectInfo.Key -Variables @{ ProjectInfo = $projectInfo } -Operation {
                $check = Get-ProjectCheckResult -ProjectInfo $ProjectInfo
                $connectivity = if ($check.RemoteExists) { Test-GitConnectivity -RepositoryPath $ProjectInfo.Config.local_path -RemoteName $ProjectInfo.Config.remote -TimeoutSeconds 10 } else { $null }
                return [PSCustomObject]@{ Check = $check; Connectivity = $connectivity }
            } -OnComplete {
                param($completion, $key)
                if (-not $completion.Success) {
                    Set-RowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $completion.Error
                    return
                }
                $data = $completion.Result
                $check = $data.Check
                if (-not $check.IsGitRepo -or -not $check.BranchMatches -or -not $check.RemoteExists) {
                    Set-RowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $check.FailureReason
                } else {
                    Set-RowStatus -ProjectKey $key -StatusKey $check.StatusKey
                }
                if ($data.Connectivity -and -not $data.Connectivity.Ok) {
                    Append-Log -Text "اتصال Git برای '$key' ناموفق بود ($($data.Connectivity.Category)): $($data.Connectivity.Message)" -ColorName 'Red'
                } elseif ($data.Connectivity -and $data.Connectivity.Ok) {
                    Append-Log -Text "اتصال Git برای '$key' موفق بود." -ColorName 'Green'
                }
                Append-Log -Text "بررسی '$key' کامل شد." -ColorName 'Gray'
            }
        }

        function Invoke-GitOnlyAction {
            param($Row)
            $projectInfo = $Row.ProjectInfo
            Append-Log -Text "در حال بررسی پروژه '$($projectInfo.Key)' قبل از ارسال..." -ColorName 'Blue'

            # The check itself is fast (local git only) — but still run it
            # off the UI thread so a slow disk/antivirus scan can never
            # freeze the window, matching "پنجره اصلی Freeze نشود" for
            # every operation, not just the visibly slow ones.
            Start-Tracked -ProjectKey $projectInfo.Key -Variables @{ ProjectInfo = $projectInfo } -Operation {
                return Get-ProjectCheckResult -ProjectInfo $ProjectInfo
            } -OnComplete {
                param($completion, $key)
                if (-not $completion.Success) {
                    Show-LauncherError -Text "بررسی پروژه با خطا مواجه شد: $($completion.Error)"
                    Set-RowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $completion.Error
                    return
                }
                $check = $completion.Result
                if (-not $check.IsGitRepo -or -not $check.BranchMatches -or -not $check.RemoteExists) {
                    Show-LauncherError -Text "Preflight ناموفق بود: $($check.FailureReason)"
                    Set-RowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $check.FailureReason
                    return
                }
                if (-not $check.HasUncommittedChanges -and -not $check.HasUnpushedCommit) {
                    Set-RowStatus -ProjectKey $key -StatusKey 'NoChanges'
                    Show-LauncherInfo -Text "پروژه '$key' هیچ تغییری برای ارسال ندارد."
                    return
                }

                $commitMessage = $null
                if ($check.HasUncommittedChanges) {
                    $sensitive = Test-SensitiveFiles -RepositoryPath $Row.ProjectInfo.Config.local_path -StatusLines $check.StatusLines
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
                $script:CurrentRunLog = $runLog

                Start-Tracked -ProjectKey $key -Variables @{ ProjectInfo = $Row.ProjectInfo; Check = $check; CommitMessage = $commitMessage } -Operation {
                    return Invoke-LauncherGitOnlyFlow -ProjectInfo $ProjectInfo -Check $Check -CommitMessage $CommitMessage -ProgressCallback {
                        param($statusKey) $OutputQueue.Enqueue(@{ Type = 'StatusChange'; Text = $statusKey })
                    } -RetryNoticeCallback {
                        param($attempt, $delay, $category) $OutputQueue.Enqueue(@{ Type = 'Warn'; Text = "تلاش مجدد Push ($attempt) پس از $delay ثانیه — دسته خطا: $category" })
                    }
                } -OnOutput {
                    param($item)
                    if ($item.Type -eq 'StatusChange') {
                        Set-RowStatus -ProjectKey $key -StatusKey $item.Text
                    }
                } -OnComplete {
                    param($completion, $completedKey)
                    if ($completion.Success) {
                        $flowResult = $completion.Result
                        if ($flowResult.Status -eq 'Succeeded') {
                            Set-RowStatus -ProjectKey $completedKey -StatusKey 'Completed'
                            $Row.LastResultLabel.Text = "آخرین نتیجه: Push موفق ($((Get-Date).ToString('HH:mm:ss'))) — commit $($flowResult.CommitSha.Substring(0,12))"
                            Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome 'Succeeded' -CommitSha $flowResult.CommitSha | Out-Null
                        } else {
                            Set-RowStatus -ProjectKey $completedKey -StatusKey 'NoChanges'
                            Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome $flowResult.Status | Out-Null
                        }
                    } else {
                        Set-RowStatus -ProjectKey $completedKey -StatusKey 'Failed' -ExtraText $completion.Error
                        $Row.LastResultLabel.Text = "آخرین نتیجه: ناموفق ($((Get-Date).ToString('HH:mm:ss')))"
                        Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome 'Failed' -ErrorText $completion.Error | Out-Null
                        Show-LauncherError -Text "ارسال به Git برای '$completedKey' ناموفق بود:`n$($completion.Error)"
                    }
                    $script:CurrentRunLog = $null
                }
            }
        }

        function Invoke-DeployAction {
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
            $script:CurrentRunLog = $runLog
            $script:PausedByThisRun = $true
            Update-ServiceStatusLabel

            Start-Tracked -ProjectKey $projectInfo.Key -Variables @{
                ProjectInfo   = $projectInfo
                Config        = $script:GuiConfig
                ToolRoot      = $ToolRoot
                StateDir      = $stateDir
                CommitMessage = $commitMessage
            } -Operation {
                return Invoke-LauncherDeployFlow -ProjectInfo $ProjectInfo -Config $Config -ToolRoot $ToolRoot -StateDir $StateDir -CommitMessage $CommitMessage
            } -OnComplete {
                param($completion, $key)
                $script:PausedByThisRun = $false
                Update-ServiceStatusLabel
                if ($completion.Success) {
                    $deployResult = $completion.Result
                    switch ($deployResult.Status) {
                        'Succeeded' {
                            Set-RowStatus -ProjectKey $key -StatusKey 'Completed'
                            $Row.LastResultLabel.Text = "آخرین نتیجه: موفق ($((Get-Date).ToString('HH:mm:ss')))" + $(if ($deployResult.CommitSha) { " — commit $($deployResult.CommitSha.Substring(0,12))" } else { '' })
                            Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome 'Succeeded' -CommitSha $deployResult.CommitSha | Out-Null
                        }
                        'Skipped' {
                            Set-RowStatus -ProjectKey $key -StatusKey 'NoChanges' -ExtraText $deployResult.Reason
                            Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome 'Skipped' | Out-Null
                        }
                        default {
                            Set-RowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $deployResult.Reason
                            $Row.LastResultLabel.Text = "آخرین نتیجه: ناموفق ($((Get-Date).ToString('HH:mm:ss')))"
                            Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome 'Failed' -ErrorText $deployResult.Reason | Out-Null
                            Show-LauncherError -Text "Deploy برای '$key' ناموفق بود: $($deployResult.Reason)"
                        }
                    }
                } else {
                    Set-RowStatus -ProjectKey $key -StatusKey 'Failed' -ExtraText $completion.Error
                    $Row.LastResultLabel.Text = "آخرین نتیجه: ناموفق ($((Get-Date).ToString('HH:mm:ss')))"
                    Complete-LauncherRunLog -RunLog $script:CurrentRunLog -Outcome 'Failed' -ErrorText $completion.Error | Out-Null
                    Show-LauncherError -Text "Deploy برای '$key' ناموفق بود:`n$($completion.Error)"
                }
                $script:CurrentRunLog = $null
            }
        }

        function Invoke-ViewLogAction {
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

        function Invoke-CheckAllAction {
            foreach ($key in $script:GuiRows.Keys) {
                Invoke-CheckAction -Row $script:GuiRows[$key]
                # Sequential: wait for this project's check to finish
                # before starting the next, so status updates stay legible
                # and only one background operation is ever active.
                while ($script:CurrentHandle) {
                    [System.Windows.Forms.Application]::DoEvents()
                    Start-Sleep -Milliseconds 50
                }
            }
        }

        function Build-ProjectRows {
            $projectsPanel.Controls.Clear()
            $script:GuiRows = @{}
            $script:GuiConfig = Get-SimpleDeployConfig -ConfigPath $ConfigPath
            $script:GuiProjects = Get-EnabledProjects -Config $script:GuiConfig
            foreach ($projectInfo in $script:GuiProjects) {
                $row = New-ProjectRow -ProjectInfo $projectInfo
                $script:GuiRows[$projectInfo.Key] = $row
                $projectsPanel.Controls.Add($row.Card)
            }
            Update-ServiceStatusLabel
        }

        $checkAllButton.Add_Click({ Invoke-CheckAllAction })
        $reloadButton.Add_Click({
            Build-ProjectRows
            Append-Log -Text 'فهرست پروژه‌ها از config.json دوباره بارگذاری شد.' -ColorName 'Gray'
        })

        $form.Add_FormClosing({
            param($sender, $e)
            if ($script:CurrentHandle) {
                $proceed = Show-LauncherConfirm -Text 'یک عملیات هنوز در حال اجراست. بستن برنامه صبر می‌کند تا مرحله فعلی به‌صورت امن به پایان برسد و سرویس Deployment بازگردانده شود. ادامه می‌دهید؟'
                if (-not $proceed) { $e.Cancel = $true; return }
                Stop-LauncherOperation -Handle $script:CurrentHandle
                $waitDeadline = (Get-Date).AddSeconds(60)
                while (-not (Test-LauncherOperationComplete -Handle $script:CurrentHandle) -and (Get-Date) -lt $waitDeadline) {
                    Start-Sleep -Milliseconds 100
                }
                if ($script:CurrentHandle) {
                    Complete-LauncherOperation -Handle $script:CurrentHandle | Out-Null
                    $script:CurrentHandle = $null
                }
            }
        })

        try {
            Build-ProjectRows
        } catch {
            Show-LauncherError -Text "بارگذاری اولیه پیکربندی با خطا مواجه شد:`n$($_.Exception.Message)"
            return
        }
        Append-Log -Text 'SimpleDeploy Launcher آماده است.' -ColorName 'Gray'

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

        [System.Windows.Forms.Application]::Run($form)
    } finally {
        Exit-SimpleDeployLock -Mutex $lockMutex
    }
}
