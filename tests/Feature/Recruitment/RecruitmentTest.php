<?php

namespace Tests\Feature\Recruitment;

use App\Mail\CandidateInterviewInvitationMail;
use App\Mail\CandidateInterviewResultMail;
use App\Mail\CandidateReviewRejectedMail;
use App\Models\Commune;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Province;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use App\Models\User;
use App\Services\RecruitmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Tuyển dụng: giới hạn CV đã duyệt theo số người cần tuyển, chỉ Admin duyệt, luồng
// trạng thái đúng thứ tự, email ứng viên, nhận việc tạo nhân viên trong cùng transaction.
class RecruitmentTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-06 09:00'));
    }

    private function as(string $who): array
    {
        $credentials = [
            'hr' => ['hr@qlns.local', 'Hr@123456'],
            'admin' => ['admin@qlns.local', 'Admin@123'],
            'employee' => ['employee@qlns.local', 'Employee@123'],
        ][$who];

        $this->tokens[$who] ??= $this->postJson('/api/v1/auth/login', ['email' => $credentials[0], 'password' => $credentials[1]])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$who]];
    }

    private function createOpening(int $headcount = 2, array $overrides = []): RecruitmentOpening
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $id = $this->postJson('/api/v1/recruitment/openings', array_merge([
            'title' => 'Thực tập sinh PHP', 'department_id' => $department->id,
            'contract_type' => 'thuc_tap', 'headcount' => $headcount, 'deadline' => '2026-10-31',
        ], $overrides), $this->as('hr'))->assertCreated()->json('data.id');

        return RecruitmentOpening::findOrFail($id);
    }

    // SĐT mặc định suy từ email (mỗi ứng viên 1 số riêng) — test chống trùng truyền số cụ thể.
    private function uploadCv(RecruitmentOpening $opening, string $email, ?string $phone = null): TestResponse
    {
        $phone ??= '09'.str_pad((string) (crc32(strtolower($email)) % 100000000), 8, '0', STR_PAD_LEFT);

        return $this->post("/api/v1/recruitment/openings/{$opening->id}/candidates", [
            'full_name' => 'Ung vien '.$email, 'email' => $email, 'phone' => $phone,
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json']);
    }

    private function addCandidate(RecruitmentOpening $opening, string $email): RecruitmentCandidate
    {
        return RecruitmentCandidate::findOrFail($this->uploadCv($opening, $email)->assertCreated()->json('data.id'));
    }

    private function review(RecruitmentCandidate $candidate, string $status, ?string $note = null): TestResponse
    {
        return $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/review", ['status' => $status, 'review_note' => $note], $this->as('admin'));
    }

    private function schedule(RecruitmentCandidate $candidate, string $at = '2026-10-08 09:00', array $overrides = []): TestResponse
    {
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();

        return $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/interview", array_merge([
            'scheduled_at' => $at, 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phòng họp tầng 3, 12 Lê Lợi',
        ], $overrides), $this->as('hr'));
    }

    private function recordResult(RecruitmentCandidate $candidate, string $result): TestResponse
    {
        return $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/result", ['result' => $result], $this->as('hr'));
    }

    // Ứng viên đã qua phỏng vấn (để test nhận việc).
    private function passedCandidate(RecruitmentOpening $opening, string $email): RecruitmentCandidate
    {
        $candidate = $this->addCandidate($opening, $email);
        $this->review($candidate, 'approved')->assertOk();
        $this->schedule($candidate)->assertOk();
        $this->travelTo(Carbon::parse('2026-10-08 10:00'));
        $this->recordResult($candidate, 'passed')->assertOk();

        return $candidate->fresh();
    }

    public function test_permissions_hr_manages_only_admin_approves_employee_has_no_access(): void
    {
        $this->getJson('/api/v1/recruitment/openings', $this->as('employee'))->assertForbidden();
        $opening = $this->createOpening();
        $candidate = $this->addCandidate($opening, 'a@mail.com');

        $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/review", ['status' => 'approved'], $this->as('hr'))->assertForbidden();
        $this->review($candidate, 'approved')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->get("/api/v1/recruitment/candidates/{$candidate->id}/cv", $this->as('employee') + ['Accept' => 'application/json'])->assertForbidden();
        $this->get("/api/v1/recruitment/candidates/{$candidate->id}/cv", $this->as('hr'))->assertOk();
    }

    public function test_hr_upload_notifies_admin(): void
    {
        $opening = $this->createOpening();
        $this->addCandidate($opening, 'a@mail.com');

        $admin = User::where('email', 'admin@qlns.local')->first();
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'recruitment.cv_pending')->count());
    }

    public function test_quota_counts_approved_cvs_and_stops_new_uploads_when_full(): void
    {
        $opening = $this->createOpening(headcount: 2);
        $a = $this->addCandidate($opening, 'a@mail.com');
        $b = $this->addCandidate($opening, 'b@mail.com');
        $c = $this->addCandidate($opening, 'c@mail.com');

        // Chưa duyệt đủ thì vẫn nhận thêm CV chờ duyệt.
        $this->review($a, 'approved')->assertOk();
        $this->assertSame('open', $opening->fresh()->status);
        $this->review($b, 'approved')->assertOk();
        $this->assertSame('full', $opening->fresh()->status);

        // Đủ suất: không nhận CV mới, không duyệt thêm, nhưng vẫn từ chối được CV còn chờ.
        $this->uploadCv($opening, 'd@mail.com')->assertStatus(422)->assertJsonValidationErrors('opening');
        $this->review($c, 'approved')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->review($c, 'rejected', 'Đã đủ người')->assertOk();
        $this->assertSame(2, RecruitmentCandidate::whereIn('status', RecruitmentCandidate::COUNTED_STATUSES)->count());
    }

    public function test_failed_interview_frees_a_slot_and_reopens_the_opening(): void
    {
        $opening = $this->createOpening(headcount: 1);
        $a = $this->addCandidate($opening, 'a@mail.com');
        $this->review($a, 'approved')->assertOk();
        $this->assertSame('full', $opening->fresh()->status);

        $this->schedule($a)->assertOk();
        $this->travelTo(Carbon::parse('2026-10-08 10:00'));
        $this->recordResult($a, 'failed')->assertOk();

        $this->assertSame('open', $opening->fresh()->status);
        $this->uploadCv($opening, 'b@mail.com')->assertCreated();
    }

    public function test_status_flow_is_enforced(): void
    {
        $opening = $this->createOpening();
        $candidate = $this->addCandidate($opening, 'a@mail.com');

        // Chưa duyệt thì chưa hẹn phỏng vấn được; chưa có lịch thì chưa ghi kết quả.
        $this->schedule($candidate)->assertStatus(422);
        $this->recordResult($candidate, 'passed')->assertStatus(422);

        $this->review($candidate, 'approved')->assertOk();
        // Duyệt lại CV đã xử lý -> 422.
        $this->review($candidate, 'rejected', 'x')->assertStatus(422);

        $this->schedule($candidate)->assertOk();
        // Chưa tới giờ phỏng vấn -> chưa ghi kết quả.
        $this->recordResult($candidate, 'passed')->assertStatus(422)->assertJsonValidationErrors('result');

        $this->travelTo(Carbon::parse('2026-10-08 10:00'));
        $this->recordResult($candidate, 'passed')->assertOk()->assertJsonPath('data.status', 'passed');
        // Có kết quả rồi thì không ghi lại / không hẹn lại.
        $this->recordResult($candidate, 'failed')->assertStatus(422);
        $this->schedule($candidate, '2026-10-09 09:00')->assertStatus(422);
    }

    public function test_candidate_emails_are_queued(): void
    {
        $opening = $this->createOpening();
        $rejected = $this->addCandidate($opening, 'reject@mail.com');
        $this->review($rejected, 'rejected', 'Chưa đủ kinh nghiệm')->assertOk();
        Mail::assertQueued(CandidateReviewRejectedMail::class, fn ($mail) => $mail->hasTo('reject@mail.com'));

        $candidate = $this->addCandidate($opening, 'ok@mail.com');
        $this->review($candidate, 'approved')->assertOk();
        $this->schedule($candidate)->assertOk();
        Mail::assertQueued(CandidateInterviewInvitationMail::class, fn ($mail) => $mail->hasTo('ok@mail.com') && ! $mail->rescheduled);

        // Dời lịch: vẫn 1 lịch phỏng vấn, email báo đổi lịch.
        $this->schedule($candidate, '2026-10-09 14:00', ['format' => 'online', 'location' => 'https://meet.example.com/abc'])->assertOk();
        $this->assertSame(1, $candidate->interviews()->count());
        Mail::assertQueued(CandidateInterviewInvitationMail::class, fn ($mail) => $mail->rescheduled);

        $this->travelTo(Carbon::parse('2026-10-09 15:00'));
        $this->recordResult($candidate, 'passed')->assertOk();
        Mail::assertQueued(CandidateInterviewResultMail::class, fn ($mail) => $mail->hasTo('ok@mail.com'));
    }

    public function test_online_interview_requires_a_link(): void
    {
        $opening = $this->createOpening();
        $candidate = $this->addCandidate($opening, 'a@mail.com');
        $this->review($candidate, 'approved')->assertOk();

        $this->schedule($candidate, '2026-10-08 09:00', ['format' => 'online', 'location' => 'javascript:alert(1)'])
            ->assertStatus(422)->assertJsonValidationErrors('location');
    }

    // Trực tiếp: bắt buộc Tỉnh + Xã (đúng tỉnh) + địa chỉ chi tiết; lưu địa chỉ đầy đủ đã
    // ghép (cho email) + mã tỉnh/xã (để dời lịch điền lại). Không bắt chọn người phỏng vấn.
    public function test_onsite_interview_requires_province_commune_and_detail(): void
    {
        $opening = $this->createOpening();
        $candidate = $this->addCandidate($opening, 'a@mail.com');
        $this->review($candidate, 'approved')->assertOk();
        $province = Province::query()->firstOrFail();
        $otherProvinceCommune = Commune::where('province_code', '!=', $province->code)->firstOrFail();

        $this->schedule($candidate, '2026-10-08 09:00', ['province_code' => null, 'commune_code' => null, 'address_detail' => null])
            ->assertStatus(422)->assertJsonValidationErrors(['province_code', 'commune_code', 'address_detail']);
        $this->schedule($candidate, '2026-10-08 09:00', ['commune_code' => $otherProvinceCommune->code])
            ->assertStatus(422)->assertJsonValidationErrors('commune_code');

        $this->schedule($candidate)->assertOk();

        $interview = $candidate->interviews()->sole();
        $commune = Commune::where('code', $interview->commune_code)->value('name');
        $this->assertSame("Phòng họp tầng 3, 12 Lê Lợi, {$commune}, {$province->name}", $interview->location);
        $this->assertSame($province->code, $interview->province_code);
        $this->assertNull($interview->interviewer_id);
    }

    public function test_duplicate_email_is_rejected_and_leaves_no_orphan_file(): void
    {
        $opening = $this->createOpening();
        $this->addCandidate($opening, 'a@mail.com');

        $this->uploadCv($opening, 'A@MAIL.com')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertCount(1, Storage::disk('local')->allFiles('recruitment-cvs'));
    }

    // Email/SĐT đã thuộc hồ sơ nhân viên (kể cả đã xóa) hoặc đã có trong cùng đợt -> 422
    // ngay lúc tải CV, tránh tới lúc nhận việc mới vấp trùng.
    public function test_email_and_phone_already_used_by_an_employee_or_in_the_opening_are_rejected(): void
    {
        $opening = $this->createOpening();
        $employee = Employee::create([
            'full_name' => 'Nhan vien co san', 'company_email' => 'congty@qlns.local', 'personal_email' => 'canhan@mail.com',
            'phone' => '0911111111', 'hire_date' => '2025-01-01', 'code' => 'NV-TRUNG', 'department_id' => $opening->department_id,
        ]);

        $this->uploadCv($opening, 'CANHAN@mail.com', '0922222222')
            ->assertStatus(422)->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Email này đã thuộc hồ sơ nhân viên NV-TRUNG – Nhan vien co san.');
        $this->uploadCv($opening, 'congty@qlns.local', '0922222222')->assertStatus(422)->assertJsonValidationErrors('email');
        $this->uploadCv($opening, 'moi@mail.com', '0911111111')->assertStatus(422)->assertJsonValidationErrors('phone');

        // Hồ sơ đã xóa mềm vẫn giữ email/SĐT (rule unique của nhân viên cũng tính).
        $employee->delete();
        $this->uploadCv($opening, 'moi@mail.com', '0911111111')->assertStatus(422)->assertJsonValidationErrors('phone');

        // Trùng SĐT với CV khác trong cùng đợt.
        $this->uploadCv($opening, 'a@mail.com', '0933333333')->assertCreated();
        $this->uploadCv($opening, 'b@mail.com', '0933333333')->assertStatus(422)->assertJsonValidationErrors('phone');
        // SĐT sai định dạng.
        $this->uploadCv($opening, 'c@mail.com', '12345')->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->assertCount(1, Storage::disk('local')->allFiles('recruitment-cvs'));
    }

    // API kiểm tra khi rời ô báo đúng câu như lúc lưu; nhân viên thường không gọi được.
    public function test_check_duplicate_endpoint_matches_save_rules(): void
    {
        $opening = $this->createOpening();
        Employee::create([
            'full_name' => 'Nhan vien co san', 'company_email' => 'congty@qlns.local', 'personal_email' => 'canhan@mail.com',
            'phone' => '0911111111', 'hire_date' => '2025-01-01', 'code' => 'NV-TRUNG', 'department_id' => $opening->department_id,
        ]);
        $this->uploadCv($opening, 'a@mail.com', '0933333333')->assertCreated();
        $url = "/api/v1/recruitment/openings/{$opening->id}/check-duplicate";

        $this->getJson($url.'?field=email&value=CanHan@mail.com', $this->as('hr'))->assertOk()
            ->assertJson(['available' => false, 'message' => 'Email này đã thuộc hồ sơ nhân viên NV-TRUNG – Nhan vien co san.']);
        $this->getJson($url.'?field=phone&value=0933333333', $this->as('hr'))->assertOk()
            ->assertJson(['available' => false, 'message' => 'Ứng viên với số điện thoại này đã có CV trong đợt tuyển.']);
        $this->getJson($url.'?field=email&value=moi@mail.com', $this->as('hr'))->assertOk()
            ->assertJson(['available' => true, 'message' => null]);
        $this->getJson($url.'?field=cccd&value=1', $this->as('hr'))->assertStatus(422);
        $this->getJson($url.'?field=email&value=moi@mail.com', $this->as('employee'))->assertForbidden();
    }

    // Duyệt hàng loạt: duyệt lần lượt theo thứ tự gửi, đủ suất thì CV dư báo lỗi riêng.
    public function test_bulk_review_stops_at_headcount_and_reports_each_failure(): void
    {
        $opening = $this->createOpening(headcount: 2);
        $ids = collect(['a', 'b', 'c'])->map(fn ($x) => $this->addCandidate($opening, "{$x}@mail.com")->id)->all();

        $this->postJson('/api/v1/recruitment/candidates/bulk-review', ['candidate_ids' => $ids, 'status' => 'approved'], $this->as('hr'))
            ->assertForbidden();

        $result = $this->postJson('/api/v1/recruitment/candidates/bulk-review', ['candidate_ids' => $ids, 'status' => 'approved'], $this->as('admin'))
            ->assertOk()->json();

        $this->assertSame(2, $result['done']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($ids[2], $result['failed'][0]['id']);
        $this->assertStringContainsString('đã đủ 2 CV', $result['failed'][0]['message']);
        $this->assertSame('full', $opening->fresh()->status);

        $this->postJson('/api/v1/recruitment/candidates/bulk-review', ['candidate_ids' => [$ids[2]], 'status' => 'rejected'], $this->as('admin'))
            ->assertStatus(422)->assertJsonValidationErrors('review_note');
    }

    // Hẹn hàng loạt theo khung giờ; ứng viên lỗi (chưa duyệt) không chiếm khung giờ.
    public function test_bulk_schedule_assigns_consecutive_slots(): void
    {
        $opening = $this->createOpening(headcount: 3);
        $a = $this->addCandidate($opening, 'a@mail.com');
        $pending = $this->addCandidate($opening, 'p@mail.com');
        $b = $this->addCandidate($opening, 'b@mail.com');
        $this->review($a, 'approved')->assertOk();
        $this->review($b, 'approved')->assertOk();
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();

        $result = $this->postJson('/api/v1/recruitment/candidates/bulk-interview', [
            'candidate_ids' => [$a->id, $pending->id, $b->id],
            'start_at' => '2026-10-08 09:00', 'slot_minutes' => 30, 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phòng họp A',
        ], $this->as('hr'))->assertOk()->json();

        $this->assertSame(2, $result['done']);
        $this->assertSame($pending->id, $result['failed'][0]['id']);
        $this->assertSame('09:00', $a->interviews()->sole()->scheduled_at->format('H:i'));
        $this->assertSame('09:30', $b->interviews()->sole()->scheduled_at->format('H:i'));
        Mail::assertQueued(CandidateInterviewInvitationMail::class, 2);
    }

    public function test_headcount_closing_deadline_and_delete_rules(): void
    {
        $opening = $this->createOpening(headcount: 2);
        $a = $this->addCandidate($opening, 'a@mail.com');
        $b = $this->addCandidate($opening, 'b@mail.com');
        $this->review($a, 'approved')->assertOk();
        $this->review($b, 'approved')->assertOk();

        $payload = ['title' => $opening->title, 'department_id' => $opening->department_id, 'contract_type' => 'thuc_tap'];
        // Không giảm số cần tuyển dưới số CV đã duyệt; tăng lên thì mở nhận CV lại.
        $this->putJson("/api/v1/recruitment/openings/{$opening->id}", $payload + ['headcount' => 1], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('headcount');
        $this->putJson("/api/v1/recruitment/openings/{$opening->id}", $payload + ['headcount' => 3], $this->as('hr'))
            ->assertOk()->assertJsonPath('data.status', 'open');

        // Đóng tay: không nhận CV; mở lại thì tính lại trạng thái.
        $this->postJson("/api/v1/recruitment/openings/{$opening->id}/close", [], $this->as('hr'))->assertOk();
        $this->uploadCv($opening, 'c@mail.com')->assertStatus(422);
        $this->postJson("/api/v1/recruitment/openings/{$opening->id}/reopen", [], $this->as('hr'))->assertOk()->assertJsonPath('data.status', 'open');

        // Quá hạn nộp -> 422.
        $this->travelTo(Carbon::parse('2026-11-01 09:00'));
        $this->uploadCv($opening, 'late@mail.com')->assertStatus(422)->assertJsonValidationErrors('opening');

        // Không xóa đợt đã có CV, không xóa CV đã duyệt.
        $this->deleteJson("/api/v1/recruitment/openings/{$opening->id}", [], $this->as('hr'))->assertStatus(422);
        $this->deleteJson("/api/v1/recruitment/candidates/{$a->id}", [], $this->as('hr'))->assertStatus(422);
    }

    public function test_hiring_creates_employee_and_marks_candidate_atomically(): void
    {
        $opening = $this->createOpening();
        $candidate = $this->passedCandidate($opening, 'hire@mail.com');
        $province = Province::query()->firstOrFail();
        $commune0 = Commune::where('province_code', $province->code)->firstOrFail();

        // Chưa có offer được chấp nhận -> chưa nhận việc được (RecruitmentOfferService).
        $this->postJson('/api/v1/employees', [
            'full_name' => 'Nguyen Chua Offer', 'company_email' => 'chuaoffer@qlns.local', 'hire_date' => '2026-10-15',
            'date_of_birth' => '2003-01-01', 'gender' => 'male', 'phone' => '0909999999', 'personal_email' => 'chuaoffer@mail.com',
            'cccd' => '001203099999', 'personal_tax_code' => 'MST099999', 'address_detail' => 'Ha Noi',
            'province_code' => $province->code, 'commune_code' => $commune0->code,
            'department_id' => $opening->department_id, 'contract_type' => 'thuc_tap', 'agreed_salary' => 3_000_000,
            'candidate_id' => $candidate->id,
        ], $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('candidate_id');

        RecruitmentOffer::create([
            'recruitment_candidate_id' => $candidate->id, 'contract_type' => 'thuc_tap', 'salary' => 3_000_000,
            'start_date' => '2026-10-15', 'response_deadline' => '2026-10-12', 'status' => RecruitmentOffer::STATUS_ACCEPTED,
        ]);
        $commune = Commune::where('province_code', $province->code)->firstOrFail();

        $payload = fn (string $email, string $cccd) => [
            'full_name' => 'Nguyen Tuyen Dung', 'company_email' => $email, 'hire_date' => '2026-10-15',
            'date_of_birth' => '2003-01-01', 'gender' => 'male', 'phone' => '090'.substr($cccd, -7), 'personal_email' => "p{$cccd}@mail.com",
            'cccd' => $cccd, 'personal_tax_code' => 'MST'.$cccd, 'address_detail' => 'Ha Noi',
            'province_code' => $province->code, 'commune_code' => $commune->code,
            'department_id' => $opening->department_id, 'contract_type' => 'thuc_tap', 'agreed_salary' => 3_000_000,
            'candidate_id' => $candidate->id,
        ];

        $employeeId = $this->postJson('/api/v1/employees', $payload('tuyen1@qlns.local', '001203000001'), $this->as('hr'))
            ->assertCreated()->json('data.id');

        $candidate->refresh();
        $this->assertSame('hired', $candidate->status);
        $this->assertSame($employeeId, $candidate->hired_employee_id);
        $this->assertSame('intern', Employee::find($employeeId)->employment_status);

        // Nhận việc lần 2 cho cùng ứng viên -> 422 và KHÔNG tạo thêm nhân viên nào.
        $before = Employee::count();
        $this->postJson('/api/v1/employees', $payload('tuyen2@qlns.local', '001203000002'), $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('candidate_id');
        $this->assertSame($before, Employee::count());
    }

    public function test_cannot_hire_a_candidate_who_has_not_passed(): void
    {
        $opening = $this->createOpening();
        $candidate = $this->addCandidate($opening, 'pending@mail.com');

        try {
            $employee = Employee::create([
                'full_name' => 'NV test', 'company_email' => 'nvtest@qlns.local', 'hire_date' => '2026-10-15',
                'code' => 'NV-TEST', 'department_id' => $opening->department_id,
            ]);
            app(RecruitmentService::class)->markHired($candidate->id, $employee);
            $this->fail('markHired phải chặn ứng viên chưa đạt phỏng vấn.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('candidate_id', $e->errors());
        }
        $this->assertSame('pending', $candidate->fresh()->status);
    }
}
