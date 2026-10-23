<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunBackup;
use App\Services\BackupService;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Mục "Sao lưu dữ liệu" của trang Cài đặt hệ thống (quyền system.backup — Admin). Khôi phục chỉ làm bằng lệnh.
class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly LogService $logService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->backupService->list(),
            'schedule' => ['time' => config('backup.time'), 'keep' => config('backup.keep')],
        ]);
    }

    // Chạy nền qua queue — xong sẽ có thông báo "backup.completed" (hoặc "backup.failed").
    public function store(Request $request): JsonResponse
    {
        RunBackup::dispatch($request->user()->id);

        return response()->json(['message' => 'Đang sao lưu dữ liệu, bạn sẽ nhận thông báo khi xong.'], 202);
    }

    public function download(Request $request, string $file): BinaryFileResponse
    {
        $path = $this->backupService->path($file);
        $this->logService->recordBackupEvent('backup_downloaded', $request->user(), $file);

        return response()->download($path, $file, ['Content-Type' => 'application/zip']);
    }
}
