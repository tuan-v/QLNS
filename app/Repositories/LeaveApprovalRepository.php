<?php

namespace App\Repositories;

use App\Models\LeaveApproval;

class LeaveApprovalRepository
{
    public function create(array $data): LeaveApproval
    {
        return LeaveApproval::create($data);
    }
}
