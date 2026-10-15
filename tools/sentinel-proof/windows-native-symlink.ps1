param([Parameter(Mandatory=$true)][string]$LinkPath,
      [Parameter(Mandatory=$true)][string]$TargetPath,
      [Parameter(Mandatory=$true)][string]$RunRoot)
$ErrorActionPreference = 'Stop'
try {
    Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
using System.Text;
public static class ProofNativeLink {
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    [return: MarshalAs(UnmanagedType.U1)]
    public static extern bool CreateSymbolicLinkW(string link, string target, uint flags);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern SafeFileHandle CreateFileW(string path, uint access, uint share, IntPtr security, uint disposition, uint flags, IntPtr template);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    static extern uint GetFinalPathNameByHandleW(SafeFileHandle handle, StringBuilder path, uint length, uint flags);
    [DllImport("kernel32.dll", SetLastError=true)]
    static extern bool DeviceIoControl(SafeFileHandle handle, uint code, IntPtr input, uint inputSize, byte[] output, uint size, out uint returned, IntPtr overlapped);
    public static string Final(string path) {
        using (var h = CreateFileW(path, 0, 7, IntPtr.Zero, 3, 0x02000000, IntPtr.Zero)) {
            if (h.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error());
            var buffer = new StringBuilder(32768);
            uint length = GetFinalPathNameByHandleW(h, buffer, 32768, 0);
            if (length == 0 || length >= 32768) throw new Win32Exception(Marshal.GetLastWin32Error());
            return buffer.ToString();
        }
    }
    public static uint Tag(string path) {
        using (var h = CreateFileW(path, 0, 7, IntPtr.Zero, 3, 0x02200000, IntPtr.Zero)) {
            if (h.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error());
            byte[] data = new byte[16384]; uint returned;
            if (!DeviceIoControl(h, 0x000900A8, IntPtr.Zero, 0, data, (uint)data.Length, out returned, IntPtr.Zero))
                throw new Win32Exception(Marshal.GetLastWin32Error());
            if (returned < 8) throw new InvalidOperationException("Invalid reparse data");
            return BitConverter.ToUInt32(data, 0);
        }
    }
}
'@
    function Normalize([string]$Path) {
        if ($Path -match '(^|[\\/])\.\.?([\\/]|$)' -or $Path.Contains([char]0) -or $Path -notmatch '^[A-Za-z]:[\\/]') { throw 'Noncanonical path rejected' }
        if ($Path.Substring(2).Contains(':')) { throw 'Alternate stream rejected' }
        return [IO.Path]::GetFullPath($Path).TrimEnd('\')
    }
    $root = Normalize $RunRoot
    $target = Normalize $TargetPath
    $link = Normalize $LinkPath
    function Contained([string]$Path, [bool]$AllowRoot = $false) {
        if ($AllowRoot -and $Path.Equals($root, [StringComparison]::OrdinalIgnoreCase)) { return }
        if (!$Path.StartsWith($root + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Outside run root rejected' }
    }
    function NoReparse([string]$Path) {
        $cursor = $Path
        while ($cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse input rejected' }
            $cursor = [IO.Path]::GetDirectoryName($cursor)
        }
    }
    Contained $target
    Contained $link
    $parent = [IO.Path]::GetDirectoryName($link)
    Contained $parent $true
    NoReparse $root
    NoReparse $target
    NoReparse $parent
    if (!(Test-Path -LiteralPath $root -PathType Container) -or !(Test-Path -LiteralPath $parent -PathType Container)) { throw 'Directory missing' }
    if (Get-Item -LiteralPath $link -Force -ErrorAction SilentlyContinue) { throw 'Existing link or copy rejected' }
    $expected = [ProofNativeLink]::Final($target)
    $canonicalRoot = [ProofNativeLink]::Final($root).TrimEnd('\')
    if (!$expected.StartsWith($canonicalRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Canonical escape rejected' }
    $flags = [uint32]2 # Developer Mode: ALLOW_UNPRIVILEGED_CREATE. No privileged retry.
    if (Test-Path -LiteralPath $target -PathType Container) { $flags = $flags -bor 1 }
    if (![ProofNativeLink]::CreateSymbolicLinkW($link, $target, $flags)) { throw [ComponentModel.Win32Exception]::new([Runtime.InteropServices.Marshal]::GetLastWin32Error()) }
    $item = Get-Item -LiteralPath $link -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0 -or [ProofNativeLink]::Tag($link) -ne [uint32]2684354572) { throw 'Native symlink tag required; junction/copy rejected' }
    if (![ProofNativeLink]::Final($link).Equals($expected, [StringComparison]::OrdinalIgnoreCase)) { throw 'Native target mismatch' }
    Write-Output 'NATIVE_SYMLINK_VERIFIED'
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
