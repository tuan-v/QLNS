<?php

namespace App\Repositories;

use App\Models\Holiday;
use App\Models\HolidayRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class HolidayRepository
{
    public function listBetween(Carbon $from, Carbon $to): Collection
    {
        return Holiday::with('rule')
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('holiday_date')
            ->get();
    }

    // Kể cả ngày đã xóa mềm: HR đã xóa 1 ngày thì lần sinh tự động sau không tạo lại.
    public function datesTakenBetween(Carbon $from, Carbon $to): Collection
    {
        return Holiday::withTrashed()
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());
    }

    public function findGroup(string $groupCode): Collection
    {
        return Holiday::where('group_code', $groupCode)->orderBy('holiday_date')->get();
    }

    public function create(array $data): Holiday
    {
        return Holiday::create($data);
    }

    public function activeRules(): Collection
    {
        return HolidayRule::where('is_active', true)->orderBy('month')->orderBy('day')->get();
    }

    public function allRules(): Collection
    {
        return HolidayRule::orderByDesc('is_system')->orderBy('month')->orderBy('day')->get();
    }
}
