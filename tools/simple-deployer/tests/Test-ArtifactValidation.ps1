<#
.SYNOPSIS
    Tests: Test-ArtifactValidation — required_files/required_directories
    existence checks, forbidden_strings/required_strings content
    scanning (extension filtering, file-size cap, multiple simultaneous
    failures collected together), and that both a PSCustomObject (real
    config.json, via ConvertFrom-Json) and a plain hashtable (test
    fixtures) are accepted as the rules object.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ArtifactValidation.ps1')

function New-FixtureArtifactRoot {
    $root = New-TestTempDirectory -Prefix 'sdtest-artifact-validation'
    New-Item -ItemType Directory -Path (Join-Path $root 'app') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $root 'routes') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $root 'public') -Force | Out-Null
    'console.log("build ok");' | Set-Content -LiteralPath (Join-Path $root 'public\app.js') -Encoding UTF8 -NoNewline
    '<?php // artisan' | Set-Content -LiteralPath (Join-Path $root 'artisan') -Encoding UTF8 -NoNewline
    '{}' | Set-Content -LiteralPath (Join-Path $root 'composer.json') -Encoding UTF8 -NoNewline
    return $root
}

# ============================================================
# 1. No rules at all ($null) — exactly today's behavior, zero effect.
# ============================================================
$root1 = New-FixtureArtifactRoot
$result1 = Test-ArtifactValidation -ArtifactRoot $root1 -ArtifactValidation $null
Assert-That '$null rules: Passed is true' $result1.Passed
Assert-That '$null rules: no failures reported' (@($result1.Failures).Count -eq 0)

# An empty rules object (no fields set) behaves identically — nothing to check.
$result1b = Test-ArtifactValidation -ArtifactRoot $root1 -ArtifactValidation ([PSCustomObject]@{})
Assert-That 'empty rules object: Passed is true' $result1b.Passed
Remove-TestTempDirectory -Path $root1

# ============================================================
# 2. required_files / required_directories — both satisfied.
# ============================================================
$root2 = New-FixtureArtifactRoot
$rules2 = [PSCustomObject]@{
    required_files       = @('artisan', 'composer.json')
    required_directories  = @('app', 'routes', 'public')
}
$result2 = Test-ArtifactValidation -ArtifactRoot $root2 -ArtifactValidation $rules2
Assert-That 'all required files/directories present: Passed is true' $result2.Passed
Remove-TestTempDirectory -Path $root2

# ============================================================
# 3. required_files / required_directories — missing entries are all
#    collected together (never fail-fast on the first one).
# ============================================================
$root3 = New-FixtureArtifactRoot
Remove-Item -LiteralPath (Join-Path $root3 'composer.json') -Force
Remove-Item -LiteralPath (Join-Path $root3 'routes') -Recurse -Force
$rules3 = [PSCustomObject]@{
    required_files       = @('artisan', 'composer.json')
    required_directories = @('app', 'routes', 'public')
}
$result3 = Test-ArtifactValidation -ArtifactRoot $root3 -ArtifactValidation $rules3
Assert-That 'missing required file/directory: Passed is false' (-not $result3.Passed)
Assert-That 'missing required file is reported' (@($result3.Failures | Where-Object { $_ -like '*composer.json*' }).Count -gt 0)
Assert-That 'missing required directory is reported' (@($result3.Failures | Where-Object { $_ -like '*routes*' }).Count -gt 0)
Assert-That 'both missing entries are collected together (not fail-fast)' (@($result3.Failures).Count -eq 2)
Remove-TestTempDirectory -Path $root3

# ============================================================
# 4. forbidden_strings — a build-time-baked localhost URL (the exact
#    Sandogh root cause) is caught, with the offending relative path
#    named in the failure message.
# ============================================================
$root4 = New-FixtureArtifactRoot
'const API = "http://localhost:3000/auth/login";' | Set-Content -LiteralPath (Join-Path $root4 'public\app.js') -Encoding UTF8 -NoNewline
$rules4 = [PSCustomObject]@{ forbidden_strings = @('http://localhost:3000') }
$result4 = Test-ArtifactValidation -ArtifactRoot $root4 -ArtifactValidation $rules4
Assert-That 'forbidden string present: Passed is false' (-not $result4.Passed)
Assert-That 'forbidden string failure names the offending relative path' (@($result4.Failures | Where-Object { $_ -like '*public/app.js*' }).Count -gt 0)
Remove-TestTempDirectory -Path $root4

# ============================================================
# 5. required_strings — the real production API URL missing from every
#    scanned file fails; present in at least one file passes.
# ============================================================
$root5 = New-FixtureArtifactRoot
$rules5Missing = [PSCustomObject]@{ required_strings = @('https://sandogh.safhenokhost.ir/api') }
$result5Missing = Test-ArtifactValidation -ArtifactRoot $root5 -ArtifactValidation $rules5Missing
Assert-That 'required string missing from every file: Passed is false' (-not $result5Missing.Passed)

'const API = "https://sandogh.safhenokhost.ir/api";' | Set-Content -LiteralPath (Join-Path $root5 'public\app.js') -Encoding UTF8 -NoNewline
$result5Present = Test-ArtifactValidation -ArtifactRoot $root5 -ArtifactValidation $rules5Missing
Assert-That 'required string present in a scanned file: Passed is true' $result5Present.Passed
Remove-TestTempDirectory -Path $root5

# ============================================================
# 6. Extension filtering — a forbidden string sitting only inside a
#    non-scanned (e.g. binary-ish) extension is never flagged.
# ============================================================
$root6 = New-FixtureArtifactRoot
'http://localhost:3000' | Set-Content -LiteralPath (Join-Path $root6 'public\binary.dat') -Encoding UTF8 -NoNewline
$rules6 = [PSCustomObject]@{ forbidden_strings = @('http://localhost:3000') }
$result6 = Test-ArtifactValidation -ArtifactRoot $root6 -ArtifactValidation $rules6
Assert-That 'forbidden string inside a non-scanned extension is not flagged' $result6.Passed
Remove-TestTempDirectory -Path $root6

# ============================================================
# 7. max_scan_file_size_kb — a file larger than the configured cap is
#    skipped even though it contains a forbidden string.
# ============================================================
$root7 = New-FixtureArtifactRoot
$bigContent = ('x' * 20000) + 'http://localhost:3000'
$bigContent | Set-Content -LiteralPath (Join-Path $root7 'public\big.js') -Encoding UTF8 -NoNewline
$rules7 = [PSCustomObject]@{ forbidden_strings = @('http://localhost:3000'); max_scan_file_size_kb = 1 }
$result7 = Test-ArtifactValidation -ArtifactRoot $root7 -ArtifactValidation $rules7
Assert-That 'a file larger than max_scan_file_size_kb is skipped during scanning' $result7.Passed
Remove-TestTempDirectory -Path $root7

# ============================================================
# 8. A plain hashtable (test-fixture style) rules object works exactly
#    like a PSCustomObject (real config.json via ConvertFrom-Json).
# ============================================================
$root8 = New-FixtureArtifactRoot
Remove-Item -LiteralPath (Join-Path $root8 'artisan') -Force
$rules8 = @{ required_files = @('artisan') }
$result8 = Test-ArtifactValidation -ArtifactRoot $root8 -ArtifactValidation $rules8
Assert-That 'hashtable-form rules object is accepted and evaluated' (-not $result8.Passed)
Remove-TestTempDirectory -Path $root8

# ============================================================
# 9. A real ConvertFrom-Json PSCustomObject (round-tripped through JSON
#    text, exactly how config.json is actually loaded) works too.
# ============================================================
$root9 = New-FixtureArtifactRoot
$json = '{ "required_files": ["missing-file.txt"] }'
$rules9 = $json | ConvertFrom-Json
$result9 = Test-ArtifactValidation -ArtifactRoot $root9 -ArtifactValidation $rules9
Assert-That 'a real ConvertFrom-Json rules object is accepted and evaluated' (-not $result9.Passed)
Assert-That 'the missing file from JSON-sourced rules is named in the failure' (@($result9.Failures | Where-Object { $_ -like '*missing-file.txt*' }).Count -gt 0)
Remove-TestTempDirectory -Path $root9

Write-TestSummaryAndExit -SuiteName 'Test-ArtifactValidation'
