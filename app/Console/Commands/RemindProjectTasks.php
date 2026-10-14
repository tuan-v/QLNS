<?php

namespace App\Console\Commands;

use App\Services\ProjectTaskService;
use Illuminate\Console\Command;

// Nhắc công việc dự án: còn 1 ngày tới hạn -> nhắc người làm; quá hạn -> báo người làm + Lead.
// Mỗi mốc chỉ nhắc 1 lần (ProjectTaskService::sendReminders).
class RemindProjectTasks extends Command
{
    protected $signature = 'projects:remind-tasks';

    protected $description = 'Nhắc công việc dự án sắp tới hạn / đã quá hạn';

    public function handle(ProjectTaskService $taskService): int
    {
        $result = $taskService->sendReminders();
        $this->info("Đã nhắc {$result['due_soon']} việc sắp tới hạn, {$result['overdue']} việc quá hạn.");

        return self::SUCCESS;
    }
}
