<?php

namespace Tests\Feature\Recruitment;

use App\Models\Department;
use App\Models\RecruitmentCandidate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Lịch sử ứng tuyển: tìm theo email (không phân biệt hoa thường) hoặc SĐT trên mọi đợt
// tuyển, gồm cả CV đã xóa; chip "đã nộp N lần trước" ở trang đợt tuyển.
class CandidateHistoryTest extends TestCase
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

    private function opening(string $title): int
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return $this->postJson('/api/v1/recruitment/openings', [
            'title' => $title, 'department_id' => $department->id, 'contract_type' => 'tat_ca', 'headcount' => 3, 'deadline' => '2026-10-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');
    }

    private function apply(int $openingId, string $email, string $phone): int
    {
        return $this->post("/api/v1/recruitment/openings/{$openingId}/candidates", [
            'full_name' => 'Tran Lich Su', 'email' => $email, 'phone' => $phone,
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_history_matches_email_case_insensitively_or_phone_across_openings(): void
    {
        $first = $this->apply($this->opening('Ke toan 2025'), 'An.Tran@Mail.com', '0911111111');
        $this->postJson("/api/v1/recruitment/candidates/{$first}/review", ['status' => 'rejected', 'review_note' => 'Thieu kinh nghiem'], $this->as('admin'))->assertOk();
        $byPhoneOnly = $this->apply($this->opening('Ke toan 2026'), 'khac@mail.com', '0911111111');
        $current = $this->apply($this->opening('Ke toan truong'), 'an.tran@mail.com', '0922222222');

        $byEmail = $this->getJson('/api/v1/recruitment/candidates/history?email=AN.TRAN@mail.com&exclude_id='.$current, $this->as('hr'))->assertOk()->json('data');
        $this->assertSame([$first], array_column($byEmail, 'id'));
        $this->assertSame('rejected', $byEmail[0]['status']);
        $this->assertSame('Thieu kinh nghiem', $byEmail[0]['review_note']);
        $this->assertSame(['key' => 'not_passed', 'label' => 'Không đạt', 'reason' => 'Loại ở vòng CV'], $byEmail[0]['stage']);
        $this->assertSame('Ke toan 2025', $byEmail[0]['opening']['title']);

        $both = $this->getJson('/api/v1/recruitment/candidates/history?email=an.tran@mail.com&phone=0911111111', $this->as('admin'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$first, $byPhoneOnly, $current], array_column($both, 'id'));
    }

    public function test_deleted_cv_is_still_in_history_and_opening_shows_previous_count(): void
    {
        $old = $this->apply($this->opening('Dot cu'), 'lap@mail.com', '0933333333');
        $this->deleteJson("/api/v1/recruitment/candidates/{$old}", [], $this->as('hr'))->assertOk();
        $openingId = $this->opening('Dot moi');
        $this->apply($openingId, 'lap@mail.com', '0944444444');

        $history = $this->getJson('/api/v1/recruitment/candidates/history?email=lap@mail.com', $this->as('hr'))->json('data');
        $deleted = collect($history)->firstWhere('id', $old);
        $this->assertTrue($deleted['deleted']);

        $this->getJson("/api/v1/recruitment/openings/{$openingId}", $this->as('hr'))->assertOk()
            ->assertJsonPath('data.candidates.0.previous_applications_count', 1)
            ->assertJsonPath('data.candidates.0.stage', ['key' => 'screening', 'label' => 'Chờ duyệt CV', 'reason' => null]);
        $this->assertSame(2, RecruitmentCandidate::withTrashed()->where('email', 'lap@mail.com')->count());
    }

    public function test_requires_email_or_phone_and_recruitment_permission(): void
    {
        $this->getJson('/api/v1/recruitment/candidates/history', $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('email');
        $this->getJson('/api/v1/recruitment/candidates/history?email=a@mail.com', $this->as('employee'))->assertForbidden();
    }
}
