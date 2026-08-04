<#
.SYNOPSIS
    The fixed catalog of per-project statuses shown in the Launcher UI,
    with their Persian display text and simple color coding. A single
    source of truth so the GUI layer never hardcodes a status string.
#>

Set-StrictMode -Version Latest

$script:LauncherStatusCatalog = @{
    NoChanges       = @{ Text = 'بدون تغییر';                  Color = 'Gray'   }
    ChangesReady    = @{ Text = 'تغییرات آماده ارسال';         Color = 'Yellow' }
    UnpushedCommit  = @{ Text = 'Commit محلی Push‌نشده';        Color = 'Yellow' }
    Committing      = @{ Text = 'در حال Commit';                Color = 'Blue'   }
    Pushing         = @{ Text = 'در حال Push';                  Color = 'Blue'   }
    PushSucceeded   = @{ Text = 'Push موفق';                    Color = 'Green'  }
    Building        = @{ Text = 'در حال Build';                 Color = 'Blue'   }
    Transferring    = @{ Text = 'در حال انتقال به سرور';        Color = 'Blue'   }
    Activating      = @{ Text = 'در حال فعال‌سازی';             Color = 'Blue'   }
    HealthCheckOk   = @{ Text = 'Health Check موفق';            Color = 'Green'  }
    Completed       = @{ Text = 'عملیات کامل شد';               Color = 'Green'  }
    Failed          = @{ Text = 'ناموفق';                       Color = 'Red'    }
    Checking        = @{ Text = 'در حال بررسی';                 Color = 'Blue'   }
    NotDeployable   = @{ Text = 'نیازمند تکمیل پیکربندی';       Color = 'Yellow' }
}

<#
.OUTPUTS
    Hashtable: @{ Text; Color (a System.Drawing.Color-resolvable name) }
#>
function Get-LauncherStatusInfo {
    param([Parameter(Mandatory = $true)][string]$StatusKey)
    if ($script:LauncherStatusCatalog.ContainsKey($StatusKey)) {
        return $script:LauncherStatusCatalog[$StatusKey]
    }
    return @{ Text = $StatusKey; Color = 'Gray' }
}
