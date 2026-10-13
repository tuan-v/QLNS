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
use App\Models\User;
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
        $interviewId = RecruitmentCandidate::findOrFail($candidateId)->interviews()->value('id');
        $this->postJson("/api/v1/recruitment/interviews/{$interviewId}/confirmation", ['status' => 'confirmed'], $this->as('hr'))->assertOk();
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
        // Offer chốt phòng ban (mặc định = phòng ban của đợt tuyển).
        $overrides += ['department_id' => $candidate->opening->department_id];

        return $this->postJson("/api/v1/recruitment/candidates/{$candidate->id}/offers", $this->offerPayload($overrides), $this->as('hr'));
    }

    // Admin duyệt -> email thư mời (không link) đã xếp hàng gửi.
    private function approve(int $offerId): void
    {
        $this->postJson("/api/v1/recruitment/offers/{$offerId}/review", ['status' => 'approved'], $this->as('admin'))
            ->assertOk()->assertJsonPath('data.status', 'sent');
        Mail::assertQueued(CandidateOfferMail::class, fn (CandidateOfferMail $mail) => $mail->offer->id === $offerId);
    }

    private function recordResponse(int $offerId, string $decision, ?string $note = null, string $who = 'hr'): TestResponse
    {
        return $this->postJson("/api/v1/recruitment/offers/{$offerId}/response", ['decision' => $decision, 'note' => $note], $this->as($who));
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

    // Đợt tuyển "tất cả loại hợp đồng": loại cụ thể chỉ chọn lúc soạn offer.
    public function test_opening_may_leave_contract_type_open_but_offer_must_pick_one(): void
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $this->postJson('/api/v1/recruitment/openings', [
            'title' => 'Tuyen nhieu loai', 'department_id' => $department->id,
            'contract_type' => 'tat_ca', 'headcount' => 1, 'deadline' => '2026-10-31',
        ], $this->as('hr'))->assertCreated()->assertJsonPath('data.contract_type', 'tat_ca');

        $candidate = $this->passedCandidate();
        $this->createOffer($candidate, ['contract_type' => 'tat_ca'])
            ->assertStatus(422)->assertJsonValidationErrors('contract_type');
        $this->createOffer($candidate, ['contract_type' => 'chinh_thuc'])
            ->assertCreated()->assertJsonPath('data.contract_type', 'chinh_thuc');
    }

    public function test_validation_and_only_passed_candidates(): void
    {
        $candidate = $this->passedCandidate();
        $this->createOffer($candidate, ['response_deadline' => '2026-10-25'])
            ->assertStatus(422)->assertJsonValidationErrors('response_deadline');

        $candidate->forceFill(['status' => 'approved'])->save();
        $this->createOffer($candidate)->assertStatus(422)->assertJsonValidationErrors('candidate');
    }

    // Email không có link: ghi người liên hệ + EMAIL HỆ THỐNG CÔNG TY (không nhận email cá nhân HR gửi lên), Reply-To về email đó.
    public function test_offer_email_has_contact_info_and_no_link(): void
    {
        config(['recruitment.offer_contact_email' => 'tuyendung@congty.vn']);
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate, ['contact_name' => 'Chi Luu', 'contact_phone' => '0977 000 111', 'contact_email' => 'ca.nhan@gmail.com'])
            ->assertCreated()
            ->assertJsonPath('data.contact_phone', '0977 000 111')
            ->assertJsonPath('data.contact_email', 'tuyendung@congty.vn')
            ->json('data.id');
        $this->approve($offerId);

        Mail::assertQueued(CandidateOfferMail::class, function (CandidateOfferMail $mail) {
            $html = $mail->render();

            return $mail->hasReplyTo('tuyendung@congty.vn') && ! $mail->hasReplyTo('ca.nhan@gmail.com')
                && str_contains($html, 'Chi Luu') && str_contains($html, '0977 000 111') && ! str_contains($html, 'ca.nhan@gmail.com')
                && ! str_contains($html, '<a ') && ! str_contains($html, 'thu-moi-nhan-viec');
        });

        // Trang trả lời công khai đã bỏ.
        $this->getJson('/api/v1/public/offers/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_contact_defaults_to_the_hr_drafting_the_offer_with_company_email(): void
    {
        config(['recruitment.offer_contact_email' => 'tuyendung@congty.vn']);
        $hr = User::with('employee')->where('email', 'hr@qlns.local')->firstOrFail();

        $this->getJson('/api/v1/recruitment/offer-defaults', $this->as('hr'))->assertOk()
            ->assertJsonPath('data.contact_email', 'tuyendung@congty.vn')
            ->assertJsonPath('data.contact_name', $hr->employee?->full_name ?? $hr->user_name);
        $this->getJson('/api/v1/recruitment/offer-defaults', $this->as('employee'))->assertForbidden();

        $offer = RecruitmentOffer::findOrFail($this->createOffer($this->passedCandidate())->assertCreated()->json('data.id'));
        $this->assertSame('tuyendung@congty.vn', $offer->contact_email);
        $this->assertSame($hr->employee?->full_name ?? $hr->user_name, $offer->contact_name);
    }

    public function test_hr_records_acceptance_and_admin_is_notified(): void
    {
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate)->json('data.id');

        // Chưa duyệt gửi -> chưa ghi nhận được.
        $this->recordResponse($offerId, 'accept')->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->approve($offerId);

        $this->recordResponse($offerId, 'accept', null, 'employee')->assertForbidden();
        $this->recordResponse($offerId, 'maybe')->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->recordResponse($offerId, 'accept', 'Goi dien 10h, xac nhan di lam dung ngay')->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.response_recorded_by', 'Nhân sự');
        $this->recordResponse($offerId, 'decline')->assertStatus(422)->assertJsonValidationErrors('decision');

        $this->assertSame('passed', $candidate->fresh()->status);
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $notice = Notification::where('type', 'recruitment.offer_responded')->where('user_id', $admin->id)->firstOrFail();
        $this->assertStringContainsString('đã chấp nhận', $notice->message);
    }

    public function test_decline_frees_the_slot(): void
    {
        $candidate = $this->passedCandidate(headcount: 1);
        $this->assertSame(RecruitmentOpening::STATUS_FULL, $candidate->opening->fresh()->status);
        $offerId = $this->createOffer($candidate)->json('data.id');
        $this->approve($offerId);

        $this->recordResponse($offerId, 'decline', 'Da nhan noi khac')->assertOk()->assertJsonPath('data.status', 'declined');

        $this->assertSame(RecruitmentCandidate::STATUS_OFFER_DECLINED, $candidate->fresh()->status);
        $this->assertSame(RecruitmentOpening::STATUS_OPEN, $candidate->opening->fresh()->status);
        $this->assertSame('Da nhan noi khac', RecruitmentOffer::firstOrFail()->response_note);
    }

    public function test_offer_expires_after_deadline(): void
    {
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate)->json('data.id');
        $this->approve($offerId);

        $this->travelTo(Carbon::parse('2026-10-11 08:00'));
        $this->artisan('recruitment:expire-offers')->assertSuccessful();

        $this->assertSame('expired', RecruitmentOffer::findOrFail($offerId)->status);
        $this->recordResponse($offerId, 'accept')->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->assertSame(RecruitmentCandidate::STATUS_OFFER_DECLINED, $candidate->fresh()->status);
    }

    public function test_withdrawn_offer_cannot_be_answered(): void
    {
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate)->json('data.id');
        $this->approve($offerId);

        $this->postJson("/api/v1/recruitment/offers/{$offerId}/withdraw", [], $this->as('hr'))->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->recordResponse($offerId, 'accept')->assertStatus(422)->assertJsonValidationErrors('decision');

        // Rút xong ứng viên vẫn "Đạt" -> soạn offer mới được.
        $this->assertSame('passed', $candidate->fresh()->status);
        $this->createOffer($candidate)->assertCreated();
    }

    // Admin xem offer rồi sửa ngay khi còn chờ duyệt; HR (người soạn) được báo thay đổi.
    public function test_pending_offer_can_be_edited_before_approval(): void
    {
        $candidate = $this->passedCandidate();
        $offerId = $this->createOffer($candidate)->json('data.id');
        $payload = $this->offerPayload(['department_id' => $candidate->opening->department_id, 'salary' => 18_000_000, 'message' => 'Moi ban nhan viec']);

        $this->putJson("/api/v1/recruitment/offers/{$offerId}", $payload, $this->as('employee'))->assertForbidden();
        $this->putJson("/api/v1/recruitment/offers/{$offerId}", ['salary' => -1] + $payload, $this->as('admin'))
            ->assertStatus(422)->assertJsonValidationErrors('salary');
        $this->putJson("/api/v1/recruitment/offers/{$offerId}", $payload, $this->as('admin'))->assertOk()
            ->assertJsonPath('data.salary', 18000000)
            ->assertJsonPath('data.status', 'pending_approval');

        $hr = User::where('email', 'hr@qlns.local')->firstOrFail();
        $notice = Notification::where('user_id', $hr->id)->where('title', 'Offer đã được chỉnh sửa')->firstOrFail();
        $this->assertStringContainsString('Lương: 15.000.000 ₫ → 18.000.000 ₫', $notice->message);

        // Đã duyệt gửi -> không sửa được nữa.
        $this->approve($offerId);
        $this->putJson("/api/v1/recruitment/offers/{$offerId}", $payload, $this->as('admin'))
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }
}
