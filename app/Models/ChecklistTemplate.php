<?php

namespace App\Models;

use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Mẫu checklist Onboarding/Offboarding do HR soạn — xem ChecklistService.
class ChecklistTemplate extends Model
{
    use BroadcastsChanges;

    public const TYPE_ONBOARDING = 'onboarding';

    public const TYPE_OFFBOARDING = 'offboarding';

    public const TYPES = [self::TYPE_ONBOARDING, self::TYPE_OFFBOARDING];

    protected array $realtimeShared = ['onboarding'];

    protected $fillable = ['type', 'name', 'is_default', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
