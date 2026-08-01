<?php

/**
 * Phase 4-J: templates for Notification title/message text.
 *
 * Deliberately rendered with an explicit 'fa' locale at creation time
 * (NotificationEventSubscriber), not the ambient app()->getLocale() —
 * several triggering events (agent poll/register, scheduled console
 * commands) run outside the 'web' middleware group, where
 * SetDashboardLocale never runs and config('app.locale') stays the
 * default 'en'. Every real user of this dashboard only ever sees it in
 * Persian (SetDashboardLocale sets 'fa' unconditionally for every web
 * request, for every user — there is no per-user locale setting to
 * respect), so notification text is always generated in Persian
 * regardless of what triggered it.
 */
return [
    'deployment_started_title' => 'استقرار آغاز شد',
    'deployment_started_message' => 'استقرار :label برای پروژه :project آغاز شد.',
    'deployment_success_title' => 'استقرار موفق',
    'deployment_success_message' => 'استقرار :label برای پروژه :project با موفقیت انجام شد.',
    'deployment_failed_title' => 'استقرار ناموفق',
    'deployment_failed_message' => 'استقرار :label برای پروژه :project ناموفق بود.',
    'deployment_rollback_title' => 'بازگشت انجام شد',
    'deployment_rollback_message' => 'بازگشت به :label برای پروژه :project انجام شد.',
    'rollback_started_title' => 'بازگشت آغاز شد',
    'rollback_started_message' => 'بازگشت به :label برای پروژه :project آغاز شد.',
    'rollback_failed_title' => 'بازگشت ناموفق',
    'rollback_failed_message' => 'بازگشت به :label برای پروژه :project ناموفق بود.',

    'agent_approval_required_title' => 'کارگزار نیازمند تایید',
    'agent_approval_required_message' => 'کارگزار جدید «:name» روی سرور :server نیازمند تایید است.',
    'agent_connected_title' => 'کارگزار متصل شد',
    'agent_connected_message' => 'کارگزار «:name» روی سرور :server تایید و متصل شد.',
    'agent_offline_title' => 'کارگزار آفلاین شد',
    'agent_offline_message' => 'کارگزار «:name» روی سرور :server دیگر پاسخ نمی‌دهد.',

    'github_connected_title' => 'اتصال گیت‌هاب برقرار شد',
    'github_connected_message' => 'حساب گیت‌هاب «:login» با موفقیت متصل شد.',
    'github_failed_title' => 'اتصال گیت‌هاب ناموفق',
    'github_failed_message' => 'بررسی اتصال حساب گیت‌هاب «:login» ناموفق بود.',

    'permission_changed_title' => 'تغییر نقش کاربر',
    'permission_changed_message' => 'نقش کاربر :target از :from به :to تغییر کرد.',
    'credential_rotated_title' => 'اعتبارنامه سرور تغییر کرد',
    'credential_rotated_message' => 'اعتبارنامه سرور :server توسط :actor چرخانده شد.',

    'operation_started_title' => 'عملیات شروع شد',
    'operation_started_message' => 'عملیات :operation روی سرور :server آغاز شد.',
    'operation_success_title' => 'عملیات موفق',
    'operation_success_message' => 'عملیات :operation روی سرور :server با موفقیت انجام شد.',
    'operation_failed_title' => 'عملیات ناموفق',
    'operation_failed_message' => 'عملیات :operation روی سرور :server ناموفق بود.',

    'local_agent_approval_required_title' => 'کارگزار محلی نیازمند تایید',
    'local_agent_approval_required_message' => 'کارگزار محلی جدید «:name» نیازمند تایید است.',
    'publish_awaiting_approval_title' => 'انتشار منتظر تایید',
    'publish_awaiting_approval_message' => 'انتشار پروژه :project با :count فایل تغییریافته منتظر تایید شماست.',
    'publish_success_title' => 'انتشار موفق',
    'publish_success_message' => ':action پروژه :project با موفقیت انجام شد.',
    'publish_failed_title' => 'انتشار ناموفق',
    'publish_failed_message' => ':action پروژه :project ناموفق بود.',
    'publish_abandoned_title' => 'انتشار رهاشده',
    'publish_abandoned_message' => ':action پروژه :project رهاشده است — عامل محلی وسط اجرا پاسخ ندادن را متوقف کرد و هرگز نتیجه را گزارش نکرد.',

    'change_detection_awaiting_approval_title' => 'تغییر محلی منتظر تایید',
    'change_detection_awaiting_approval_message' => ':count فایل در پروژه :project تغییر کرده و آماده انتشار است.',
];
