<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Models\User;
use App\Repositories\HolidayRepository;
use App\Support\Realtime;
use App\Support\LunarCalendar;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Ngày nghỉ lễ theo mô hình BÁN TỰ ĐỘNG:
//   1. Hệ thống tự tính theo quy tắc lặp hằng năm (dương lịch hoặc âm lịch —
//      LunarCalendar tự viết, không thư viện ngoài), tự nghỉ bù khi lễ trùng cuối
//      tuần, tự chọn ngày liền kề nối với cuối tuần (Quốc khánh).
//   2. Nhà nước công bố lịch chính thức từng năm bằng văn bản (không có API), nên
//      HR đối chiếu, điều chỉnh (thêm/bớt ngày, hoán đổi ngày làm việc) rồi XÁC
//      NHẬN. Hệ thống tự nhắc HR trước mỗi dịp chưa xác nhận.
//   3. Chỉ dịp ĐÃ xác nhận mới tự gửi thông báo cho nhân viên (holidays:notify).
// Dịp đã xác nhận hoặc đã được HR điều chỉnh thì việc tính tự động không đụng vào.
//
// Các ngày cùng một dịp chung group_code (rule-{id}-{năm} hoặc manual-{chuỗi}).
class HolidayService
{
    public const DEFAULT_NOTIFY_DAYS_BEFORE = 7;

    public const DEFAULT_CONFIRM_REMIND_DAYS_BEFORE = 45;

    private const WEEKDAYS = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];

    // Ngày HR đã can thiệp: dịp chứa các ngày này coi như đã điều chỉnh tay.
    private const HR_SOURCES = [Holiday::SOURCE_ADJUSTED, Holiday::SOURCE_SWAP];

    public function __construct(
        private readonly HolidayRepository $holidayRepository,
        private readonly NotificationService $notificationService,
    ) {
    }

    /* --------------------------------- Danh sách --------------------------------- */

    /** Các dịp nghỉ trong năm, mỗi phần tử là một dịp gồm nhiều ngày. */
    public function listForYear(int $year): array
    {
        $holidays = $this->holidayRepository->listBetween(
            Carbon::create($year, 1, 1),
            Carbon::create($year, 12, 31),
        );

        return $holidays
            ->groupBy(fn (Holiday $holiday) => $this->groupKey($holiday))
            ->map(fn (Collection $days, string $code) => $this->presentGroup($code, $days))
            ->sortBy('start_date')
            ->values()
            ->all();
    }

    public function showGroup(string $groupCode): array
    {
        return $this->presentGroup($groupCode, $this->groupDays($groupCode));
    }

    private function groupKey(Holiday $holiday): string
    {
        return $holiday->group_code ?? 'single-'.$holiday->id;
    }

    private function groupDays(string $groupCode): Collection
    {
        $days = $this->holidayRepository->findGroup($groupCode);
        if ($days->isEmpty()) {
            abort(404);
        }

        return $days;
    }

    private function presentGroup(string $code, Collection $days): array
    {
        $first = $days->first();
        $last = $days->last();
        $rule = $days->first(fn (Holiday $h) => $h->rule !== null)?->rule;
        $start = $first->holiday_date->copy();
        $notifyDaysBefore = $rule?->notify_days_before ?? self::DEFAULT_NOTIFY_DAYS_BEFORE;
        $remindDaysBefore = $rule?->confirm_remind_days_before ?? self::DEFAULT_CONFIRM_REMIND_DAYS_BEFORE;
        $confirmed = $days->every(fn (Holiday $h) => $h->confirmed_at !== null);
        $mainDay = $days->first(fn (Holiday $h) => in_array($h->source, [Holiday::SOURCE_RULE, Holiday::SOURCE_MANUAL], true)) ?? $first;

        return [
            'group_code' => $code,
            'name' => $mainDay->name,
            'source' => $rule ? 'rule' : 'manual',
            'calendar' => $rule?->calendar ?? HolidayRule::CALENDAR_SOLAR,
            'rule_id' => $rule?->id,
            'is_paid' => (bool) $first->is_paid,
            'intern_paid' => (bool) $first->intern_paid,
            'start_date' => $start->toDateString(),
            'end_date' => $last->holiday_date->toDateString(),
            'total_days' => $days->count(),
            'weekday_days' => $days->filter(fn (Holiday $h) => ! $h->holiday_date->isWeekend())->count(),
            'return_date' => $this->returnDateAfter($last->holiday_date)->toDateString(),
            'notify_on' => $start->copy()->subDays($notifyDaysBefore)->toDateString(),
            'remind_on' => $start->copy()->subDays($remindDaysBefore)->toDateString(),
            'notified_at' => $days->max('notified_at')?->toDateTimeString(),
            'confirmed' => $confirmed,
            'confirmed_at' => $confirmed ? $days->max('confirmed_at')?->toDateTimeString() : null,
            'adjusted' => $days->contains(fn (Holiday $h) => in_array($h->source, self::HR_SOURCES, true)),
            'makeup_days' => $days->whereNotNull('makeup_date')
                ->map(fn (Holiday $h) => [
                    'date' => $h->makeup_date->toDateString(),
                    'weekday' => self::WEEKDAYS[$h->makeup_date->dayOfWeek],
                    'replaces' => $h->holiday_date->toDateString(),
                ])->values()->all(),
            'note' => $first->note,
            'days' => $days->map(fn (Holiday $h) => [
                'id' => $h->id,
                'date' => $h->holiday_date->toDateString(),
                'weekday' => self::WEEKDAYS[$h->holiday_date->dayOfWeek],
                'name' => $h->name,
                'source' => $h->source,
                'makeup_date' => $h->makeup_date?->toDateString(),
                'lunar_label' => LunarCalendar::label($h->holiday_date),
            ])->values()->all(),
        ];
    }

    /* ----------------------------- Sinh theo quy tắc ----------------------------- */

    /**
     * Sinh/đồng bộ ngày nghỉ của năm theo các quy tắc đang bật. Chạy lại bao nhiêu
     * lần cũng được: không tạo trùng, không tạo lại ngày HR đã xóa, chỉ gỡ ngày TỪ
     * HÔM NAY TRỞ ĐI không còn khớp quy tắc, và KHÔNG đụng dịp đã xác nhận hoặc đã
     * được HR điều chỉnh.
     *
     * @return array{created: int, removed: int}
     */
    public function generateForYear(int $year): array
    {
        return DB::transaction(function () use ($year) {
            $plan = $this->planYear($year);
            $today = now()->toDateString();
            $created = 0;
            $removed = 0;

            // Ngày của quy tắc đã bị tắt/xóa trong năm này (từ hôm nay trở đi) thì gỡ.
            $staleGroups = Holiday::whereNotNull('holiday_rule_id')
                ->whereYear('holiday_date', $year)
                ->whereNotIn('group_code', array_keys($plan))
                ->where('holiday_date', '>=', $today)
                ->whereNull('confirmed_at')
                ->get();
            foreach ($staleGroups as $holiday) {
                $holiday->forceDelete();
                $removed++;
            }

            foreach ($plan as $groupCode => $group) {
                $existing = Holiday::withTrashed()->where('group_code', $groupCode)->get();

                if ($this->isLocked($existing)) {
                    continue; // HR đã xác nhận/điều chỉnh theo lịch chính thức — giữ nguyên.
                }

                $existing = $existing->keyBy(fn (Holiday $h) => $h->holiday_date->toDateString());

                foreach ($existing as $date => $holiday) {
                    if (! isset($group['dates'][$date]) && ! $holiday->trashed() && $date >= $today) {
                        $holiday->forceDelete();
                        $removed++;
                    }
                }

                foreach ($group['dates'] as $date => $entry) {
                    if ($existing->has($date)) {
                        continue; // Đã có (hoặc HR đã xóa ngày này) — giữ nguyên.
                    }

                    // Ngày đã qua của một dịp đã có trong DB: coi như đã chốt — không
                    // thêm lại. Chỉ sinh ngày quá khứ khi dịp đó chưa từng được sinh.
                    if ($date < $today && $existing->isNotEmpty()) {
                        continue;
                    }

                    $this->holidayRepository->create([
                        'holiday_rule_id' => $group['rule']->id,
                        'group_code' => $groupCode,
                        'source' => $entry['source'],
                        'holiday_date' => $date,
                        'name' => $entry['name'],
                        'is_paid' => $group['rule']->is_paid,
                        'intern_paid' => $group['rule']->intern_paid,
                    ]);
                    $created++;
                }
            }

            return ['created' => $created, 'removed' => $removed];
        });
    }

    private function isLocked(Collection $days): bool
    {
        return $days->contains(fn (Holiday $h) => $h->confirmed_at !== null
            || in_array($h->source, self::HR_SOURCES, true));
    }

    /**
     * Kế hoạch ngày nghỉ của năm: group_code => ['rule' => HolidayRule, 'dates' => [Y-m-d => [name, source]]].
     */
    private function planYear(int $year): array
    {
        $rules = $this->holidayRepository->activeRules();
        $plan = [];

        foreach ($rules as $rule) {
            $start = $this->ruleStartDate($rule, $year);
            // Quy tắc chỉ áp dụng từ ngày có hiệu lực (vd Ngày Văn hóa Việt Nam từ 1/7/2026).
            if ($start === null || ($rule->effective_from && $start->lt($rule->effective_from))) {
                continue;
            }

            $dates = [];
            for ($i = 0; $i < $rule->duration_days; $i++) {
                $dates[$start->copy()->addDays($i)->toDateString()] = [
                    'name' => $rule->name,
                    'source' => Holiday::SOURCE_RULE,
                ];
            }

            $plan["rule-{$rule->id}-{$year}"] = ['rule' => $rule, 'dates' => $dates];
        }

        // Ngày đã bị chiếm bởi dịp KHÁC (HR tự thêm, ...) — quy tắc không ghi đè lên.
        $otherDates = Holiday::withTrashed()
            ->whereBetween('holiday_date', [Carbon::create($year, 1, 1)->toDateString(), Carbon::create($year + 1, 1, 31)->toDateString()])
            ->where(fn ($q) => $q->whereNull('group_code')->orWhereNotIn('group_code', array_keys($plan)))
            ->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        foreach ($plan as $code => $group) {
            $plan[$code]['dates'] = array_diff_key($group['dates'], $otherDates->all());
        }

        // Nghỉ bù (Điều 112 khoản 3): ngày lễ trùng Thứ 7/CN được nghỉ bù vào ngày
        // làm việc kế tiếp — bỏ qua cuối tuần và ngày đã là ngày nghỉ khác.
        $taken = $otherDates->all();
        foreach ($plan as $group) {
            $taken += array_fill_keys(array_keys($group['dates']), true);
        }

        foreach ($plan as $code => $group) {
            if (! $group['rule']->compensate_weekend || $group['dates'] === []) {
                continue;
            }

            $weekendCount = collect(array_keys($group['dates']))
                ->filter(fn (string $date) => Carbon::parse($date)->isWeekend())
                ->count();
            $cursor = Carbon::parse(array_key_last($group['dates']));

            for ($i = 0; $i < $weekendCount; $i++) {
                do {
                    $cursor->addDay();
                } while ($cursor->isWeekend() || isset($taken[$cursor->toDateString()]));

                $date = $cursor->toDateString();
                $taken[$date] = true;
                $plan[$code]['dates'][$date] = [
                    'name' => "Nghỉ bù {$group['rule']->name}",
                    'source' => Holiday::SOURCE_COMPENSATORY,
                ];
            }
        }

        return $plan;
    }

    private function ruleStartDate(HolidayRule $rule, int $year): ?Carbon
    {
        if ($rule->calendar === HolidayRule::CALENDAR_LUNAR) {
            // Ngày âm của năm âm lịch Y có thể rơi vào năm dương Y hoặc Y+1 (tháng 11-12
            // âm); lấy đúng ngày rơi vào năm dương đang sinh.
            foreach ([$year, $year - 1] as $lunarYear) {
                $base = LunarCalendar::toSolar($rule->day, $rule->month, $lunarYear);
                $start = $base?->addDays($this->offsetFor($rule, $base));
                if ($start !== null && (int) $start->year === $year) {
                    return $start;
                }
            }

            return null;
        }

        if (! checkdate($rule->month, $rule->day, $year)) {
            return null; // vd 29/2 năm không nhuận.
        }

        $base = Carbon::create($year, $rule->month, $rule->day);

        return $base->copy()->addDays($this->offsetFor($rule, $base));
    }

    // Tự chọn ngày liền kề (quy tắc 2 ngày: ngày gốc + 1 ngày trước hoặc sau) sao
    // cho nối với cuối tuần: ngày gốc rơi Thứ 3/Thứ 6/Thứ 7 thì nghỉ thêm ngày TRƯỚC,
    // Thứ 2/Thứ 5/CN thì ngày SAU; Thứ 4 không nối được cuối tuần, mặc định ngày
    // trước (khớp lịch Quốc khánh 2026: nghỉ 1/9-2/9). Khớp lịch thật 2024 (2-3/9)
    // và 2025 (1-2/9). HR vẫn đối chiếu thông báo chính thức rồi xác nhận.
    private function offsetFor(HolidayRule $rule, Carbon $base): int
    {
        if (! $rule->auto_adjacent || $rule->duration_days !== 2) {
            return $rule->offset_days;
        }

        return in_array($base->dayOfWeekIso, [2, 3, 5, 6], true) ? -1 : 0;
    }

    /* ------------------------------ HR thêm / xóa ------------------------------ */

    public function createManual(array $data, User $actor): array
    {
        $start = $this->resolveStartDate($data);
        $duration = (int) ($data['duration_days'] ?? 1);
        $dates = collect(range(0, $duration - 1))->map(fn (int $i) => $start->copy()->addDays($i)->toDateString());

        $this->assertDatesFree($dates, 'start_date');

        $groupCode = 'manual-'.Str::lower(Str::random(12));

        DB::transaction(function () use ($dates, $groupCode, $data, $actor) {
            foreach ($dates as $date) {
                $this->holidayRepository->create([
                    'group_code' => $groupCode,
                    'source' => Holiday::SOURCE_MANUAL,
                    'holiday_date' => $date,
                    'name' => $data['name'],
                    'is_paid' => $data['is_paid'] ?? true,
                    'intern_paid' => $data['intern_paid'] ?? true,
                    'note' => $data['note'] ?? null,
                    // HR tự nhập nên đã là lịch chính thức của công ty.
                    'confirmed_at' => now(),
                    'confirmed_by' => $actor->id,
                ]);
            }
        });

        return $this->showGroup($groupCode);
    }

    // Xóa cả một dịp. Xóa mềm để lần sinh tự động sau không tạo lại dịp HR đã bỏ.
    public function deleteGroup(string $groupCode): void
    {
        $this->groupDays($groupCode)->each->delete();
    }

    /* ------------------------- HR điều chỉnh & xác nhận ------------------------- */

    // Bật/tắt "thực tập sinh được hưởng lương" cho cả 1 dịp nghỉ (mọi ngày trong dịp).
    // Không đổi lịch nghỉ nên không ảnh hưởng trạng thái xác nhận.
    public function setInternPaid(string $groupCode, bool $internPaid): array
    {
        $this->groupDays($groupCode);
        Holiday::where('group_code', $groupCode)->update(['intern_paid' => $internPaid]);
        // update() hàng loạt không phát event Eloquent -> báo realtime tay.
        Realtime::shared('holidays');

        return $this->showGroup($groupCode);
    }

    public function confirmGroup(string $groupCode, User $actor, bool $confirmed = true): array
    {
        $days = $this->groupDays($groupCode);

        Holiday::whereIn('id', $days->pluck('id'))->update([
            'confirmed_at' => $confirmed ? now() : null,
            'confirmed_by' => $confirmed ? $actor->id : null,
        ]);
        Realtime::shared('holidays');

        return $this->showGroup($groupCode);
    }

    /**
     * Áp dụng MỘT LẦN mọi chỉnh sửa HR soạn trong dialog "Điều chỉnh" (bỏ ngày, thêm
     * ngày, hoán đổi) rồi xác nhận lịch — chỉ chạy khi HR bấm "Lưu & xác nhận". Trong
     * một transaction: lỗi ở bất kỳ thay đổi nào thì không lưu gì (tránh lưu dở dang
     * khi HR bấm nhầm).
     *
     * @param  array{remove_ids?: int[], add_dates?: string[], swaps?: array<int, array{off_date: string, makeup_date: string}>}  $changes
     */
    public function applyChanges(string $groupCode, array $changes, User $actor): array
    {
        $days = $this->groupDays($groupCode);

        return DB::transaction(function () use ($groupCode, $changes, $actor, $days) {
            foreach ($changes['remove_ids'] ?? [] as $id) {
                $holiday = $days->firstWhere('id', (int) $id);
                if ($holiday === null) {
                    throw ValidationException::withMessages(['remove_ids' => 'Ngày cần bỏ không thuộc dịp nghỉ này.']);
                }
                $holiday->delete();
            }

            foreach ($changes['add_dates'] ?? [] as $date) {
                $this->addDayToGroup($groupCode, $date, null, 'add_dates');
            }

            foreach ($changes['swaps'] ?? [] as $swap) {
                $this->addSwap($groupCode, $swap['off_date'], $swap['makeup_date']);
            }

            if (Holiday::where('group_code', $groupCode)->doesntExist()) {
                return ['group_code' => $groupCode, 'deleted' => true];
            }

            if (($changes['remove_ids'] ?? []) !== []) {
                $this->markChanged($groupCode);
            }

            return $this->confirmGroup($groupCode, $actor, true);
        });
    }

    // Thêm 1 ngày nghỉ vào dịp (vd thông báo chính thức nghỉ thêm 1 ngày).
    public function addDayToGroup(string $groupCode, string $date, ?string $name = null, string $errorField = 'date'): array
    {
        $days = $this->groupDays($groupCode);
        $date = Carbon::parse($date)->toDateString();
        $this->assertDatesFree(collect([$date]), $errorField);

        $first = $days->first();
        $this->holidayRepository->create([
            'holiday_rule_id' => $first->holiday_rule_id,
            'group_code' => $groupCode,
            'source' => $first->holiday_rule_id ? Holiday::SOURCE_ADJUSTED : Holiday::SOURCE_MANUAL,
            'holiday_date' => $date,
            'name' => $name ?: $first->name,
            'is_paid' => $first->is_paid,
            'intern_paid' => $first->intern_paid,
            'note' => $first->note,
        ]);
        $this->markChanged($groupCode);

        return $this->showGroup($groupCode);
    }

    /**
     * Hoán đổi ngày làm việc: nghỉ ngày thường $offDate (thành ngày nghỉ của dịp),
     * đi làm bù vào $makeupDate (Thứ 7/CN). Ngày làm bù được chấm công như chính
     * ngày thường đã nghỉ (Holiday::effectiveWeekdayIso) và được cộng lại vào ngày
     * công chuẩn (PayrollService::standardWorkDaysFor).
     */
    public function addSwap(string $groupCode, string $offDate, string $makeupDate): array
    {
        $days = $this->groupDays($groupCode);
        $off = Carbon::parse($offDate)->startOfDay();
        $makeup = Carbon::parse($makeupDate)->startOfDay();

        if ($off->isWeekend()) {
            throw ValidationException::withMessages(['off_date' => 'Ngày nghỉ hoán đổi phải là ngày làm việc (Thứ 2 – Thứ 6).']);
        }
        if (! $makeup->isWeekend()) {
            throw ValidationException::withMessages(['makeup_date' => 'Ngày làm bù phải là Thứ 7 hoặc Chủ nhật.']);
        }
        $this->assertDatesFree(collect([$off->toDateString()]), 'off_date');
        if (Holiday::where('holiday_date', $makeup->toDateString())->orWhere('makeup_date', $makeup->toDateString())->exists()) {
            throw ValidationException::withMessages(['makeup_date' => 'Ngày làm bù trùng một ngày nghỉ hoặc ngày làm bù khác.']);
        }

        $first = $days->first();
        $this->holidayRepository->create([
            'holiday_rule_id' => $first->holiday_rule_id,
            'group_code' => $groupCode,
            'source' => Holiday::SOURCE_SWAP,
            'holiday_date' => $off->toDateString(),
            'makeup_date' => $makeup->toDateString(),
            'name' => "Nghỉ hoán đổi {$first->name}",
            'is_paid' => true,
            'intern_paid' => $first->intern_paid,
            'note' => $first->note,
        ]);
        $this->markChanged($groupCode);

        return $this->showGroup($groupCode);
    }

    // Bỏ 1 ngày khỏi dịp (xóa mềm — lần tính tự động sau không thêm lại).
    public function removeDay(Holiday $holiday): array
    {
        $groupCode = $this->groupKey($holiday);
        $holiday->delete();

        if (Holiday::where('group_code', $groupCode)->doesntExist()) {
            return ['group_code' => $groupCode, 'deleted' => true];
        }

        // Một dịp sinh từ quy tắc mà HR bỏ bớt ngày: đánh dấu đã điều chỉnh để giữ nguyên.
        $this->markChanged($groupCode);

        return $this->showGroup($groupCode);
    }

    // Dịp vừa bị sửa thì cần HR xác nhận lại.
    private function markChanged(string $groupCode): void
    {
        Holiday::where('group_code', $groupCode)->update(['confirmed_at' => null, 'confirmed_by' => null]);
        Realtime::shared('holidays');

        // Dịp sinh từ quy tắc mà chưa có ngày nào do HR thêm: gắn cờ "đã điều chỉnh"
        // bằng cách đổi nguồn của ngày gốc đầu tiên còn lại, để lệnh tính tự động
        // không ghi đè chỉnh sửa này.
        $days = Holiday::where('group_code', $groupCode)->get();
        if ($days->first()?->holiday_rule_id && ! $days->contains(fn (Holiday $h) => in_array($h->source, self::HR_SOURCES, true))) {
            $days->firstWhere('source', Holiday::SOURCE_RULE)?->update(['source' => Holiday::SOURCE_ADJUSTED]);
        }
    }

    private function assertDatesFree(Collection $dates, string $field): void
    {
        $conflicts = Holiday::whereIn('holiday_date', $dates)->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->format('d/m/Y'));
        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                $field => 'Đã có ngày nghỉ khác vào: '.$conflicts->implode(', ').'.',
            ]);
        }

        // Ngày đã bị xóa mềm trước đó (cột holiday_date là unique) — xóa hẳn để thêm lại.
        Holiday::onlyTrashed()->whereIn('holiday_date', $dates)->forceDelete();
    }

    /** Âm lịch -> dương lịch cho form (xem trước ngày sẽ nghỉ). */
    public function resolveStartDate(array $data): Carbon
    {
        if (($data['calendar'] ?? HolidayRule::CALENDAR_SOLAR) === HolidayRule::CALENDAR_LUNAR) {
            $date = LunarCalendar::toSolar(
                (int) $data['lunar_day'],
                (int) $data['lunar_month'],
                (int) $data['lunar_year'],
                (bool) ($data['lunar_leap'] ?? false),
            );

            if ($date === null || LunarCalendar::fromSolar($date)['day'] !== (int) $data['lunar_day']) {
                throw ValidationException::withMessages([
                    'lunar_day' => 'Ngày âm lịch này không tồn tại (tháng thiếu chỉ có 29 ngày, hoặc năm đó không có tháng nhuận này).',
                ]);
            }

            return $date;
        }

        return Carbon::parse($data['start_date'])->startOfDay();
    }

    /* --------------------------------- Quy tắc --------------------------------- */

    public function listRules(): Collection
    {
        return $this->holidayRepository->allRules();
    }

    public function createRule(array $data): HolidayRule
    {
        $rule = HolidayRule::create($data);
        $this->regenerateUpcoming();

        return $rule;
    }

    public function updateRule(HolidayRule $rule, array $data): HolidayRule
    {
        // Quy tắc theo luật: giữ nguyên tên và ngày gốc, chỉ chỉnh cách nghỉ.
        if ($rule->is_system) {
            unset($data['name'], $data['calendar'], $data['month'], $data['day']);
        }

        $rule->update($data);
        // Đổi "thực tập sinh được hưởng lương" áp cho mọi ngày nghỉ SẮP TỚI của quy tắc
        // (kể cả dịp đã xác nhận); ngày đã qua giữ nguyên vì có thể đã tính lương.
        if (array_key_exists('intern_paid', $data)) {
            Holiday::where('holiday_rule_id', $rule->id)
                ->whereDate('holiday_date', '>=', now()->toDateString())
                ->update(['intern_paid' => (bool) $data['intern_paid']]);
            Realtime::shared('holidays');
        }
        $this->regenerateUpcoming();

        return $rule->fresh();
    }

    public function deleteRule(HolidayRule $rule): void
    {
        if ($rule->is_system) {
            throw ValidationException::withMessages([
                'rule' => 'Không xóa được ngày lễ theo luật — hãy tắt quy tắc nếu không áp dụng.',
            ]);
        }

        DB::transaction(function () use ($rule) {
            // Gỡ các ngày sắp tới của quy tắc; ngày đã qua giữ lại (đã dùng tính lương).
            Holiday::withTrashed()->where('holiday_rule_id', $rule->id)
                ->where('holiday_date', '>=', now()->toDateString())
                ->forceDelete();
            $rule->delete();
        });
        Realtime::shared('holidays');
    }

    // Quy tắc đổi thì tính lại năm nay và năm sau (chỉ các ngày từ hôm nay trở đi).
    public function regenerateUpcoming(): void
    {
        $year = (int) now()->year;
        $this->generateForYear($year);
        $this->generateForYear($year + 1);
    }

    /* -------------------------------- Thông báo -------------------------------- */

    /**
     * Chạy hằng ngày: (1) nhắc HR xác nhận các dịp sắp tới chưa xác nhận; (2) gửi
     * thông báo nghỉ cho nhân viên với dịp ĐÃ xác nhận đã vào "cửa sổ báo trước".
     *
     * @return array{notified: int, reminded: int}
     */
    public function runDailyNotifications(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return [
            'reminded' => $this->remindUnconfirmed($today),
            'notified' => $this->notifyUpcoming($today),
        ];
    }

    /** @return int số dịp đã gửi thông báo cho nhân viên */
    public function notifyUpcoming(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $maxWindow = max(self::DEFAULT_NOTIFY_DAYS_BEFORE, (int) HolidayRule::max('notify_days_before'));
        $sent = 0;

        foreach ($this->upcomingGroups($today, $maxWindow) as $days) {
            $start = $days->first()->holiday_date;
            $window = $days->first(fn (Holiday $h) => $h->rule)?->rule?->notify_days_before ?? self::DEFAULT_NOTIFY_DAYS_BEFORE;

            $confirmed = $days->every(fn (Holiday $h) => $h->confirmed_at !== null);
            $alreadyNotified = $days->contains(fn (Holiday $h) => $h->notified_at !== null);
            $startsInWindow = $start->gte($today) && $start->lte($today->copy()->addDays($window));

            if (! $confirmed || $alreadyNotified || ! $startsInWindow) {
                continue;
            }

            $this->sendGroupNotification($days);
            $sent++;
        }

        return $sent;
    }

    /** @return int số dịp đã nhắc HR xác nhận */
    public function remindUnconfirmed(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $maxWindow = max(self::DEFAULT_CONFIRM_REMIND_DAYS_BEFORE, (int) HolidayRule::max('confirm_remind_days_before'));
        $reminded = 0;

        $managers = User::where('status', 'active')->withPermission('holiday.manage')->get();
        if ($managers->isEmpty()) {
            return 0;
        }

        foreach ($this->upcomingGroups($today, $maxWindow) as $days) {
            $start = $days->first()->holiday_date;
            $window = $days->first(fn (Holiday $h) => $h->rule)?->rule?->confirm_remind_days_before ?? self::DEFAULT_CONFIRM_REMIND_DAYS_BEFORE;

            $confirmed = $days->every(fn (Holiday $h) => $h->confirmed_at !== null);
            $alreadyReminded = $days->contains(fn (Holiday $h) => $h->confirm_reminded_at !== null);

            if ($confirmed || $alreadyReminded || $start->gt($today->copy()->addDays($window))) {
                continue;
            }

            $group = $this->presentGroup($this->groupKey($days->first()), $days);
            $title = "Cần xác nhận lịch nghỉ {$group['name']}";
            $message = "Lịch nghỉ {$group['name']} ".$this->periodText($group)
                .' do hệ thống tự tính, chưa được xác nhận. Vui lòng đối chiếu thông báo chính thức của Nhà nước, '
                .'điều chỉnh nếu cần rồi bấm "Xác nhận" — nhân viên chỉ được thông báo sau khi xác nhận.';

            foreach ($managers as $manager) {
                $this->notificationService->send($manager, 'holiday.confirm_needed', $title, $message, [
                    'group_code' => $group['group_code'],
                    'start_date' => $group['start_date'],
                ]);
            }

            Holiday::whereIn('id', $days->pluck('id'))->update(['confirm_reminded_at' => now()]);
            $reminded++;
        }

        return $reminded;
    }

    // Các dịp có ngày bắt đầu trong khoảng [hôm nay, hôm nay + $window], kèm đủ ngày của dịp.
    private function upcomingGroups(Carbon $today, int $window): Collection
    {
        return $this->holidayRepository->listBetween($today, $today->copy()->addDays($window))
            ->groupBy(fn (Holiday $h) => $this->groupKey($h))
            ->map(fn (Collection $days, string $code) => str_starts_with($code, 'single-') ? $days : $this->holidayRepository->findGroup($code))
            ->filter(fn (Collection $days) => $days->first()->holiday_date->gte($today));
    }

    // HR bấm "Gửi thông báo ngay" (gửi lại được nếu cần nhắc).
    public function notifyGroup(string $groupCode): int
    {
        return $this->sendGroupNotification($this->groupDays($groupCode));
    }

    private function sendGroupNotification(Collection $days): int
    {
        $group = $this->presentGroup($this->groupKey($days->first()), $days);
        $return = Carbon::parse($group['return_date']);

        $title = "Thông báo nghỉ {$group['name']}";
        $message = "Công ty nghỉ {$group['name']} {$group['total_days']} ngày, ".$this->periodText($group).'. '
            .'Ngày đi làm lại: '.self::WEEKDAYS[$return->dayOfWeek].' '.$return->format('d/m/Y').'.'
            .$this->makeupText($group);
        $data = ['group_code' => $group['group_code'], 'start_date' => $group['start_date']];

        $recipients = User::where('status', 'active')->get();
        foreach ($recipients as $user) {
            $this->notificationService->send($user, 'holiday.upcoming', $title, $message, $data);
        }

        Holiday::whereIn('id', $days->pluck('id'))->update(['notified_at' => now()]);
        Realtime::shared('holidays');

        return $recipients->count();
    }

    private function periodText(array $group): string
    {
        $start = Carbon::parse($group['start_date']);
        $end = Carbon::parse($group['end_date']);

        $period = $start->equalTo($end)
            ? 'ngày '.self::WEEKDAYS[$start->dayOfWeek].' '.$start->format('d/m/Y')
            : 'từ '.self::WEEKDAYS[$start->dayOfWeek].' '.$start->format('d/m/Y').' đến '.self::WEEKDAYS[$end->dayOfWeek].' '.$end->format('d/m/Y');

        $compensatory = collect($group['days'])->where('source', Holiday::SOURCE_COMPENSATORY)
            ->map(fn (array $day) => Carbon::parse($day['date'])->format('d/m'));

        return $period.($compensatory->isNotEmpty() ? ' (gồm nghỉ bù '.$compensatory->implode(', ').')' : '');
    }

    private function makeupText(array $group): string
    {
        if ($group['makeup_days'] === []) {
            return '';
        }

        $parts = collect($group['makeup_days'])->map(fn (array $m) => "{$m['weekday']} ".Carbon::parse($m['date'])->format('d/m/Y')
            .' (thay cho '.Carbon::parse($m['replaces'])->format('d/m').')');

        return ' Đi làm bù: '.$parts->implode(', ').'.';
    }

    // Ngày làm việc đầu tiên sau kỳ nghỉ (bỏ qua cuối tuần và các ngày nghỉ khác).
    private function returnDateAfter(Carbon $lastDay): Carbon
    {
        $holidayDates = Holiday::whereBetween('holiday_date', [$lastDay->toDateString(), $lastDay->copy()->addDays(30)->toDateString()])
            ->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $cursor = $lastDay->copy();
        do {
            $cursor->addDay();
        } while ($cursor->isWeekend() || $holidayDates->has($cursor->toDateString()));

        return $cursor;
    }
}
