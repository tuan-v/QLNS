<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\HolidayAnnouncement;
use App\Models\User;
use App\Repositories\PayrollRepository;
use App\Support\Realtime;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Văn bản "Thông báo nghỉ lễ" theo mẫu Word của công ty (ngoài tài liệu gốc, người dùng
// duyệt 2026-10-12). Mỗi dịp nghỉ (group_code) một văn bản. Văn bản chỉ có hiệu lực khi
// người đại diện công ty KÝ + đóng dấu, nên đi theo quy trình (người dùng duyệt 2026-10-12):
//   draft (HR soạn, xem trước bản Word)
//     -> pending_signature ("Chốt để trình ký": khóa nội dung, chụp lịch, cấp số; HR tải
//        Word in ra trình ký — ký ngoài hệ thống)
//     -> HR tải lên bản scan PDF đã ký (bắt buộc)
//     -> published (gửi thông báo trong app; nhân viên chỉ xem/tải BẢN ĐÃ KÝ; thưởng lễ
//        chỉ được cộng lương từ lúc này — Payroll\HolidayBonusCalculator).
//   "Mở lại để sửa" đưa về draft và bỏ bản đã ký (phải ký lại), trừ khi thưởng đã vào bảng lương.
// Trường ĐỘNG (thời gian nghỉ, ngày đi làm lại, làm bù) lấy từ lịch nghỉ; trường TĨNH (công
// ty, đối tượng, trách nhiệm, nơi nhận, người ký…) lấy lại từ văn bản gần nhất.
class HolidayAnnouncementService
{
    private const WEEKDAYS = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];

    // Các trường dùng lại từ văn bản gần nhất khi soạn cho dịp mới.
    private const STATIC_KEYS = ['company_type', 'company_name', 'city', 'audience', 'responsibilities', 'closing', 'sign_off', 'recipients', 'bonus_method', 'signer_title', 'signer_name'];

    private const SIGNED_DIRECTORY = 'holiday-announcements';

    public function __construct(
        private readonly HolidayService $holidayService,
        private readonly NotificationService $notificationService,
        private readonly PayrollRepository $payrollRepository,
        private readonly HolidayAnnouncementDocument $document,
    ) {
    }

    /** Dữ liệu cho trang soạn/xem: lịch của dịp + nội dung văn bản (đã lưu hoặc mặc định). */
    public function show(string $groupCode, bool $canManage): array
    {
        $group = $this->holidayService->showGroup($groupCode);
        $announcement = HolidayAnnouncement::with(['publisher:id,user_name', 'signedUploader:id,user_name'])
            ->where('group_code', $groupCode)->first();

        if (! $canManage && ! $announcement?->isPublished()) {
            abort(404);
        }

        return [
            'group' => $group,
            'schedule' => $this->scheduleFor($announcement, $group),
            'announcement' => $announcement ? [
                'id' => $announcement->id,
                'status' => $announcement->status,
                'published_at' => $announcement->published_at?->toDateTimeString(),
                'published_by' => $announcement->publisher?->user_name,
                'signed_file_name' => $announcement->signed_file ? $this->signedFileName($announcement) : null,
                'signed_uploaded_at' => $announcement->signed_uploaded_at?->toDateTimeString(),
                'signed_uploaded_by' => $announcement->signedUploader?->user_name,
                'updated_at' => $announcement->updated_at?->toDateTimeString(),
            ] : null,
            'form' => $announcement ? $this->formFrom($announcement) : $this->defaults($group),
            'payroll_periods' => $canManage ? $this->payrollPeriodsAround(Carbon::parse($group['start_date'])) : [],
        ];
    }

    /** Lưu nháp — chỉ khi văn bản còn là nháp (đã chốt trình ký thì phải "Mở lại" trước). */
    public function save(string $groupCode, array $data, User $actor): array
    {
        $this->holidayService->showGroup($groupCode);

        DB::transaction(function () use ($groupCode, $data, $actor) {
            $announcement = $this->draftFor($groupCode, $actor);
            $this->assertBonus($data);

            $announcement->fill($this->attributesFrom($data) + ['updated_by' => $actor->id])->save();
        });

        return $this->show($groupCode, true);
    }

    /** Lưu + chốt nội dung để in trình ký: khóa sửa, chụp lịch nghỉ, cấp số văn bản. */
    public function prepareSigning(string $groupCode, array $data, User $actor): array
    {
        $group = $this->holidayService->showGroup($groupCode);

        DB::transaction(function () use ($groupCode, $group, $data, $actor) {
            $announcement = $this->draftFor($groupCode, $actor);
            $this->assertBonus($data);
            // Báo sớm: ký xong mới biết kỳ lương đã chốt thì phải in ký lại.
            $this->assertBonusPeriodOpen($data);

            $announcement->fill($this->attributesFrom($data) + [
                'status' => HolidayAnnouncement::STATUS_PENDING_SIGNATURE,
                'schedule_snapshot' => $this->schedule($group),
                'updated_by' => $actor->id,
            ]);
            if (! $announcement->document_number) {
                $announcement->document_number = $this->suggestedNumber($data['content']['company_name'] ?? '');
            }
            $announcement->save();
        });

        return $this->show($groupCode, true);
    }

    /** Tải lên bản scan PDF đã ký + đóng dấu (thay bản cũ nếu tải lại). */
    public function uploadSigned(string $groupCode, UploadedFile $file, User $actor): array
    {
        $announcement = $this->findOrFail($groupCode);
        if ($announcement->status !== HolidayAnnouncement::STATUS_PENDING_SIGNATURE) {
            throw ValidationException::withMessages([
                'signed_file' => 'Chỉ tải bản đã ký lên khi văn bản đang chờ ký (bấm "Chốt để trình ký" trước).',
            ]);
        }

        $old = $announcement->signed_file;
        $announcement->update([
            'signed_file' => $file->store(self::SIGNED_DIRECTORY, 'local'),
            'signed_uploaded_at' => now(),
            'signed_uploaded_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $this->show($groupCode, true);
    }

    /** Phát hành bản ĐÃ KÝ: gửi thông báo trong app. @return array{0: array, 1: int} */
    public function publish(string $groupCode, User $actor): array
    {
        $group = $this->holidayService->showGroup($groupCode);

        $announcement = DB::transaction(function () use ($groupCode, $actor) {
            $announcement = HolidayAnnouncement::where('group_code', $groupCode)->lockForUpdate()->first();
            if (! $announcement || $announcement->status !== HolidayAnnouncement::STATUS_PENDING_SIGNATURE) {
                throw ValidationException::withMessages(['status' => 'Văn bản phải được chốt để trình ký trước khi phát hành.']);
            }
            if (! $announcement->signed_file) {
                throw ValidationException::withMessages(['signed_file' => 'Vui lòng tải lên bản scan đã ký và đóng dấu trước khi phát hành.']);
            }
            $this->assertBonusPeriodOpen($this->formFrom($announcement));

            $announcement->update([
                'status' => HolidayAnnouncement::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return $announcement;
        });

        $recipients = $this->notifyEmployees($announcement, $group);

        return [$this->show($groupCode, true), $recipients];
    }

    /** Đưa về nháp để sửa: bỏ bản đã ký (nội dung đổi thì phải ký lại); đã phát hành thì nhân viên không xem được nữa. */
    public function reopen(string $groupCode, User $actor): array
    {
        $announcement = $this->findOrFail($groupCode);
        if ($announcement->status === HolidayAnnouncement::STATUS_DRAFT) {
            return $this->show($groupCode, true);
        }

        if ($announcement->isPublished() && $announcement->has_bonus
            && $this->payrollRepository->findByPeriod($announcement->bonus_payroll_month, $announcement->bonus_payroll_year)) {
            throw ValidationException::withMessages([
                'status' => "Thưởng đã được tính vào bảng lương tháng {$announcement->bonus_payroll_month}/{$announcement->bonus_payroll_year} — không mở lại văn bản được nữa.",
            ]);
        }

        $old = $announcement->signed_file;
        $announcement->update([
            'status' => HolidayAnnouncement::STATUS_DRAFT,
            'signed_file' => null,
            'signed_uploaded_at' => null,
            'signed_uploaded_by' => null,
            'published_at' => null,
            'published_by' => null,
            'updated_by' => $actor->id,
        ]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $this->show($groupCode, true);
    }

    /**
     * File .docx (chỉ người quản lý): $data có giá trị = xem trước nội dung đang soạn (chưa
     * lưu, theo lịch hiện tại); null = bản đã lưu (đã chốt thì theo lịch đã chụp).
     *
     * @return array{0: string, 1: string} [đường dẫn file tạm, tên file tải về]
     */
    public function docx(string $groupCode, ?array $data): array
    {
        $group = $this->holidayService->showGroup($groupCode);
        $announcement = HolidayAnnouncement::where('group_code', $groupCode)->first();

        if ($data === null) {
            if (! $announcement) {
                abort(404);
            }
            $data = $this->formFrom($announcement);
            $schedule = $this->scheduleFor($announcement, $group);
        } else {
            $schedule = $this->schedule($group);
        }

        return [$this->document->build($data, $schedule), $this->baseFileName($schedule).'.docx'];
    }

    /** Bản PDF đã ký: nhân viên chỉ xem khi đã phát hành. @return array{0: string, 1: string} [đường dẫn trên disk local, tên file] */
    public function signedFile(string $groupCode, bool $canManage): array
    {
        $announcement = HolidayAnnouncement::where('group_code', $groupCode)->first();
        if (! $announcement?->signed_file || (! $canManage && ! $announcement->isPublished())) {
            abort(404);
        }

        return [$announcement->signed_file, $this->signedFileName($announcement)];
    }

    /** group_code => trạng thái văn bản, cho danh sách dịp nghỉ. */
    public function statusesFor(array $groupCodes): array
    {
        return HolidayAnnouncement::whereIn('group_code', $groupCodes)
            ->get(['group_code', 'status', 'has_bonus', 'published_at'])
            ->mapWithKeys(fn (HolidayAnnouncement $a) => [$a->group_code => [
                'status' => $a->status,
                'has_bonus' => $a->has_bonus,
                'published_at' => $a->published_at?->toDateTimeString(),
            ]])
            ->all();
    }

    public function deleteForGroup(string $groupCode): void
    {
        foreach (HolidayAnnouncement::where('group_code', $groupCode)->get() as $announcement) {
            if ($announcement->signed_file) {
                Storage::disk('local')->delete($announcement->signed_file);
            }
            $announcement->delete();
        }
    }

    /* ------------------------------- Tiện ích ------------------------------- */

    // Văn bản để sửa: chưa có thì tạo nháp; đã chốt trình ký / phát hành thì phải "Mở lại" trước.
    private function draftFor(string $groupCode, User $actor): HolidayAnnouncement
    {
        $announcement = HolidayAnnouncement::where('group_code', $groupCode)->lockForUpdate()->first()
            ?? new HolidayAnnouncement(['group_code' => $groupCode, 'status' => HolidayAnnouncement::STATUS_DRAFT, 'created_by' => $actor->id]);

        if ($announcement->status !== HolidayAnnouncement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Văn bản đã chốt để trình ký — bấm "Mở lại để sửa" trước khi chỉnh nội dung.',
            ]);
        }

        return $announcement;
    }

    private function findOrFail(string $groupCode): HolidayAnnouncement
    {
        $this->holidayService->showGroup($groupCode);

        return HolidayAnnouncement::where('group_code', $groupCode)->firstOrFail();
    }

    private function baseFileName(array $schedule): string
    {
        return 'Thong-bao-nghi-'.Str::slug(Str::ascii($schedule['name'])).'-'.Carbon::parse($schedule['start_date'])->year;
    }

    private function signedFileName(HolidayAnnouncement $announcement): string
    {
        return $this->baseFileName($announcement->schedule_snapshot ?? ['name' => 'le', 'start_date' => now()->toDateString()]).'-da-ky.pdf';
    }

    /* ------------------------------- Nội dung ------------------------------- */

    private function defaults(array $group): array
    {
        $last = HolidayAnnouncement::latest('updated_at')->latest('id')->first();
        $statics = $last ? array_intersect_key($last->content ?? [], array_flip(self::STATIC_KEYS)) : [];
        $statics += [
            'company_type' => 'CÔNG TY CỔ PHẦN',
            'company_name' => 'ASFY TECH',
            'city' => 'Hà Nội',
            'responsibilities' => [
                'Các trưởng bộ phận, phòng ban, trưởng nhóm thông báo tới nhân viên trong bộ phận nắm rõ.',
                'Tắt hết các thiết bị sử dụng điện trước kỳ nghỉ lễ.',
                'Các vướng mắc và phát sinh vui lòng liên hệ bộ phận Quản lý HCNS.',
            ],
            'closing' => 'Thay mặt Công ty, chúc toàn thể cán bộ và nhân sự trong công ty kỳ nghỉ lễ vui vẻ, hạnh phúc và ý nghĩa!',
            'sign_off' => 'Trân trọng thông báo./.',
            'recipients' => [
                ['text' => 'Ban giám đốc', 'note' => 'để b/c'],
                ['text' => 'Trưởng các phòng ban, tổ', 'note' => 'để t/h'],
                ['text' => 'Lưu: HCNS', 'note' => null],
            ],
            'bonus_method' => 'Chuyển khoản/tiền mặt',
            'signer_title' => 'GIÁM ĐỐC',
            'signer_name' => '',
        ];
        $company = Str::ucfirst(mb_strtolower($statics['company_type'])).' '.$statics['company_name'];
        $statics['audience'] ??= "Toàn thể nhân sự đang làm việc tại {$company}, bao gồm nhân sự chính thức, nhân sự thử việc, học việc và thực tập sinh.";

        $start = Carbon::parse($group['start_date']);
        [$month, $year] = $this->defaultBonusPeriod($start);

        return [
            'document_number' => $this->suggestedNumber($statics['company_name']),
            'issued_date' => now()->toDateString(),
            'content' => $statics + [
                'title' => "Về việc nghỉ {$group['name']} năm {$start->year}",
                'intro' => "{$company} thông báo lịch nghỉ {$group['name']} năm {$start->year} như sau:",
                'bonus_heading' => "Nội dung chi thưởng {$group['name']}",
                'bonus_pay_time' => "Chi trả cùng lương tháng {$month}/{$year}.",
            ],
            'has_bonus' => false,
            'bonus_rows' => $last?->has_bonus && $last->bonus_rows ? $last->bonus_rows : [
                ['label' => 'Nhân sự chính thức', 'category' => 'official', 'amount' => 0, 'note' => null],
                ['label' => 'Nhân sự thử việc', 'category' => 'probation', 'amount' => 0, 'note' => null],
                ['label' => 'Học việc, thực tập sinh', 'category' => 'intern', 'amount' => 0, 'note' => null],
            ],
            'bonus_payroll_month' => $month,
            'bonus_payroll_year' => $year,
        ];
    }

    private function formFrom(HolidayAnnouncement $announcement): array
    {
        return [
            'document_number' => $announcement->document_number,
            'issued_date' => $announcement->issued_date?->toDateString(),
            'content' => ($announcement->content ?? []) + ['signer_title' => 'GIÁM ĐỐC', 'signer_name' => ''],
            'has_bonus' => $announcement->has_bonus,
            'bonus_rows' => $announcement->bonus_rows ?? [],
            'bonus_payroll_month' => $announcement->bonus_payroll_month,
            'bonus_payroll_year' => $announcement->bonus_payroll_year,
        ];
    }

    private function attributesFrom(array $data): array
    {
        $hasBonus = (bool) ($data['has_bonus'] ?? false);

        return [
            'document_number' => $data['document_number'] ?? null,
            'issued_date' => $data['issued_date'],
            'content' => $data['content'],
            'has_bonus' => $hasBonus,
            'bonus_rows' => $hasBonus ? $this->normalizeRows($data['bonus_rows'] ?? []) : null,
            'bonus_payroll_month' => $hasBonus ? (int) $data['bonus_payroll_month'] : null,
            'bonus_payroll_year' => $hasBonus ? (int) $data['bonus_payroll_year'] : null,
        ];
    }

    private function normalizeRows(array $rows): array
    {
        return array_values(array_map(fn (array $row) => [
            'label' => trim((string) $row['label']),
            'category' => $row['category'] ?? null,
            'amount' => round((float) $row['amount'], 2),
            'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null,
        ], $rows));
    }

    // Số văn bản gợi ý: {thứ tự trong năm}-{yy}/TB-{tên công ty}, vd 04-26/TB-ASFY TECH.
    private function suggestedNumber(string $companyName): string
    {
        $year = now()->year;
        $sequence = HolidayAnnouncement::where('status', HolidayAnnouncement::STATUS_PUBLISHED)
            ->whereYear('issued_date', $year)
            ->count() + 1;

        return sprintf('%02d-%s/TB-%s', $sequence, substr((string) $year, -2), $companyName);
    }

    /* -------------------------------- Lịch nghỉ -------------------------------- */

    // Văn bản đã chốt (chờ ký / đã phát hành) dùng lịch đã chụp — đúng với bản giấy đã ký.
    private function scheduleFor(?HolidayAnnouncement $announcement, array $group): array
    {
        if ($announcement && $announcement->status !== HolidayAnnouncement::STATUS_DRAFT && $announcement->schedule_snapshot) {
            return $announcement->schedule_snapshot;
        }

        return $this->schedule($group);
    }

    private function schedule(array $group): array
    {
        $label = fn (string $date) => self::WEEKDAYS[Carbon::parse($date)->dayOfWeek];

        return [
            'name' => $group['name'],
            'start_date' => $group['start_date'],
            'end_date' => $group['end_date'],
            'return_date' => $group['return_date'],
            'return_weekday' => $label($group['return_date']),
            'makeup_days' => array_map(fn (array $m) => [
                'date' => $m['date'],
                'weekday' => $label($m['date']),
                'replaces' => $m['replaces'],
            ], $group['makeup_days']),
        ];
    }

    /* ------------------------------ Thưởng + lương ------------------------------ */

    private function assertBonus(array $data): void
    {
        if (! ($data['has_bonus'] ?? false)) {
            return;
        }

        $rows = collect($data['bonus_rows'] ?? []);
        $categories = $rows->pluck('category')->filter();
        if ($categories->count() !== $categories->unique()->count()) {
            throw ValidationException::withMessages(['bonus_rows' => 'Mỗi nhóm hưởng thưởng (chính thức / thử việc / thực tập) chỉ được chọn ở một dòng.']);
        }
        if (! $rows->contains(fn (array $row) => ($row['category'] ?? null) && (float) $row['amount'] > 0)) {
            throw ValidationException::withMessages(['bonus_rows' => 'Cần ít nhất một dòng có nhóm hưởng thưởng và mức thưởng lớn hơn 0.']);
        }
    }

    private function assertBonusPeriodOpen(array $data): void
    {
        if (! ($data['has_bonus'] ?? false)) {
            return;
        }

        $month = (int) $data['bonus_payroll_month'];
        $year = (int) $data['bonus_payroll_year'];
        if ($this->payrollRepository->findByPeriod($month, $year)) {
            throw ValidationException::withMessages([
                'bonus_payroll_month' => "Bảng lương tháng {$month}/{$year} đã được tạo nên thưởng sẽ không được cộng. Hãy chọn tháng chưa tạo bảng lương.",
            ]);
        }
    }

    // Tháng gợi ý chi thưởng: tháng liền trước dịp nghỉ (lương tháng đó trả ngay trước kỳ nghỉ,
    // như mẫu lễ 2/9 chi cùng lương tháng 8); bảng lương tháng đó đã tạo thì lấy tháng của dịp.
    private function defaultBonusPeriod(Carbon $start): array
    {
        $previous = $start->copy()->startOfMonth()->subMonth();
        if (! $this->payrollRepository->findByPeriod($previous->month, $previous->year)) {
            return [$previous->month, $previous->year];
        }

        return [$start->month, $start->year];
    }

    // Các kỳ lương quanh dịp nghỉ để chọn, kèm cờ đã tạo bảng lương hay chưa.
    private function payrollPeriodsAround(Carbon $start): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfMonth()->subMonths(2);
        for ($i = 0; $i < 5; $i++) {
            $periods[] = [
                'month' => $cursor->month,
                'year' => $cursor->year,
                'has_payroll' => (bool) $this->payrollRepository->findByPeriod($cursor->month, $cursor->year),
            ];
            $cursor->addMonth();
        }

        return $periods;
    }

    /* -------------------------------- Phát hành -------------------------------- */

    private function notifyEmployees(HolidayAnnouncement $announcement, array $group): int
    {
        $schedule = $announcement->schedule_snapshot;
        $start = Carbon::parse($schedule['start_date']);
        $end = Carbon::parse($schedule['end_date']);
        $return = Carbon::parse($schedule['return_date']);

        $period = $start->equalTo($end)
            ? 'ngày '.$start->format('d/m/Y')
            : 'từ '.$start->format('d/m/Y').' đến hết '.$end->format('d/m/Y');
        $message = "Công ty nghỉ {$schedule['name']} {$period}, đi làm lại {$schedule['return_weekday']} ".$return->format('d/m/Y').'.'
            .($schedule['makeup_days'] ? ' Có lịch làm bù.' : '')
            .($announcement->has_bonus ? ' Có thưởng lễ, chi cùng lương tháng '.$announcement->bonus_payroll_month.'/'.$announcement->bonus_payroll_year.'.' : '')
            .' Bấm để xem văn bản thông báo.';

        $recipients = User::where('status', 'active')->get();
        foreach ($recipients as $user) {
            $this->notificationService->send($user, 'holiday.announcement', "Thông báo nghỉ {$schedule['name']}", $message, [
                'group_code' => $announcement->group_code,
                'start_date' => $schedule['start_date'],
            ]);
        }

        // Đã có văn bản chính thức -> không gửi thêm thông báo tự động ngắn của dịp này.
        Holiday::whereIn('id', array_column($group['days'], 'id'))->update(['notified_at' => now()]);
        Realtime::shared('holidays');

        return $recipients->count();
    }
}
