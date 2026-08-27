<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditObserver
{
    public function created(Model $model): void
    {
        $this->log('created', $model, null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->log('updated', $model, $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, $model->getAttributes(), null);
    }

    private function log(string $action, Model $model, ?array $oldData, ?array $newData): void
    {
        AuditLog::create([
            'action' => $action,
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'old_data' => $oldData,
            'new_data' => $newData,
            'user_id' => request()->user()?->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'http_method' => request()->method(),
            'url' => request()->fullUrl(),
            'request_id' => (string) Str::uuid(),
        ]);
    }
}
