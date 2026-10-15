$ErrorActionPreference = 'Stop'
# Read-only byte verification of the old run. Not part of the Sentinel pipeline.
Add-Type -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.IO;
using System.Security.Cryptography;
using System.Threading;
using System.Threading.Tasks;
public sealed class PreservedFileRecord {
    public string Name;
    public long Size;
    public string Hash;
}
public sealed class PreservedRunResult {
    public int expected_files, actual_files, matching_hashes, mismatches, read_errors, unexpected_paths, reparse_points;
    public bool identical_content_and_paths;
}
public static class PreservedRunByteVerifier {
    public static PreservedRunResult Verify(string root, PreservedFileRecord[] records) {
        var result = new PreservedRunResult();
        result.expected_files = records.Length;
        root = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar);
        var expected = new HashSet<string>(StringComparer.Ordinal);
        foreach (var item in records) {
            if (item.Name.Contains("..") || Path.IsPathRooted(item.Name) || item.Name.Contains(":"))
                throw new InvalidOperationException("Invalid fingerprint path");
            expected.Add(item.Name);
        }
        var pending = new Stack<string>(); pending.Push(root);
        while (pending.Count > 0) {
            foreach (var path in Directory.EnumerateFileSystemEntries(pending.Pop())) {
                var attributes = File.GetAttributes(path);
                if ((attributes & FileAttributes.ReparsePoint) != 0) { result.reparse_points++; continue; }
                if ((attributes & FileAttributes.Directory) != 0) { pending.Push(path); continue; }
                result.actual_files++;
                var relative = path.Substring(root.Length + 1).Replace('\\', '/');
                if (!expected.Contains(relative)) result.unexpected_paths++;
            }
        }
        Parallel.ForEach(records, new ParallelOptions { MaxDegreeOfParallelism = 8 }, item => {
            try {
                var path = Path.Combine(root, item.Name.Replace('/', Path.DirectorySeparatorChar));
                using (var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read))
                using (var algorithm = SHA256.Create()) {
                    var size = stream.Length;
                    var hash = BitConverter.ToString(algorithm.ComputeHash(stream)).Replace("-", "").ToLowerInvariant();
                    if (size == item.Size && hash == item.Hash) Interlocked.Increment(ref result.matching_hashes);
                    else Interlocked.Increment(ref result.mismatches);
                }
            } catch { Interlocked.Increment(ref result.read_errors); }
        });
        result.identical_content_and_paths = result.actual_files == result.expected_files
            && result.matching_hashes == result.expected_files && result.mismatches == 0
            && result.read_errors == 0 && result.unexpected_paths == 0 && result.reparse_points == 0;
        return result;
    }
}
'@
$taskRoot = 'C:/Users/Farshad-IT/AppData/Local/Temp/SentinelProof/2f606c65-a4c9-48af-8646-de4ae6f12d49'
$taskBaseline = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'preserved-run-before.json') -Raw | ConvertFrom-Json
$taskRecords = New-Object 'System.Collections.Generic.List[PreservedFileRecord]'
foreach ($taskItem in $taskBaseline.PSObject.Properties) {
    if ($null -ne $taskItem.Value.PSObject.Properties['link'] -or $taskItem.Value.sha256 -notmatch '^[0-9a-f]{64}$') {
        throw 'Unsupported or incomplete baseline; cannot assert preservation.'
    }
    $taskRow = New-Object PreservedFileRecord
    $taskRow.Name = $taskItem.Name; $taskRow.Size = $taskItem.Value.size; $taskRow.Hash = $taskItem.Value.sha256
    $taskRecords.Add($taskRow)
}
$taskResult = [PreservedRunByteVerifier]::Verify($taskRoot, $taskRecords.ToArray())
$taskResult | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $PSScriptRoot 'preserved-run-native-verification.json') -Encoding UTF8
$taskResult | ConvertTo-Json
if (-not $taskResult.identical_content_and_paths) { exit 1 }
