<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

class ProjectMember extends Model
{
    use Auditable;
    use BroadcastsChanges;

    protected array $realtimeShared = ['projects'];

    public const ROLE_LEAD = 'lead';

    public const ROLE_MEMBER = 'member';

    protected $fillable = ['project_id', 'employee_id', 'role', 'added_by'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
