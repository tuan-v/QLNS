<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Holiday\SaveHolidayRuleRequest;
use App\Http\Requests\Holiday\StoreHolidayRequest;
use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Services\HolidayService;
use App\Support\LunarCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function __construct(private readonly HolidayService $holidayService)
    {
    }

    // Mọi nhân viên đã đăng nhập đều xem được lịch nghỉ lễ.
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->input('year') ?: now()->year);

        return response()->json(['year' => $year, 'data' => $this->holidayService->listForYear($year)]);
    }

    // Xem trước ngày dương lịch của một ngày âm (form thêm ngày nghỉ).
    public function convert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lunar_day' => ['required', 'integer', 'between:1,30'],
            'lunar_month' => ['required', 'integer', 'between:1,12'],
            'lunar_year' => ['required', 'integer', 'between:1900,2199'],
            'lunar_leap' => ['nullable', 'boolean'],
        ]);
        $date = $this->holidayService->resolveStartDate($data + ['calendar' => 'lunar']);

        return response()->json(['date' => $date->toDateString(), 'lunar_label' => LunarCalendar::label($date)]);
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        return response()->json($this->holidayService->createManual($request->validated(), $request->user()), 201);
    }

    public function showGroup(string $groupCode): JsonResponse
    {
        return response()->json($this->holidayService->showGroup($groupCode));
    }

    // HR đối chiếu thông báo chính thức rồi xác nhận (confirmed=false để bỏ xác nhận).
    public function confirmGroup(Request $request, string $groupCode): JsonResponse
    {
        $request->validate(['confirmed' => ['sometimes', 'boolean']]);

        return response()->json(
            $this->holidayService->confirmGroup($groupCode, $request->user(), $request->boolean('confirmed', true)),
        );
    }

    // Bật/tắt thực tập sinh được hưởng lương cho cả dịp nghỉ.
    public function setInternPaid(Request $request, string $groupCode): JsonResponse
    {
        $request->validate(['intern_paid' => ['required', 'boolean']]);

        return response()->json($this->holidayService->setInternPaid($groupCode, $request->boolean('intern_paid')));
    }

    // Lưu mọi chỉnh sửa trong dialog "Điều chỉnh" cùng lúc rồi xác nhận lịch.
    public function applyChanges(Request $request, string $groupCode): JsonResponse
    {
        $data = $request->validate([
            'remove_ids' => ['sometimes', 'array'],
            'remove_ids.*' => ['integer'],
            'add_dates' => ['sometimes', 'array'],
            'add_dates.*' => ['date'],
            'swaps' => ['sometimes', 'array'],
            'swaps.*.off_date' => ['required', 'date'],
            'swaps.*.makeup_date' => ['required', 'date', 'different:swaps.*.off_date'],
        ]);

        return response()->json($this->holidayService->applyChanges($groupCode, $data, $request->user()));
    }

    public function addDay(Request $request, string $groupCode): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
        ], ['date.required' => 'Vui lòng chọn ngày nghỉ']);

        return response()->json($this->holidayService->addDayToGroup($groupCode, $data['date'], $data['name'] ?? null), 201);
    }

    public function addSwap(Request $request, string $groupCode): JsonResponse
    {
        $data = $request->validate([
            'off_date' => ['required', 'date'],
            'makeup_date' => ['required', 'date', 'different:off_date'],
        ], [
            'off_date.required' => 'Vui lòng chọn ngày nghỉ hoán đổi',
            'makeup_date.required' => 'Vui lòng chọn ngày đi làm bù',
        ]);

        return response()->json($this->holidayService->addSwap($groupCode, $data['off_date'], $data['makeup_date']), 201);
    }

    public function removeDay(Holiday $holiday): JsonResponse
    {
        return response()->json($this->holidayService->removeDay($holiday));
    }

    public function destroyGroup(string $groupCode): JsonResponse
    {
        $this->holidayService->deleteGroup($groupCode);

        return response()->json(null, 204);
    }

    public function generate(Request $request): JsonResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2199']])['year'];

        return response()->json($this->holidayService->generateForYear($year));
    }

    public function notifyGroup(string $groupCode): JsonResponse
    {
        return response()->json(['recipients' => $this->holidayService->notifyGroup($groupCode)]);
    }

    public function rules(): JsonResponse
    {
        return response()->json(['data' => $this->holidayService->listRules()]);
    }

    public function storeRule(SaveHolidayRuleRequest $request): JsonResponse
    {
        return response()->json($this->holidayService->createRule($request->validated()), 201);
    }

    public function updateRule(SaveHolidayRuleRequest $request, HolidayRule $holidayRule): JsonResponse
    {
        return response()->json($this->holidayService->updateRule($holidayRule, $request->validated()));
    }

    public function destroyRule(HolidayRule $holidayRule): JsonResponse
    {
        $this->holidayService->deleteRule($holidayRule);

        return response()->json(null, 204);
    }
}
