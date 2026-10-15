<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

enum MysqlLifecycleState: string
{
    case PREFLIGHT = 'PREFLIGHT';
    case INITIALIZING = 'INITIALIZING';
    case BOOTSTRAPPING_CREDENTIALS = 'BOOTSTRAPPING_CREDENTIALS';
    case STARTING = 'STARTING';
    case READY = 'READY';
    case MIGRATING = 'MIGRATING';
    case STOPPING = 'STOPPING';
    case STOPPED = 'STOPPED';
    case FAILED = 'FAILED';
    case CLEANUP_FAILED = 'CLEANUP_FAILED';
}
