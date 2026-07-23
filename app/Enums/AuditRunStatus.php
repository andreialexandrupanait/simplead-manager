<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a single crawl/ingest run backing an audit (Faza D2).
 */
enum AuditRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
