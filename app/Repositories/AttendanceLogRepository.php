<?php

namespace App\Repositories;

use App\Models\AttendanceLog;

class AttendanceLogRepository
{
    public function create(array $data): AttendanceLog
    {
        return AttendanceLog::create($data);
    }
}
