<?php

declare(strict_types=1);

namespace App\Services\Sentinel\Readiness\Transport;

/**
 * PowershellAllowedScripts — the complete, closed registry of the
 * PowerShell script texts the Readiness package is permitted to run.
 *
 * Nothing is computed here and nothing is interpolated. Every entry is
 * a fixed literal selected by token from PowershellObservationTransport,
 * so a caller can never introduce its own script source.
 *
 * Every script obeys the same rules:
 *
 *   - It reads caller-supplied values ONLY from its own process
 *     environment via [Environment]::GetEnvironmentVariable(). Those
 *     values are never on the command line and never in the script
 *     text, so no quoting or escaping of them can be defeated.
 *   - It emits one structured line per field as KEY=VALUE on stdout.
 *   - $ErrorActionPreference = 'Stop' makes a failing cmdlet terminate
 *     the script with a nonzero exit code instead of printing a
 *     warning and continuing as if nothing happened.
 *   - WQL filters are built by concatenating a quoted literal, never by
 *     embedding a caller value into the query source.
 *   - There is no Invoke-Expression, no download, no remoting, no
 *     profile use, no execution-policy change, and no cmdlet that
 *     creates, starts, stops or configures anything.
 *
 * These scripts collect information only.
 */
final class PowershellAllowedScripts
{
    public const FILESYSTEM = <<<'PS'
$ErrorActionPreference = 'Stop'
$path = [Environment]::GetEnvironmentVariable('SENTINEL_OBSERVE_PATH')
if ([string]::IsNullOrEmpty($path)) { exit 3 }
$out = @{}
$out['exists'] = '0'
$out['type'] = 'unknown'
$out['filesystem'] = ''
$out['canonicalPath'] = ''
$out['reparsePointAbove'] = '0'
$full = $null
try { $full = [System.IO.Path]::GetFullPath($path) } catch { exit 4 }
$root = [System.IO.Path]::GetPathRoot($full)
$letter = $root.Substring(0, 2)
try { $drive = Get-CimInstance -ClassName Win32_LogicalDisk -Filter ('DeviceID=' + "'" + ($letter -replace "'", "''") + "'") -ErrorAction Stop } catch { exit 5 }
if ($null -eq $drive) { exit 6 }
$out['filesystem'] = [string]$drive.FileSystem
if ($drive.DriveType -eq 3) { $out['type'] = 'fixed' } else { $out['type'] = 'removable' }
if (Test-Path -LiteralPath $full -PathType Container) {
  $out['exists'] = '1'
  $l = Get-Item -LiteralPath $full -Force -ErrorAction Stop
  if ($l.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { $out['reparsePointAbove'] = '1' }
  else {
    $above = '0'
    $parent = [System.IO.Path]::GetDirectoryName($full)
    while (-not [string]::IsNullOrEmpty($parent)) {
      if (Test-Path -LiteralPath $parent -PathType Container) {
        $p = Get-Item -LiteralPath $parent -Force -ErrorAction Stop
        if ($p.Attributes -band [System.IO.FileAttributes]::ReparsePoint) { $above = '1'; break }
      }
      $next = [System.IO.Path]::GetDirectoryName($parent)
      if ((-not $next) -or ($next -eq $parent)) { break }
      $parent = $next
    }
    $out['reparsePointAbove'] = $above
  }
}
elseif (Test-Path -LiteralPath $full) { $out['type'] = 'not_directory' }
$out['canonicalPath'] = $full
foreach ($k in @('exists','type','filesystem','canonicalPath','reparsePointAbove')) {
  Write-Output ($k + '=' + $out[$k])
}
PS;

    public const FREESPACE = <<<'PS'
$ErrorActionPreference = 'Stop'
$path = [Environment]::GetEnvironmentVariable('SENTINEL_OBSERVE_PATH')
if ([string]::IsNullOrEmpty($path)) { exit 3 }
$full = $null
try { $full = [System.IO.Path]::GetFullPath($path) } catch { exit 4 }
$root = [System.IO.Path]::GetPathRoot($full)
$letter = $root.Substring(0, 2)
try { $drive = Get-CimInstance -ClassName Win32_LogicalDisk -Filter ('DeviceID=' + "'" + ($letter -replace "'", "''") + "'") -ErrorAction Stop } catch { exit 5 }
if ($null -eq $drive) { exit 6 }
Write-Output ('bytesFree=' + [string]$drive.FreeSpace)
Write-Output ('bytesTotal=' + [string]$drive.Size)
PS;

    public const SERVICE = <<<'PS'
$ErrorActionPreference = 'Stop'
$name = [Environment]::GetEnvironmentVariable('SENTINEL_OBSERVE_SERVICE')
if ([string]::IsNullOrEmpty($name)) { exit 3 }
try { $svc = Get-CimInstance -ClassName Win32_Service -Filter ('Name=' + "'" + ($name -replace "'", "''") + "'") -ErrorAction Stop } catch { exit 5 }
if ($null -eq $svc) { Write-Output 'present=0'; Write-Output 'state='; exit 0 }
Write-Output 'present=1'
Write-Output ('state=' + [string]$svc.State)
PS;

    public const MODULE = <<<'PS'
$ErrorActionPreference = 'Stop'
$name = [Environment]::GetEnvironmentVariable('SENTINEL_OBSERVE_MODULE')
if ([string]::IsNullOrEmpty($name)) { exit 3 }
$found = $false
try { $found = [bool](Get-Module -ListAvailable -Name $name -ErrorAction Stop) } catch { exit 5 }
if ($found) { Write-Output 'available=1' } else { Write-Output 'available=0' }
PS;

    public const ELEVATION = <<<'PS'
$ErrorActionPreference = 'Stop'
$id = [System.Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object -TypeName System.Security.Principal.WindowsPrincipal($id)
$admin = [System.Security.Principal.WindowsBuiltInRole]::Administrator
if ($principal.IsInRole($admin)) { Write-Output 'elevated=1' } else { Write-Output 'elevated=0' }
PS;

    public const GROUP = <<<'PS'
$ErrorActionPreference = 'Stop'
$name = [Environment]::GetEnvironmentVariable('SENTINEL_OBSERVE_GROUP')
if ([string]::IsNullOrEmpty($name)) { exit 3 }
$who = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$member = $false
try {
  $g = Get-LocalGroup -Name $name -ErrorAction Stop
  $users = Get-LocalGroupMember -Group $g -ErrorAction Stop
  foreach ($u in $users) { if ($u.Name -ieq $who) { $member = $true } }
} catch { exit 5 }
if ($member) { Write-Output 'member=1' } else { Write-Output 'member=0' }
PS;

    /**
     * The token -> script map. A caller selects by token only.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'filesystem' => self::FILESYSTEM,
            'freespace' => self::FREESPACE,
            'service' => self::SERVICE,
            'module' => self::MODULE,
            'elevation' => self::ELEVATION,
            'group' => self::GROUP,
        ];
    }

    /**
     * @throws \InvalidArgumentException when the token is not allowlisted
     */
    public static function resolve(string $token): string
    {
        $all = self::all();

        if (! isset($all[$token])) {
            throw new \InvalidArgumentException(
                'PowershellAllowedScripts refuses an unallowlisted script token.',
            );
        }

        return $all[$token];
    }
}
