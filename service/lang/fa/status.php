<?php

return [
    // Deployment::STATUS_* / DeploymentTask::STATUS_* / AgentJob::STATUS_*
    'pending_approval' => 'در انتظار تایید',
    'queued' => 'در صف اجرا',
    'running' => 'در حال اجرا',
    'succeeded' => 'موفق',
    'failed' => 'ناموفق',
    'cancelled' => 'لغو شده',
    'partial_success' => 'موفقیت جزئی',
    'skipped' => 'رد شده',
    'awaiting_approval' => 'منتظر تایید',
    // PublishJob::STATUS_* (اصلاح ریشه‌ای: وضعیت‌های lease/بازیابی)
    'timed_out' => 'اتمام زمان',
    'abandoned' => 'رهاشده پس از قطع عامل',
    'awaiting_recovery' => 'در انتظار بازیابی',

    // Agent::STATUS_*
    'approved' => 'تایید شده',
    'rejected' => 'رد شده',
    'disabled' => 'غیرفعال',

    // LocalChangeDetectionJob::STATUS_*
    'detected' => 'تشخیص داده شده',
    'waiting_approval' => 'منتظر تایید',
    'published' => 'منتشر شده',
    'ignored' => 'نادیده گرفته شده',

    // Health check status
    'pending' => 'در انتظار',
    'success' => 'موفق',

    // Generic words reused across pages
    'online' => 'آنلاین',
    'offline' => 'آفلاین',
    'active' => 'فعال',
    'never' => 'هرگز',
    'yes' => 'بله',
    'no' => 'خیر',

    // Phase 4-L Readiness page
    'connected' => 'متصل',
    'not_connected' => 'متصل نیست',
    'ready' => 'آماده',
    'not_ready' => 'آماده نیست',
    'configured' => 'پیکربندی شده',
    'not_configured' => 'پیکربندی نشده',
    'no_agent' => 'بدون کارگزار',
];
