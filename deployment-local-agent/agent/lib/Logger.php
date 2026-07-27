<?php

declare(strict_types=1);

namespace LocalAgent;

final class Logger
{
    private string $logFile;

    public function __construct(string $logDir)
    {
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        $this->logFile = rtrim($logDir, '/\\') . '/local-agent-' . date('Y-m-d') . '.log';
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf('[%s] %s %s%s', date('c'), $level, $message, PHP_EOL);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        fwrite($level === 'ERROR' ? STDERR : STDOUT, $line);
    }
}
