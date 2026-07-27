<?php

namespace App\Console\Commands;

use App\Services\Bootstrap\DeploymentBootstrapConfig;
use App\Services\Bootstrap\DeploymentBootstrapException;
use App\Services\Bootstrap\DeploymentBootstrapReport;
use App\Services\Bootstrap\DeploymentBootstrapService;
use Illuminate\Console\Command;

/**
 * The one-command "register this install exactly as if an operator had
 * clicked through the dashboard wizards" entry point deployment-bootstrap's
 * install.sh drives. All the actual idempotent find-or-create logic lives
 * in DeploymentBootstrapService — this class only handles the CLI
 * surface: parsing config.env, deciding interactive vs. --non-interactive,
 * and presenting the result in Persian.
 */
class DeploymentBootstrapCommand extends Command
{
    protected $signature = 'deployment:bootstrap
        {--config= : مسیر فایل config.env (الزامی)}
        {--non-interactive : در صورت هر ابهام یا وضعیت خطرناک، بدون سوال با خطا متوقف شو}
        {--admin-email= : ایمیل یک کاربر Admin موجود، فقط برای ثبت در Audit Log}
        {--secrets-output= : مسیر فایلی برای نوشتن Secretهای تازه (فقط هنگام ایجاد اولیه) به‌صورت JSON با Permission 600 — install.sh آن را می‌خواند و بلافاصله حذف می‌کند}';

    protected $description = 'ثبت خودکار Server/Agent/Project/Environment/Deployment Target برای این نصب (idempotent، بدون تغییر رکورد موجود)';

    public function __construct(private readonly DeploymentBootstrapService $bootstrap)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $configPath = $this->option('config');
        if (!$configPath) {
            $this->error('گزینه --config الزامی است. ابتدا config.example.env را به config.env کپی و ویرایش کنید، سپس دوباره با --config=/path/to/config.env اجرا کنید.');

            return self::FAILURE;
        }

        if (!is_file($configPath)) {
            $this->error("فایل تنظیمات پیدا نشد: {$configPath}");

            return self::FAILURE;
        }

        $nonInteractive = (bool) $this->option('non-interactive');

        try {
            $config = DeploymentBootstrapConfig::fromEnvFile($configPath);
        } catch (DeploymentBootstrapException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $gitStatus = $this->bootstrap->checkRepository($config, $nonInteractive);
            if ($gitStatus->exists && !$gitStatus->isClean) {
                $this->warn('Working Tree تغییرات ذخیره‌نشده دارد:');
                foreach ($gitStatus->changedFiles as $file) {
                    $this->line("  - {$file}");
                }
            }

            $report = $this->bootstrap->run($config, $this->option('admin-email') ?: null);
        } catch (DeploymentBootstrapException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            // Anything NOT already a DeploymentBootstrapException is a real
            // bug, not an anticipated validation failure — still caught
            // here (rather than left to crash the process) so the
            // transaction's own rollback completes cleanly and the
            // operator gets a clear Persian message instead of a raw
            // stack trace. Reported to the log either way.
            report($e);
            $this->error('خطای غیرمنتظره در حین Bootstrap: ' . $e->getMessage());
            $this->line('جزئیات در storage/logs/laravel.log ثبت شد.');

            return self::FAILURE;
        }

        $this->printReport($config, $report);

        $secretsOutput = $this->option('secrets-output');
        if ($secretsOutput) {
            $this->writeSecretsFile($secretsOutput, $config, $report);
        }

        return self::SUCCESS;
    }

    private function printReport(DeploymentBootstrapConfig $config, DeploymentBootstrapReport $report): void
    {
        $this->newLine();
        $this->info('=== گزارش Bootstrap ===');

        $this->reportRow('Server', $report->server->name, $report->server->public_id, $report->serverWasCreated);
        $this->reportRow('Agent', $report->agent->name ?? '(بدون نام)', $report->agent->public_id, $report->agentWasCreated);
        $this->reportRow('Project', $report->project->name, $report->project->public_id, $report->projectWasCreated);
        $this->reportRow('Environment', $report->environment->name, (string) $report->environment->id, $report->environmentWasCreated);
        $this->reportRow('Deployment Target', $report->target->name, (string) $report->target->id, $report->targetWasCreated);

        if ($report->configuredDifferences !== []) {
            $this->newLine();
            $this->comment('تغییرات همگام‌سازی‌شده با config.env:');
            foreach ($report->configuredDifferences as $difference) {
                $this->line("  - {$difference}");
            }
        }

        if ($report->notices !== []) {
            $this->newLine();
            $this->comment('نکات:');
            foreach ($report->notices as $notice) {
                $this->line("  - {$notice}");
            }
        }

        $this->newLine();
        $this->info('Webhook URL: ' . $report->webhookUrl);

        if ($report->plainWebhookSecret !== null) {
            $this->warn('Webhook Secret (فقط همین یک‌بار نمایش داده می‌شود): ' . $report->plainWebhookSecret);
            $this->line('این دو مقدار را در GitHub → Settings → Webhooks وارد کنید (Content type: application/json، فقط رویداد push).');
        } else {
            $this->line('این پروژه قبلاً وجود داشت — Secret آن دوباره نمایش داده نمی‌شود؛ در صورت نیاز از پنل Rotate کنید.');
        }

        if ($report->webhookAutoCreated) {
            $this->info('Webhook به‌صورت خودکار در GitHub ساخته شد.');
        } elseif ($report->webhookAutoCreateError !== null) {
            $this->error('ساخت خودکار Webhook ناموفق بود: ' . $report->webhookAutoCreateError);
            $this->line('Webhook را دستی با URL/Secret بالا در GitHub اضافه کنید.');
        }

        if ($report->plainAgentToken !== null) {
            $this->newLine();
            $this->warn('Agent Token و Signing Secret تازه ساخته شدند و مستقیماً در فایل config.php کارگزار (با Permission 600) نوشته می‌شوند — در ترمینال نمایش داده نمی‌شوند.');
        }

        $this->newLine();
        $this->info('اتمام موفق Bootstrap.');
    }

    private function reportRow(string $label, string $name, string $identifier, bool $wasCreated): void
    {
        $status = $wasCreated ? 'ایجاد شد' : 'از قبل موجود بود';
        $this->line("{$label}: {$name} (#{$identifier}) — {$status}");
    }

    private function writeSecretsFile(string $path, DeploymentBootstrapConfig $config, DeploymentBootstrapReport $report): void
    {
        $agentConfig = $this->bootstrap->buildAgentConfigPayload(
            $config,
            $report->server,
            $report->plainAgentToken,
            $report->plainSigningSecret,
        );

        $payload = ['agent_config' => $agentConfig];

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);
    }
}
