<?php

namespace App\Console\Commands;

use App\Services\ProjectService;
use App\Services\ProjectTaskService;
use Illuminate\Console\Command;

// Nhắc mỗi sáng cho module Dự án — công việc (ProjectTaskService::sendReminders: ngày bắt đầu,
// sắp tới hạn, hạn hôm nay, quá hạn, chờ duyệt lâu) và dự án (ProjectService::sendProjectReminders:
// tới ngày bắt đầu, sắp kết thúc, quá hạn kết thúc). Mỗi mốc chỉ nhắc 1 lần.
class RemindProjectTasks extends Command
{
    protected $signature = 'projects:remind-tasks';

    protected $description = 'Nhắc công việc/dự án theo ngày (bắt đầu, sắp hạn, quá hạn, chờ duyệt lâu)';

    public function handle(ProjectTaskService $taskService, ProjectService $projectService): int
    {
        $tasks = $taskService->sendReminders();
        $projects = $projectService->sendProjectReminders();
        $this->info('Công việc: '.json_encode($tasks).' — Dự án: '.json_encode($projects));

        return self::SUCCESS;
    }
}
