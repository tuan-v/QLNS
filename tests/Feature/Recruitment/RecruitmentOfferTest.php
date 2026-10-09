<?php

namespace Tests\Feature\Recruitment;

use App\Mail\CandidateOfferMail;
use App\Models\Commune;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Province;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Thư mời nhận việc: HR soạn -> Admin duyệt -> email có link bảo mật -> ứng viên trả lời;
// từ chối/hết hạn trả lại suất; chỉ 1 offer đang mở; link rút/sai không dùng được.
class RecruitmentOfferTest extends TestCase
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

    private function passedCandidate(int $headcount = 1, string $email = 'ungvien@mail.com'): RecruitmentCandidate
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $openingId = $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Lap trinh vien PHP', 'department_id' => $department->id,
            'contract_type' => 'thu_viec', 'headcount' => $headcount, 'deadline' => '2026-10-31',
        ], $this->as('hr'))->assertCreated()->json('data.id');

        $candidateId = $this->post("/api/v1/recruitment/openings/{$openingId}/candidates", [
            'full_name' => 'Ung Vien Offer', 'email' => $email, 'phone' => '09'.str_pad((string) (crc32($email) % 100000000), 8, '0', STR_PAD_LEFT),
            'cv_file' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ], $this->as('hr') + ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/review", ['status' => 'approved'], $this->as('admin'))->assertOk();
        $province = Province::query()->firstOrFail();
        $commune = Commune::where('province_code', $province->code)->firstOrFail();
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/interview", [
            'scheduled_at' => '2026-10-07 09:00', 'format' => 'onsite',
            'province_code' => $province->code, 'commune_code' => $commune->code, 'address_detail' => 'Phong hop tang 3',
        ], $this->as('hr'))->assertOk();
        $this->travelTo(Carbon::parse('2026-10-07 10:00'));
        $this->postJson("/api/v1/recruitment/candidates/{$candidateId}/result", ['result' => 'passed'], $this->as('hr'))->assertOk();

        return RecruitmentCandidate::findOrFail($candidateId);
    }

    private function offerPayload(array $overrides = []): array
    {
        return array_merge([
            'contract_type' => 'thu_viec', 'salary' => 15_000_000,
            'start_date' => '2026-10-20', 'response_deadline' => '2026-10-10', 'message' => 'Mong ban som gia nhap.',
        ], $overrides);
    }

    private function createOffer(RecruitmentCandidate $candidate, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/offers", $this->offerPayload($overrides), $this->as('hr'));
    }

    // Admin duyệt -> lấy mã link từ email đã xếp hàng gửi.
    private function approveAndGetToken(int $offerId): string
    {
        $this->postJson("/api/v1/recruitment/offers/{$offerId}/review", ['status' => 'approved'], $this->as('admin'))
            ->assertOk()->assertJsonPath('data.status', 'sent');

        $url = null;
        Mail::assertQueued(CandidateOfferMail::class, function (CandidateOfferMail $mail) use ($offerId, &$url) {
            if ($mail->offer->id === $offerId) {
                $url = $mail->responseUrl;
            }

            return $mail->offer->id === $offerId;
        });

        return substr($url, strrpos($url, '/') + 1);
    }

    public function test_hr_drafts_and_only_admin_approves(): void
    {
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate)->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data.id');

        $this->assertTrue(Notification::where('type', 'recruitment.offer_pending')->exists());
        Mail::assertNotQueued(CandidateOfferMail::class);

        $this->postJson("/api/v1/recruitment/offers/{$offerId}/review", ['status' => 'approved'], $this->as('hr'))->assertForbidden();
        $this->createOffer($candidate)->assertStatus(422)->assertJsonValidationErrors('candidate');
        $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/offers", $this->offerPayload(), $this->as('employee'))->assertForbidden();

        // Không duyệt bắt buộc lý do; không duyệt xong HR soạn lại được.
        $this->postJson("/api/v1/recruitment/offers/{$offerId}/review", ['status' => 'rejected'], $this->as('admin'))
            ->assertStatus(422)->assertJsonValidationErrors('review_note');
        $this->postJson("/api/v1/recruitment/offers/{$offerId}/review", ['status' => 'rejected', 'review_note' => 'Luong cao'], $this->as('admin'))
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->createOffer($candidate, ['salary' => 12_000_000])->assertCreated();
    }

    public function test_validation_and_only_passed_candidates(): void
    {
        $candidate = $this->passedCandidate();
        $this->createOffer($candidate, ['response_deadline' => '2026-10-25'])
            ->assertStatus(422)->assertJsonValidationErrors('response_deadline');

        $candidate->forceFill(['status' => 'approved'])->save();
        $this->createOffer($candidate)->assertStatus(422)->assertJsonValidationErrors('candidate');
    }

    public function test_candidate_accepts_through_public_link_once(): void
    {
        $candidate = $this->passedCandidate();
        $token = $this->approveAndGetToken($this->createOffer($candidate)->json('data.id'));

        $page = $this->getJson("/api/v1/public/offers/{$token}")->assertOk()->json('data');
        $this->assertTrue($page['can_respond']);
        $this->assertSame(15000000.0, (float) $page['salary']);
        $this->assertArrayNotHasKey('review_note', $page);

        $this->postJson("/api/v1/public/offers/{$token}/respond", ['decision' => 'accept'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->postJson("/api/v1/public/offers/{$token}/respond", ['decision' => 'decline'])
            ->assertStatus(422)->assertJsonValidationErrors('decision');

        $this->assertSame('passed', $candidate->fresh()->status);
        $this->assertTrue(Notification::where('type', 'recruitment.offer_responded')->exists());
    }

    public function test_decline_frees_the_slot(): void
    {
        $candidate = $this->passedCandidate(headcount: 1);
        $this->assertSame(RecruitmentOpening::STATUS_FULL, $candidate->opening->fresh()->status);
        $token = $this->approveAndGetToken($this->createOffer($candidate)->json('data.id'));

        $this->postJson("/api/v1/public/offers/{$token}/respond", ['decision' => 'decline', 'note' => 'Da nhan noi khac'])->assertOk();

        $this->assertSame(RecruitmentCandidate::STATUS_OFFER_DECLINED, $candidate->fresh()->status);
        $this->assertSame(RecruitmentOpening::STATUS_OPEN, $candidate->opening->fresh()->status);
        $this->assertSame('Da nhan noi khac', RecruitmentOffer::firstOrFail()->response_note);
    }

    public function test_offer_expires_after_deadline(): void
    {
        $candidate = $this->passedCandidate();
        $token = $this->approveAndGetToken($this->createOffer($candidate)->json('data.id'));

        $this->travelTo(Carbon::parse('2026-10-11 08:00'));
        $this->artisan('recruitment:expire-offers')->assertSuccessful();

        $page = $this->getJson("/api/v1/public/offers/{$token}")->assertOk()->json('data');
        $this->assertSame('expired', $page['status']);
        $this->assertFalse($page['can_respond']);
        $this->postJson("/api/v1/public/offers/{$token}/respond", ['decision' => 'accept'])->assertStatus(422);
        $this->assertSame(RecruitmentCandidate::STATUS_OFFER_DECLINED, $candidate->fresh()->status);
    }

    public function test_withdrawn_or_unknown_link_does_not_work(): void
    {
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate)->json('data.id');
        $token = $this->approveAndGetToken($offerId);

        $this->postJson("/api/v1/recruitment/offers/{$offerId}/withdraw", [], $this->as('hr'))->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->getJson("/api/v1/public/offers/{$token}")->assertNotFound();
        $this->getJson('/api/v1/public/offers/'.str_repeat('a', 48))->assertNotFound();

        // Rút xong ứng viên vẫn "Đạt" -> soạn offer mới được.
        $this->assertSame('passed', $candidate->fresh()->status);
        $this->createOffer($candidate)->assertCreated();
    }
}
