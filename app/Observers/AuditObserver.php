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

    // 1 mã cho CẢ lần thao tác (request) — trước đây mỗi dòng log 1 mã riêng nên không gom
    // được "tạo nhân viên" = hồ sơ + hợp đồng + ca làm... (2026-10-15).
    public static function requestId(): string
    {
        // Queue worker / scheduler sống lâu, dùng chung 1 request() — không gom được theo
        // "lần thao tác", nên mỗi dòng 1 mã như cũ.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return (string) Str::uuid();
        }

        $request = request();
        if (! $request->attributes->has('audit_request_id')) {
            $request->attributes->set('audit_request_id', (string) Str::uuid());
        }

        return $request->attributes->get('audit_request_id');
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
            'request_id' => self::requestId(),
        ]);
    }
}
