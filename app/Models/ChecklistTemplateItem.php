<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistTemplateItem extends Model
{
    public const RESPONSIBLES = ['hr', 'manager', 'employee'];

    // Việc hệ thống tự đánh dấu xong khi điều kiện đúng (ChecklistService::autoConditionMet()).
    public const AUTO_KEYS = [
        ChecklistTemplate::TYPE_ONBOARDING => ['account_created', 'contract_signed', 'bank_account_verified'],
        ChecklistTemplate::TYPE_OFFBOARDING => ['account_locked', 'contract_ended'],
    ];

    protected $fillable = [
        'checklist_template_id', 'title', 'description', 'responsible',
        'due_offset_days', 'is_required', 'auto_key', 'sort_order',
    ];

    protected $casts = [
        'due_offset_days' => 'integer',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
