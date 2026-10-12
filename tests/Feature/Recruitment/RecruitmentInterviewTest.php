<?php

namespace Tests\Feature\Recruitment;

use App\Models\Commune;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Province;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOpening;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Trang "Lịch phỏng vấn": HR + Admin xem theo tháng; HR ghi nhận ứng viên đồng ý / xin
// đổi lịch / không tham gia -> báo Admin; không tham gia thì trả lại suất.
class RecruitmentInterviewTest extends TestCase
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

    // Ứng viên đã được hẹn phỏng vấn; đợt tuyển 1 suất -> đang "Đủ CV".
    private function scheduledCandidate(string $at = '2026-10-15 09:00'): RecruitmentCandidate
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $openingId = $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Ke toan', 'department_id' => $department->id,
            'contract_type' => 'tat_ca', 'headcount' => 1, 'deadline' => '2026-10-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');

        $candidateId = $this->post("/api/v1/recruitment/openings/{$openingId}/candidates", [
            'full_name' => 'Ung Vien Lich', 'email' => 'lich@mail.com', 'phone' => '0912345678',
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/review", ['status' => 'approved'], $this->as('admin'))->assertOk();
        $this->schedule($candidateId, $at)->assertOk();

        return RecruitmentCandidate::findOrFail($candidateId);
    }

    private function schedule(int $candidateId, string $at): TestResponse
    {
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();

        return $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/interview", [
            'scheduled_at' => $at, 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop',
        ], $this->as('hr'));
    }

    private function interviewId(RecruitmentCandidate $candidate): int
    {
        return $candidate->interviews()->latest('id')->value('id');
    }

    private function confirm(int $interviewId, string $status, ?string $note = null, string $who = 'hr'): TestResponse
    {
        return $this->postJson("/api/v1/recruitment/interviews/{$interviewId}/confirmation", ['status' => $status, 'note' => $note], $this->as($who));
    }

    public function test_month_view_is_for_hr_and_admin_with_summary(): void
    {
        $candidate = $this->scheduledCandidate('2026-10-15 09:00');

        $response = $this->getJson('/api/v1/recruitment/interviews?month=2026-10', $this->as('admin'))->assertOk();
        $this->assertSame($candidate->id, $response->json('data.0.candidate.id'));
        $this->assertSame('pending', $response->json('data.0.confirmation_status'));
        $this->assertSame(1, $response->json('summary.total'));
        $this->assertSame(1, $response->json('summary.pending'));

        $this->getJson('/api/v1/recruitment/interviews?month=2026-11', $this->as('hr'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/recruitment/interviews?month=2026-10', $this->as('employee'))->assertForbidden();
        $this->getJson('/api/v1/recruitment/interviews?month=10-2026', $this->as('hr'))->assertStatus(422);
    }

    public function test_hr_records_acceptance_and_admin_is_notified(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);

        $this->confirm($id, 'confirmed', null, 'employee')->assertForbidden();
        $this->confirm($id, 'confirmed', 'Se den dung gio')->assertOk()
            ->assertJsonPath('data.confirmation_status', 'confirmed')
            ->assertJsonPath('data.confirmed_by', 'Nhân sự');

        $this->assertTrue(Notification::where('type', 'recruitment.interview_confirmation')->exists());
        $this->getJson('/api/v1/recruitment/interviews?month=2026-10&confirmation_status=confirmed', $this->as('admin'))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reschedule_request_keeps_slot_and_new_time_resets_confirmation(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);

        $this->confirm($id, 'reschedule_requested', 'Ban sang thu 5')->assertOk();
        $this->assertSame('interview_scheduled', $candidate->fresh()->status);
        $this->assertSame(RecruitmentOpening::STATUS_FULL, $candidate->opening->fresh()->status);

        // HR dời lịch -> cùng buổi phỏng vấn, phản hồi về "chờ xác nhận".
        $this->schedule($candidate->id, '2026-10-16 14:00')->assertOk();
        $this->getJson('/api/v1/recruitment/interviews?month=2026-10', $this->as('hr'))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.confirmation_status', 'pending');
    }

    public function test_withdrawal_requires_reason_cancels_interview_and_frees_slot(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);

        $this->confirm($id, 'withdrawn')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->confirm($id, 'withdrawn', 'Da nhan viec noi khac')->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(RecruitmentCandidate::STATUS_INTERVIEW_DECLINED, $candidate->fresh()->status);
        $this->assertSame(RecruitmentOpening::STATUS_OPEN, $candidate->opening->fresh()->status);

        // Đã hủy -> không ghi nhận / ghi kết quả được nữa.
        $this->confirm($id, 'confirmed')->assertStatus(422);
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/result", ['result' => 'passed'], $this->as('hr'))->assertStatus(422);
    }
}
