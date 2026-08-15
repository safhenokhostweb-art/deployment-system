<#
.SYNOPSIS
    Validates an already-built artifact's CONTENT (pre-zip workspace
    ProjectDir) against a Project Profile's own, optional
    artifact_validation rules — required_files/required_directories
    (e.g. Next.js standalone's server.js/.next/static/public, or
    Laravel's artisan/app/routes/public) and forbidden_strings/
    required_strings (e.g. a Next.js build must never contain
    "http://localhost:3000" and must contain the real production API
    URL — the exact root cause of Sandogh's build-time/runtime
    environment failure). Runs BEFORE New-DeploymentArtifact packages
    the zip, so an incomplete or misconfigured artifact is never
    packaged, transferred, or activated in the first place.

.NOTES
    Every rule is OPTIONAL and additive — a project config with no
    artifact_validation property at all (every real project's config.json
    entry today) gets Passed=$true immediately, with zero behavior
    change. This mirrors the same optional-field pattern already used
    for source_subdirectory (see Export-GitArchiveToWorkspace).
#>

Set-StrictMode -Version Latest

# Content-scan (forbidden_strings/required_strings) is skipped for any
# file whose extension isn't in this list, or whose size exceeds
# $MaxScanFileSizeBytes — scanning genuinely binary assets (images,
# fonts, archives) is pointless and, for a large vendor bundle, slow.
# This default list intentionally covers every text-ish output a
# Laravel/Node/Next.js build actually produces (JS bundles are exactly
# where a baked-in "http://localhost:3000" was found in Sandogh).
$script:DefaultArtifactScanExtensions = @(
    '.js', '.mjs', '.cjs', '.jsx', '.ts', '.tsx', '.json', '.html', '.htm',
    '.css', '.map', '.env', '.php', '.blade.php', '.yml', '.yaml', '.txt',
    '.xml', '.env.production', '.config'
)
$script:DefaultMaxScanFileSizeBytes = 20MB

<#
.OUTPUTS
    Hashtable: @{ Passed (bool); Failures (string[]) } — every violated
    rule is collected and reported together (never fail-fast), matching
    the same pattern already used by Get-ProjectDeployability.
#>
function Test-ArtifactValidation {
    param(
        [Parameter(Mandatory = $true)][string]$ArtifactRoot,
        # Accepts $null (no rules configured) or a PSCustomObject/hashtable
        # with any subset of: required_files, required_directories,
        # forbidden_strings, required_strings, scan_extensions,
        # max_scan_file_size_kb — every one of them optional.
        [Parameter(Mandatory = $false)][AllowNull()]$ArtifactValidation
    )

    $failures = New-Object System.Collections.Generic.List[string]

    if (-not $ArtifactValidation) {
        return @{ Passed = $true; Failures = @() }
    }

    $requiredFiles = @(Get-ArtifactValidationListField -Rules $ArtifactValidation -FieldName 'required_files')
    foreach ($relativePath in $requiredFiles) {
        $fullPath = Join-Path $ArtifactRoot $relativePath
        if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
            $failures.Add("فایل الزامی در Artifact پیدا نشد: $relativePath")
        }
    }

    $requiredDirectories = @(Get-ArtifactValidationListField -Rules $ArtifactValidation -FieldName 'required_directories')
    foreach ($relativePath in $requiredDirectories) {
        $fullPath = Join-Path $ArtifactRoot $relativePath
        if (-not (Test-Path -LiteralPath $fullPath -PathType Container)) {
            $failures.Add("پوشه الزامی در Artifact پیدا نشد: $relativePath")
        }
    }

    $forbiddenStrings = @(Get-ArtifactValidationListField -Rules $ArtifactValidation -FieldName 'forbidden_strings')
    $requiredStrings = @(Get-ArtifactValidationListField -Rules $ArtifactValidation -FieldName 'required_strings')

    if ($forbiddenStrings.Count -gt 0 -or $requiredStrings.Count -gt 0) {
        $scanExtensions = @(Get-ArtifactValidationListField -Rules $ArtifactValidation -FieldName 'scan_extensions')
        if ($scanExtensions.Count -eq 0) { $scanExtensions = $script:DefaultArtifactScanExtensions }

        $maxScanFileSizeBytes = $script:DefaultMaxScanFileSizeBytes
        if ($ArtifactValidation.PSObject.Properties['max_scan_file_size_kb'] -and $ArtifactValidation.max_scan_file_size_kb) {
            $maxScanFileSizeBytes = [int64]$ArtifactValidation.max_scan_file_size_kb * 1KB
        }

        $scanResult = Find-ArtifactStringMatches -ArtifactRoot $ArtifactRoot -ScanExtensions $scanExtensions `
            -MaxScanFileSizeBytes $maxScanFileSizeBytes -ForbiddenStrings $forbiddenStrings -RequiredStrings $requiredStrings

        foreach ($hit in $scanResult.ForbiddenHits) {
            $failures.Add("رشته ممنوعه '$($hit.Value)' در فایل Artifact پیدا شد: $($hit.RelativePath)")
        }
        foreach ($missing in $scanResult.MissingRequired) {
            $failures.Add("رشته الزامی در هیچ فایل اسکن‌شده Artifact پیدا نشد: $missing")
        }
    }

    return @{ Passed = ($failures.Count -eq 0); Failures = $failures.ToArray() }
}

<#
.SYNOPSIS
    Reads one array-valued field off $Rules (PSCustomObject from JSON,
    or a plain hashtable — both supported since tests build fixtures as
    hashtables while real config comes from ConvertFrom-Json) as a flat
    string[], tolerating the field being entirely absent.
#>
function Get-ArtifactValidationListField {
    param(
        [Parameter(Mandatory = $true)]$Rules,
        [Parameter(Mandatory = $true)][string]$FieldName
    )

    if ($Rules -is [System.Collections.IDictionary]) {
        if (-not $Rules.Contains($FieldName)) { return @() }
        $value = $Rules[$FieldName]
    } else {
        if (-not $Rules.PSObject.Properties[$FieldName]) { return @() }
        $value = $Rules.$FieldName
    }

    if ($null -eq $value) { return @() }
    return @($value | Where-Object { $_ })
}

<#
.SYNOPSIS
    Single pass over every eligible file under $ArtifactRoot, checking
    each forbidden/required string with plain substring search (never
    regex — a string like "http://localhost:3000" must be matched
    literally, not interpreted as a pattern).

.OUTPUTS
    Hashtable: @{ ForbiddenHits (list of @{Value;RelativePath});
    MissingRequired (string[] — required strings never found in ANY
    scanned file) }
#>
function Find-ArtifactStringMatches {
    param(
        [Parameter(Mandatory = $true)][string]$ArtifactRoot,
        [Parameter(Mandatory = $true)][string[]]$ScanExtensions,
        [Parameter(Mandatory = $true)][int64]$MaxScanFileSizeBytes,
        [Parameter(Mandatory = $false)][string[]]$ForbiddenStrings = @(),
        [Parameter(Mandatory = $false)][string[]]$RequiredStrings = @()
    )

    $forbiddenHits = New-Object System.Collections.Generic.List[object]
    $foundRequired = New-Object System.Collections.Generic.HashSet[string]

    $rootLength = ([System.IO.Path]::GetFullPath($ArtifactRoot)).TrimEnd('\', '/').Length
    $extensionSet = New-Object System.Collections.Generic.HashSet[string]([StringComparer]::OrdinalIgnoreCase)
    foreach ($ext in $ScanExtensions) { [void]$extensionSet.Add($ext) }

    $files = Get-ChildItem -LiteralPath $ArtifactRoot -Recurse -File -Force -ErrorAction SilentlyContinue | Where-Object {
        $extensionSet.Contains($_.Extension) -and $_.Length -le $MaxScanFileSizeBytes
    }

    foreach ($file in $files) {
        $content = $null
        try {
            $content = Get-Content -LiteralPath $file.FullName -Raw -ErrorAction Stop
        } catch {
            # An unreadable file (locked, exotic encoding) is never a hard
            # validation failure on its own — it simply can't be scanned.
            continue
        }
        if ($null -eq $content) { continue }

        $relativePath = $file.FullName.Substring($rootLength + 1).Replace('\', '/')

        foreach ($forbidden in $ForbiddenStrings) {
            if ($content.Contains($forbidden)) {
                $forbiddenHits.Add(@{ Value = $forbidden; RelativePath = $relativePath })
            }
        }
        foreach ($required in $RequiredStrings) {
            if ($content.Contains($required)) {
                [void]$foundRequired.Add($required)
            }
        }
    }

    $missingRequired = @($RequiredStrings | Where-Object { -not $foundRequired.Contains($_) })

    return @{ ForbiddenHits = $forbiddenHits.ToArray(); MissingRequired = $missingRequired }
}
