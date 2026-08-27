<?php

namespace App\Models\Concerns;

use App\Observers\AuditObserver;

trait Auditable
{
    protected static function booted(): void
    {
        static::observe(AuditObserver::class);
    }
}
