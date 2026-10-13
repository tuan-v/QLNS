<?php

namespace Tests\Feature\Recruitment;

use App\Models\Commune;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Province;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOffer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// (1) Chặn trùng lịch phỏng vấn của cùng người phỏng vấn (hẹn hàng loạt tự nhảy khung trống).
// (2) Nguồn ứng viên + báo cáo tuyển dụng (phễu, offer, theo nguồn / vị trí).
class InterviewConflictAndReportTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private int $openingId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-06 09:00'));

        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $this->openingId = $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Backend', 'department_id' => $department->id, 'contract_type' => 'tat_ca', 'headcount' => 20, 'deadline' => '2026-12-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');
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

    private function interviewer(string $name): Employee
    {
        return Employee::create([
            'full_name' => $name, 'company_email' => uniqid().'@qlns.local', 'hire_date' => '2025-01-01',
            'code' => 'NV-'.uniqid(), 'department_id' => Department::first()->id, 'employment_status' => 'active',
        ]);
    }

    private function approvedCandidate(string $email, array $extra = []): RecruitmentCandidate
    {
        $id = $this->post("/api/v1/recruitment/openings/{$this->openingId}/candidates", $extra + [
            'full_name' => 'UV '.$email, 'email' => $email,
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/recruitment/candidates/{$id}/review", ['status' => 'approved'], $this->as('admin'))->assertOk();

        return RecruitmentCandidate::findOrFail($id);
    }

    private function schedule(RecruitmentCandidate $candidate, string $at, ?int $interviewerId, ?int $minutes = null): TestResponse
    {
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();

        return $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/interview", array_filter([
            'scheduled_at' => $at, 'format' => 'onsite', 'duration_minutes' => $minutes,
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop',
            'interviewer_id' => $interviewerId,
        ], fn ($v) => $v !== null), $this->as('hr'));
    }

    public function test_same_interviewer_cannot_have_overlapping_interviews(): void
    {
        $lan = $this->interviewer('Tran Thi Lan');
        $minh = $this->interviewer('Le Van Minh');
        $a = $this->approvedCandidate('a@mail.com');
        $b = $this->approvedCandidate('b@mail.com');

        $this->schedule($a, '2026-10-15 09:00', $lan->id)->assertOk(); // 09:00–10:00 (mặc định 60')

        $this->schedule($b, '2026-10-15 09:30', $lan->id)->assertStatus(422)
            ->assertJsonPath('errors.scheduled_at.0', 'Người phỏng vấn Tran Thi Lan đã có lịch phỏng vấn UV a@mail.com lúc 09:00–10:00 15/10/2026 — chọn giờ khác hoặc người phỏng vấn khác.');
        $this->schedule($b, '2026-10-15 08:30', $lan->id, 45)->assertStatus(422); // 08:30–09:15 chồng 09:00
        // Người phỏng vấn khác, hoặc không chọn người phỏng vấn -> không vướng.
        $this->schedule($b, '2026-10-15 09:30', $minh->id)->assertOk();
        $this->schedule($b, '2026-10-15 09:30', null)->assertOk();
        // Sát giờ (10:00) không tính là trùng; dời chính buổi của A không tự vướng chính nó.
        $this->schedule($b, '2026-10-15 10:00', $lan->id)->assertOk();
        $this->schedule($a, '2026-10-15 09:15', $lan->id, 30)->assertOk();

        $this->assertSame(30, RecruitmentInterview::where('recruitment_candidate_id', $a->id)->value('duration_minutes'));
    }

    // Hẹn hàng loạt chỉ cho CV chưa có lịch; đã có lịch thì phải "Dời lịch" từng người.
    public function test_bulk_scheduling_rejects_candidates_already_scheduled(): void
    {
        $scheduled = $this->approvedCandidate('da-hen@mail.com');
        $this->schedule($scheduled, '2026-10-15 09:00', null)->assertOk();
        $fresh = $this->approvedCandidate('moi@mail.com');
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();

        $result = $this->postJson('/api/v1/recruitment/candidates/bulk-interview', [
            'candidate_ids' => [$scheduled->id, $fresh->id], 'start_at' => '2026-10-16 09:00', 'slot_minutes' => 30, 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop',
        ], $this->as('hr'))->assertOk()->json();

        $this->assertSame(1, $result['done']);
        $this->assertSame('Đã có lịch phỏng vấn — dùng "Dời lịch" cho từng ứng viên.', $result['failed'][0]['message']);
        // Lịch cũ giữ nguyên, ứng viên mới được xếp vào khung đầu tiên.
        $this->assertSame('2026-10-15 09:00', $scheduled->interviews()->value('scheduled_at')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-16 09:00', $fresh->interviews()->value('scheduled_at')->format('Y-m-d H:i'));
    }

    public function test_bulk_scheduling_skips_busy_slots_of_the_interviewer(): void
    {
        $lan = $this->interviewer('Tran Thi Lan');
        $busy = $this->approvedCandidate('busy@mail.com');
        $this->schedule($busy, '2026-10-15 09:30', $lan->id)->assertOk(); // bận 09:30–10:30

        $ids = collect(['x@mail.com', 'y@mail.com', 'z@mail.com'])->map(fn ($e) => $this->approvedCandidate($e)->id)->all();
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();
        $this->postJson('/api/v1/recruitment/candidates/bulk-interview', [
            'candidate_ids' => $ids, 'start_at' => '2026-10-15 09:00', 'slot_minutes' => 30, 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop',
            'interviewer_id' => $lan->id,
        ], $this->as('hr'))->assertOk()->assertJsonPath('done', 3);

        $times = RecruitmentInterview::whereIn('recruitment_candidate_id', $ids)->orderBy('scheduled_at')
            ->get()->map(fn ($i) => $i->scheduled_at->format('H:i').'/'.$i->duration_minutes)->all();
        $this->assertSame(['09:00/30', '10:30/30', '11:00/30'], $times);
    }

    public function test_report_funnel_sources_positions_and_offer_rates(): void
    {
        // 4 CV: 2 TopCV, 1 người giới thiệu, 1 không ghi nguồn.
        $hired = $this->approvedCandidate('hired@mail.com', ['source' => 'topcv']);
        $declined = $this->approvedCandidate('declined@mail.com', ['source' => 'topcv']);
        $referral = $this->approvedCandidate('ref@mail.com', ['source' => 'referral', 'source_note' => 'Anh Nam phong IT']);
        $this->post("/api/v1/recruitment/openings/{$this->openingId}/candidates", [
            'full_name' => 'Cho duyet', 'email' => 'pending@mail.com', 'cv_file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/recruitment/openings/{$this->openingId}/candidates", [
            'full_name' => 'Sai nguon', 'email' => 'bad@mail.com', 'source' => 'tiktok', 'cv_file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('source');

        // Dựng tiến trình thẳng trong DB (không đi qua email): 2 người PV đạt, 1 người hẹn chưa PV.
        foreach ([$hired, $declined] as $c) {
            $c->interviews()->create(['scheduled_at' => '2026-10-07 09:00', 'format' => 'online', 'location' => 'meet', 'status' => 'completed', 'result' => 'passed', 'confirmation_status' => 'confirmed']);
        }
        $referral->interviews()->create(['scheduled_at' => '2026-10-20 09:00', 'format' => 'online', 'location' => 'meet', 'status' => 'scheduled', 'confirmation_status' => 'pending']);
        $hired->offers()->create(['department_id' => Department::first()->id, 'contract_type' => 'thu_viec', 'salary' => 1, 'start_date' => '2026-10-20', 'response_deadline' => '2026-10-10', 'status' => RecruitmentOffer::STATUS_ACCEPTED, 'sent_at' => now(), 'responded_at' => Carbon::parse('2026-10-11 09:00')]);
        $declined->offers()->create(['department_id' => Department::first()->id, 'contract_type' => 'thu_viec', 'salary' => 1, 'start_date' => '2026-10-20', 'response_deadline' => '2026-10-10', 'status' => RecruitmentOffer::STATUS_DECLINED, 'sent_at' => now(), 'responded_at' => now()]);
        $hired->forceFill(['status' => RecruitmentCandidate::STATUS_HIRED])->save();
        $declined->forceFill(['status' => RecruitmentCandidate::STATUS_OFFER_DECLINED])->save();

        $data = $this->getJson('/api/v1/recruitment/report?from=2026-10-01&to=2026-10-31', $this->as('hr'))->assertOk()->json('data');

        $this->assertSame(
            ['received' => 4, 'approved' => 3, 'scheduled' => 3, 'interviewed' => 2, 'passed' => 2, 'offered' => 2, 'accepted' => 1, 'hired' => 1],
            collect($data['funnel'])->pluck('count', 'key')->all(),
        );
        $this->assertSame(50.0, (float) collect($data['funnel'])->firstWhere('key', 'accepted')['percent_of_previous']);
        $this->assertSame(25.0, (float) $data['metrics']['hire_rate']);
        $this->assertSame(50.0, (float) $data['metrics']['offer_acceptance_rate']);
        $this->assertSame(5.0, (float) $data['metrics']['avg_days_to_accept']); // nhận CV 06/10 09:00 -> chấp nhận 11/10 09:00

        $sources = collect($data['by_source'])->keyBy('key');
        $this->assertSame([2, 1, 50.0], [$sources['topcv']['received'], $sources['topcv']['hired'], (float) $sources['topcv']['hire_rate']]);
        $this->assertSame(1, $sources['referral']['received']);
        $this->assertSame('Chưa ghi nguồn', $sources['none']['label']);

        // Lọc theo nguồn; ngoài khoảng ngày -> rỗng; nhân viên thường không xem được.
        $this->getJson('/api/v1/recruitment/report?from=2026-10-01&to=2026-10-31&source=topcv', $this->as('admin'))->assertOk()
            ->assertJsonPath('data.metrics.cv_total', 2);
        $this->getJson('/api/v1/recruitment/report?from=2026-11-01&to=2026-11-30', $this->as('hr'))->assertOk()
            ->assertJsonPath('data.metrics.cv_total', 0);
        $this->getJson('/api/v1/recruitment/report', $this->as('employee'))->assertForbidden();
    }
}
