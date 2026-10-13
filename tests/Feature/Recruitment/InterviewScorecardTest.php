<?php

namespace Tests\Feature\Recruitment;

use App\Models\Commune;
use App\Models\Department;
use App\Models\InterviewQuestionSet;
use App\Models\InterviewScorecard;
use App\Models\Position;
use App\Models\Province;
use App\Models\RecruitmentCandidate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Phiếu chấm phỏng vấn: Admin soạn bộ câu hỏi (trọng số + % đạt) và gắn đợt tuyển (nhiều bộ,
// có tỉ trọng; mặc định theo chức vụ); chỉ chấm khi ứng viên đã đồng ý PV; % theo trọng số
// -> tự ra Đạt/Không đạt; phiếu chép câu hỏi.
class InterviewScorecardTest extends TestCase
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

    // Mặc định: bộ 2 câu, trọng số 3 và 1, đạt từ 70%.
    private function createSet(string $name = 'Backend PHP', int $pass = 70, ?array $questions = null): int
    {
        return $this->postJson('/api/v1/recruitment/question-sets', [
            'name' => $name, 'pass_percent' => $pass,
            'questions' => $questions ?? [
                ['content' => 'Kien thuc Laravel', 'guidance' => 'Service, Repository', 'weight' => 3],
                ['content' => 'Giao tiep', 'weight' => 1],
            ],
        ], $this->as('admin'))->assertCreated()->json('data.id');
    }

    private function assignSets(int $openingId, array $sets): TestResponse
    {
        return $this->putJson("/api/v1/recruitment/openings/{$openingId}/question-sets", ['question_sets' => $sets], $this->as('admin'));
    }

    // Ứng viên được hẹn PV lúc 15/10 09:00; đợt tuyển 1 suất.
    // $sets: null = 1 bộ mặc định 100%; [] = không gắn bộ; hoặc [['id'=>, 'weight'=>], ...].
    private function scheduledCandidate(?array $sets = null): RecruitmentCandidate
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $this->openingId = $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Backend', 'department_id' => $department->id,
            'contract_type' => 'tat_ca', 'headcount' => 1, 'deadline' => '2026-10-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');

        $sets ??= [['id' => $this->createSet(), 'weight' => 100]];
        if ($sets !== []) {
            $this->assignSets($this->openingId, $sets)->assertOk();
        }

        $candidateId = $this->post("/api/v1/recruitment/openings/{$this->openingId}/candidates", [
            'full_name' => 'Ung Vien Cham', 'email' => 'cham@mail.com', 'phone' => '0912345670',
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/review", ['status' => 'approved'], $this->as('admin'))->assertOk();
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/interview", [
            'scheduled_at' => '2026-10-15 09:00', 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop',
        ], $this->as('hr'))->assertOk();

        return RecruitmentCandidate::findOrFail($candidateId);
    }

    private function interviewId(RecruitmentCandidate $candidate): int
    {
        return $candidate->interviews()->latest('id')->value('id');
    }

    private function confirm(int $interviewId): void
    {
        $this->postJson("/api/v1/recruitment/interviews/{$interviewId}/confirmation", ['status' => 'confirmed'], $this->as('hr'))->assertOk();
    }

    private function scores(array $scorecard, array $values): array
    {
        return ['items' => collect($scorecard['items'])->values()->map(fn ($item, $i) => ['id' => $item['id'], 'score' => $values[$i] ?? null])->all()];
    }

    private function submit(int $scorecardId, array $payload, string $who = 'hr'): TestResponse
    {
        return $this->postJson("/api/v1/recruitment/scorecards/{$scorecardId}/submit", $payload, $this->as($who));
    }

    public function test_only_admin_edits_question_sets_hr_can_read(): void
    {
        $this->postJson('/api/v1/recruitment/question-sets', ['name' => 'X', 'pass_percent' => 50, 'questions' => [['content' => 'A', 'weight' => 1]]], $this->as('hr'))
            ->assertForbidden();
        $this->createSet();
        $this->getJson('/api/v1/recruitment/question-sets', $this->as('hr'))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/recruitment/question-sets', ['name' => 'X', 'pass_percent' => 120, 'questions' => [['content' => 'A', 'weight' => 0]]], $this->as('admin'))
            ->assertStatus(422)->assertJsonValidationErrors(['pass_percent', 'questions.0.weight']);
    }

    public function test_scorecard_needs_confirmed_interview_and_question_set(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);

        $this->getJson("/api/v1/recruitment/interviews/{$id}/scorecard", $this->as('hr'))->assertOk()
            ->assertJsonPath('scorecard', null)
            ->assertJsonPath('start_blocker', 'Chỉ chấm điểm khi ứng viên đã đồng ý tham gia phỏng vấn.');
        $this->postJson("/api/v1/recruitment/interviews/{$id}/scorecard", [], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('scorecard');

        $this->confirm($id);
        $this->postJson("/api/v1/recruitment/interviews/{$id}/scorecard", [], $this->as('hr'))->assertSuccessful()
            ->assertJsonCount(2, 'data.items')->assertJsonPath('data.pass_percent', 70);
    }

    public function test_weighted_score_passes_and_records_result(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);
        $this->confirm($id);
        $scorecard = $this->postJson("/api/v1/recruitment/interviews/{$id}/scorecard", [], $this->as('hr'))->json('data');

        // Nháp được để trống câu.
        $this->putJson("/api/v1/recruitment/scorecards/{$scorecard['id']}", $this->scores($scorecard, [5]), $this->as('hr'))->assertOk();

        // Chưa tới giờ PV -> không nộp được, phiếu vẫn là nháp.
        $this->submit($scorecard['id'], $this->scores($scorecard, [5, 1]))->assertStatus(422)->assertJsonValidationErrors('result');
        $this->assertSame(InterviewScorecard::STATUS_DRAFT, InterviewScorecard::findOrFail($scorecard['id'])->status);

        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->submit($scorecard['id'], $this->scores($scorecard, [5]))->assertStatus(422)->assertJsonValidationErrors('items');

        // (3×5 + 1×1) / (3×5 + 1×5) = 16/20 = 80% >= 70% -> Đạt. Admin cũng chấm được.
        $this->submit($scorecard['id'], $this->scores($scorecard, [5, 1]) + ['overall_note' => 'Tot'], 'admin')->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.score_percent', 80)
            ->assertJsonPath('data.result', 'passed');
        $this->assertSame('passed', $candidate->fresh()->status);
        $this->assertSame('completed', $candidate->interviews()->first()->status);

        $this->submit($scorecard['id'], $this->scores($scorecard, [1, 1]))->assertStatus(422)->assertJsonValidationErrors('scorecard');
    }

    public function test_below_pass_percent_fails_and_frees_slot(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);
        $this->confirm($id);
        $scorecard = $this->postJson("/api/v1/recruitment/interviews/{$id}/scorecard", [], $this->as('hr'))->json('data');
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));

        // (3×3 + 1×3) / 20 = 60% < 70% -> Không đạt.
        $this->submit($scorecard['id'], $this->scores($scorecard, [3, 3]))->assertOk()
            ->assertJsonPath('data.score_percent', 60)->assertJsonPath('data.result', 'failed');
        $this->assertSame('failed', $candidate->fresh()->status);
        $this->assertSame('open', $candidate->opening->fresh()->status);
    }

    public function test_manual_result_blocked_when_opening_uses_question_set(): void
    {
        $candidate = $this->scheduledCandidate();
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));

        $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/result", ['result' => 'passed'], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('result');
    }

    public function test_editing_set_later_does_not_change_started_scorecard(): void
    {
        $candidate = $this->scheduledCandidate();
        $id = $this->interviewId($candidate);
        $this->confirm($id);
        $scorecard = $this->postJson("/api/v1/recruitment/interviews/{$id}/scorecard", [], $this->as('hr'))->json('data');
        $setId = $candidate->opening->questionSets()->value('interview_question_sets.id');

        $this->putJson("/api/v1/recruitment/question-sets/{$setId}", [
            'name' => 'Backend PHP v2', 'pass_percent' => 90, 'questions' => [['content' => 'Cau moi', 'weight' => 10]],
        ], $this->as('admin'))->assertOk();

        $after = $this->getJson("/api/v1/recruitment/interviews/{$id}/scorecard", $this->as('hr'))->json('scorecard');
        $this->assertSame($scorecard['items'], $after['items']);
        $this->assertSame(70, (int) $after['pass_percent']);
    }

    public function test_multiple_sets_are_weighted_into_sections(): void
    {
        // Văn hóa (đạt 60%) tỉ trọng 30% + Chuyên môn (đạt 80%) tỉ trọng 70% -> đạt từ 0.3×60 + 0.7×80 = 74%.
        $culture = $this->createSet('Van hoa', 60, [['content' => 'Thai do', 'weight' => 1]]);
        $skill = $this->createSet('Chuyen mon', 80, [['content' => 'Laravel', 'weight' => 1], ['content' => 'SQL', 'weight' => 1]]);
        $candidate = $this->scheduledCandidate([['id' => $culture, 'weight' => 30], ['id' => $skill, 'weight' => 70]]);
        $id = $this->interviewId($candidate);
        $this->confirm($id);

        $show = $this->getJson("/api/v1/recruitment/interviews/{$id}/scorecard", $this->as('hr'))->assertOk();
        $this->assertSame([30, 70], array_column($show->json('question_sets'), 'weight'));

        $scorecard = $this->postJson("/api/v1/recruitment/interviews/{$id}/scorecard", [], $this->as('hr'))->assertSuccessful()
            ->assertJsonPath('data.pass_percent', 74)
            ->assertJsonPath('data.question_set_name', 'Van hoa + Chuyen mon')
            ->assertJsonCount(2, 'data.sections')
            ->json('data');
        $this->assertSame([0, 1, 1], array_column($scorecard['items'], 'section_index'));

        // Văn hóa 5/5 = 100%, Chuyên môn (4+3)/10 = 70% -> 0.3×100 + 0.7×70 = 79% >= 74% -> Đạt.
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $result = $this->submit($scorecard['id'], $this->scores($scorecard, [5, 4, 3]))->assertOk()
            ->assertJsonPath('data.score_percent', 79)
            ->assertJsonPath('data.result', 'passed')
            ->json('data.sections');
        $this->assertSame([100, 70], array_map('intval', array_column($result, 'percent')));
        $this->assertSame('passed', $candidate->fresh()->status);
    }

    public function test_weights_must_total_100_and_sets_must_be_active(): void
    {
        $a = $this->createSet('A');
        $b = $this->createSet('B');
        $candidate = $this->scheduledCandidate([]);

        $this->assignSets($candidate->recruitment_opening_id, [['id' => $a, 'weight' => 50], ['id' => $b, 'weight' => 40]])
            ->assertStatus(422)->assertJsonValidationErrors('question_sets');
        $this->assignSets($candidate->recruitment_opening_id, [['id' => $a, 'weight' => 50], ['id' => $a, 'weight' => 50]])
            ->assertStatus(422)->assertJsonValidationErrors('question_sets.1.id');
        InterviewQuestionSet::whereKey($b)->update(['is_active' => false]);
        $this->assignSets($candidate->recruitment_opening_id, [['id' => $a, 'weight' => 50], ['id' => $b, 'weight' => 50]])
            ->assertStatus(422)->assertJsonValidationErrors('question_sets');
        $this->putJson("/api/v1/recruitment/openings/{$candidate->recruitment_opening_id}/question-sets", ['question_sets' => [['id' => $a, 'weight' => 100]]], $this->as('hr'))
            ->assertForbidden();

        // Bỏ hết bộ -> không còn bị chặn ghi kết quả tay.
        $this->assignSets($candidate->recruitment_opening_id, [['id' => $a, 'weight' => 100]])->assertOk();
        $this->assignSets($candidate->recruitment_opening_id, [])->assertOk()->assertJsonPath('data.question_sets', []);
    }

    public function test_position_defaults_are_copied_to_new_openings_and_sets_in_use_cannot_be_deleted(): void
    {
        $department = Department::create(['name' => 'Phong Dev', 'code' => 'PB-DEV']);
        $position = Position::create(['department_id' => $department->id, 'code' => 'CV-DEV', 'name' => 'Lap trinh vien', 'is_active' => 1]);
        $culture = $this->createSet('Van hoa');
        $skill = $this->createSet('Chuyen mon');

        $this->putJson("/api/v1/recruitment/positions/{$position->id}/question-sets", ['question_sets' => [['id' => $skill, 'weight' => 70], ['id' => $culture, 'weight' => 30]]], $this->as('hr'))
            ->assertForbidden();
        $this->putJson("/api/v1/recruitment/positions/{$position->id}/question-sets", ['question_sets' => [['id' => $skill, 'weight' => 70], ['id' => $culture, 'weight' => 30]]], $this->as('admin'))
            ->assertOk();
        $listed = collect($this->getJson('/api/v1/recruitment/position-question-sets', $this->as('hr'))->assertOk()->json('data'))->firstWhere('id', $position->id);
        $this->assertSame(['Chuyen mon', 'Van hoa'], array_column($listed['question_sets'], 'name'));

        // HR tạo đợt tuyển cho chức vụ -> tự có 2 bộ theo đúng tỉ trọng + thứ tự.
        $openingId = $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Tuyen Dev', 'department_id' => $department->id, 'position_id' => $position->id,
            'contract_type' => 'tat_ca', 'headcount' => 2, 'deadline' => '2026-10-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');
        $sets = $this->getJson("/api/v1/recruitment/openings/{$openingId}", $this->as('hr'))->assertOk()->json('data.question_sets');
        $this->assertSame([[$skill, 70], [$culture, 30]], array_map(fn ($s) => [$s['id'], $s['weight']], $sets));

        // Admin đổi riêng cho đợt -> chức vụ không đổi.
        $this->assignSets($openingId, [['id' => $skill, 'weight' => 100]])->assertOk();
        $this->assertSame(2, $position->questionSets()->count());

        // Bộ đang dùng không xóa được.
        $this->deleteJson("/api/v1/recruitment/question-sets/{$culture}", [], $this->as('admin'))->assertStatus(422)->assertJsonValidationErrors('set');
        $unused = $this->createSet('Khong dung');
        $this->deleteJson("/api/v1/recruitment/question-sets/{$unused}", [], $this->as('admin'))->assertOk();
    }
}
