<?php

namespace Tests\Feature\Recruitment;

use App\Models\Commune;
use App\Models\Department;
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

// "Tuyển dụng chung": đợt không phòng ban/chức vụ, không giới hạn số lượng; mỗi ứng viên
// có vị trí ứng tuyển + hình thức mong muốn; bộ câu hỏi theo ứng viên (riêng > vị trí > đợt);
// phòng ban/chức vụ chốt trong offer.
class GeneralRecruitmentTest extends TestCase
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
        ][$who];

        $this->tokens[$who] ??= $this->postJson('/api/v1/auth/login', ['email' => $credentials[0], 'password' => $credentials[1]])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$who]];
    }

    private function generalOpening(): int
    {
        return $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Tuyen dung chung Q4', 'contract_type' => 'tat_ca', 'deadline' => '2026-12-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');
    }

    private function position(string $name): Position
    {
        $department = Department::create(['name' => 'Phong '.$name, 'code' => 'PB-'.uniqid()]);

        return Position::create(['department_id' => $department->id, 'code' => 'CV-'.uniqid(), 'name' => $name, 'is_active' => 1]);
    }

    private function set(string $name): int
    {
        return $this->postJson('/api/v1/recruitment/question-sets', [
            'name' => $name, 'pass_percent' => 60, 'questions' => [['content' => "Cau {$name}", 'weight' => 1]],
        ], $this->as('admin'))->assertCreated()->json('data.id');
    }

    private function apply(int $openingId, string $email, array $extra = []): TestResponse
    {
        return $this->post("/api/v1/recruitment/openings/{$openingId}/candidates", $extra + [
            'full_name' => 'Ung vien '.$email, 'email' => $email,
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json']);
    }

    // Duyệt CV + hẹn PV + ứng viên đồng ý tham gia -> trả id buổi PV.
    private function confirmedInterview(int $candidateId): int
    {
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/review", ['status' => 'approved'], $this->as('admin'))->assertOk();
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/interview", [
            'scheduled_at' => '2026-10-15 09:00', 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop',
        ], $this->as('hr'))->assertOk();
        $interviewId = RecruitmentCandidate::findOrFail($candidateId)->interviews()->value('id');
        $this->postJson("/api/v1/recruitment/interviews/{$interviewId}/confirmation", ['status' => 'confirmed'], $this->as('hr'))->assertOk();

        return $interviewId;
    }

    public function test_general_opening_has_no_department_position_or_limit(): void
    {
        $openingId = $this->generalOpening();
        $this->getJson("/api/v1/recruitment/openings/{$openingId}", $this->as('hr'))->assertOk()
            ->assertJsonPath('data.department_id', null)
            ->assertJsonPath('data.position_id', null)
            ->assertJsonPath('data.headcount', null);

        $it = $this->position('Nhan vien IT');
        $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Sai', 'position_id' => $it->id, 'contract_type' => 'tat_ca',
        ], $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('position_id');
        $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Theo phong ban', 'department_id' => $it->department_id, 'contract_type' => 'tat_ca',
        ], $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('headcount');

        // Không giới hạn: duyệt bao nhiêu CV cũng vẫn "open", vẫn nhận CV.
        foreach (['a@mail.com', 'b@mail.com', 'c@mail.com'] as $email) {
            $id = $this->apply($openingId, $email)->assertCreated()->json('data.id');
            $this->postJson("/api/v1/recruitment/candidates/{$id}/review", ['status' => 'approved'], $this->as('admin'))->assertOk();
        }
        $this->getJson("/api/v1/recruitment/openings/{$openingId}", $this->as('hr'))->assertJsonPath('data.status', 'open');
        $this->apply($openingId, 'd@mail.com')->assertCreated();
    }

    public function test_candidates_record_applied_position_and_desired_contract(): void
    {
        $openingId = $this->generalOpening();
        $it = $this->position('Nhan vien IT');

        $a = $this->apply($openingId, 'tts@mail.com', ['desired_contract_type' => 'thuc_tap'])->assertCreated()->json('data');
        $c = $this->apply($openingId, 'it@mail.com', ['applied_position_id' => $it->id, 'desired_contract_type' => 'chinh_thuc'])->assertCreated()->json('data');
        $this->assertSame('thuc_tap', $a['desired_contract_type']);
        $this->assertNull($a['applied_position_id']);
        $this->assertSame($it->id, $c['applied_position_id']);
        $this->apply($openingId, 'x@mail.com', ['desired_contract_type' => 'part_time'])->assertStatus(422)->assertJsonValidationErrors('desired_contract_type');

        // HR sửa lại vị trí ứng tuyển.
        $this->putJson("/api/v1/recruitment/candidates/{$a['id']}/application", ['applied_position_id' => $it->id, 'desired_contract_type' => 'thuc_tap'], $this->as('hr'))
            ->assertOk()->assertJsonPath('data.applied_position_id', $it->id);

        // Đợt theo chức vụ: vị trí ứng tuyển tự = chức vụ của đợt, không sửa riêng được.
        $specific = $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Tuyen IT', 'department_id' => $it->department_id, 'position_id' => $it->id,
            'contract_type' => 'tat_ca', 'headcount' => 2, 'deadline' => '2026-12-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');
        $s = $this->apply($specific, 'it2@mail.com')->assertCreated()->json('data');
        $this->assertSame($it->id, $s['applied_position_id']);
        $this->putJson("/api/v1/recruitment/candidates/{$s['id']}/application", ['applied_position_id' => null], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('applied_position_id');
    }

    public function test_question_sets_follow_candidate_then_position_then_opening(): void
    {
        $openingId = $this->generalOpening();
        $it = $this->position('Nhan vien IT');
        $common = $this->set('Chung');
        $itSet = $this->set('IT');
        $custom = $this->set('Rieng');
        $this->putJson("/api/v1/recruitment/positions/{$it->id}/question-sets", ['question_sets' => [['id' => $itSet, 'weight' => 100]]], $this->as('admin'))->assertOk();
        $this->putJson("/api/v1/recruitment/openings/{$openingId}/question-sets", ['question_sets' => [['id' => $common, 'weight' => 100]]], $this->as('admin'))->assertOk();

        $itCandidate = $this->apply($openingId, 'it@mail.com', ['applied_position_id' => $it->id])->json('data.id');
        $intern = $this->apply($openingId, 'tts@mail.com', ['desired_contract_type' => 'thuc_tap'])->json('data.id');

        $candidates = collect($this->getJson("/api/v1/recruitment/openings/{$openingId}", $this->as('hr'))->json('data.candidates'))->keyBy('id');
        $this->assertSame(['position', 'IT'], [$candidates[$itCandidate]['question_set_source'], $candidates[$itCandidate]['question_sets'][0]['name']]);
        $this->assertSame(['opening', 'Chung'], [$candidates[$intern]['question_set_source'], $candidates[$intern]['question_sets'][0]['name']]);

        // Admin chọn riêng cho ứng viên (HR không được).
        $this->putJson("/api/v1/recruitment/candidates/{$intern}/question-sets", ['question_sets' => [['id' => $custom, 'weight' => 100]]], $this->as('hr'))->assertForbidden();
        $this->putJson("/api/v1/recruitment/candidates/{$intern}/question-sets", ['question_sets' => [['id' => $custom, 'weight' => 60], ['id' => $common, 'weight' => 40]]], $this->as('admin'))
            ->assertOk()->assertJsonPath('data.question_set_source', 'candidate');

        // Phiếu chấm dùng đúng bộ của từng ứng viên.
        $itInterview = $this->confirmedInterview($itCandidate);
        $this->postJson("/api/v1/recruitment/interviews/{$itInterview}/scorecard", [], $this->as('hr'))->assertSuccessful()
            ->assertJsonPath('data.question_set_name', 'IT');
        $internInterview = $this->confirmedInterview($intern);
        $this->postJson("/api/v1/recruitment/interviews/{$internInterview}/scorecard", [], $this->as('hr'))->assertSuccessful()
            ->assertJsonPath('data.question_set_name', 'Rieng + Chung');

        // Đã có phiếu chấm -> không đổi bộ của ứng viên nữa; ghi kết quả tay bị chặn.
        $this->putJson("/api/v1/recruitment/candidates/{$intern}/question-sets", ['question_sets' => []], $this->as('admin'))
            ->assertStatus(422)->assertJsonValidationErrors('question_sets');
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->postJson("/api/v1/recruitment/candidates/{$intern}/result", ['result' => 'passed'], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('result');
    }

    public function test_offer_decides_department_and_position(): void
    {
        $openingId = $this->generalOpening();
        $marketing = $this->position('Nhan vien Marketing');
        $other = $this->position('Ke toan');
        $candidateId = $this->apply($openingId, 'mkt@mail.com', ['applied_position_id' => $marketing->id])->json('data.id');
        $this->confirmedInterview($candidateId);
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/result", ['result' => 'passed'], $this->as('hr'))->assertOk();

        $payload = ['contract_type' => 'thu_viec', 'salary' => 9000000, 'start_date' => '2026-11-01', 'response_deadline' => '2026-10-25'];
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/offers", $payload, $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/offers", $payload + ['department_id' => $marketing->department_id, 'position_id' => $other->id], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('position_id');

        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/offers", $payload + ['department_id' => $marketing->department_id, 'position_id' => $marketing->id], $this->as('hr'))
            ->assertCreated()
            ->assertJsonPath('data.department_id', $marketing->department_id)
            ->assertJsonPath('data.position_id', $marketing->id);

        $offer = collect($this->getJson("/api/v1/recruitment/openings/{$openingId}", $this->as('hr'))->json('data.candidates'))->firstWhere('id', $candidateId)['offer'];
        $this->assertSame(['Phong Nhan vien Marketing', 'Nhan vien Marketing'], [$offer['department'], $offer['position']]);

        // Có offer đang mở -> thông tin ứng tuyển đã chốt, không sửa được; hủy offer thì sửa lại được.
        $this->putJson("/api/v1/recruitment/candidates/{$candidateId}/application", ['source' => 'topcv'], $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('candidate');
        $this->postJson("/api/v1/recruitment/offers/{$offer['id']}/withdraw", [], $this->as('hr'))->assertOk();
        $this->putJson("/api/v1/recruitment/candidates/{$candidateId}/application", ['source' => 'topcv'], $this->as('hr'))
            ->assertOk()->assertJsonPath('data.source', 'topcv');
    }
}
