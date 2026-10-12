<?php

namespace Tests\Feature\Holiday;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Holiday;
use App\Models\HolidayAnnouncement;
use App\Models\Notification;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\User;
use App\Services\HolidayService;
use App\Services\Payroll\HolidayBonusCalculator;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

// Văn bản thông báo nghỉ lễ (mẫu Word): soạn -> chốt trình ký -> tải lên bản đã ký ->
// phát hành; mục thưởng chỉ có khi bật thưởng; nhân viên chỉ thấy BẢN ĐÃ KÝ đã phát hành;
// thưởng chỉ cộng lương khi đã phát hành, theo loại hợp đồng vào ngày đầu kỳ nghỉ.
class HolidayAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private string $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-08-10 09:00'));

        $this->group = $this->postJson('/api/v1/holidays', [
            'name' => 'Quốc khánh ZZ',
            'calendar' => 'solar',
            'start_date' => '2026-09-01',
            'duration_days' => 2,
        ], $this->as('hr'))->assertCreated()->json('group_code');
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

    private function url(string $suffix = ''): string
    {
        return "/api/v1/holidays/groups/{$this->group}/announcement{$suffix}";
    }

    private function form(array $overrides = []): array
    {
        $form = $this->getJson($this->url(), $this->as('hr'))->assertOk()->json('form');

        return array_replace_recursive($form, $overrides);
    }

    private function withBonus(array $amounts = [500000, 200000, 100000], int $month = 8): array
    {
        $form = $this->form(['has_bonus' => true, 'bonus_payroll_month' => $month, 'bonus_payroll_year' => 2026]);
        foreach ($amounts as $i => $amount) {
            $form['bonus_rows'][$i]['amount'] = $amount;
        }

        return $form;
    }

    private function uploadSigned(): void
    {
        $this->post($this->url('/signed'), ['signed_file' => UploadedFile::fake()->create('da-ky.pdf', 200, 'application/pdf')], $this->as('hr') + ['Accept' => 'application/json'])
            ->assertOk();
    }

    // Đủ quy trình: chốt trình ký -> tải bản đã ký -> phát hành.
    private function publishSigned(array $form): \Illuminate\Testing\TestResponse
    {
        $this->postJson($this->url('/prepare-signing'), $form, $this->as('hr'))->assertOk();
        $this->uploadSigned();

        return $this->postJson($this->url('/publish'), [], $this->as('hr'))->assertOk();
    }

    private function documentText($response): string
    {
        $zip = new ZipArchive();
        $zip->open($response->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return strip_tags(str_replace('</w:p>', "\n", $xml));
    }

    public function test_defaults_come_from_the_schedule_and_drafts_are_hidden_from_employees(): void
    {
        $data = $this->getJson($this->url(), $this->as('hr'))->assertOk()->json();

        $this->assertNull($data['announcement']);
        $this->assertSame('Về việc nghỉ Quốc khánh ZZ năm 2026', $data['form']['content']['title']);
        $this->assertSame('01-26/TB-ASFY TECH', $data['form']['document_number']);
        $this->assertSame('GIÁM ĐỐC', $data['form']['content']['signer_title']);
        $this->assertFalse($data['form']['has_bonus']);
        $this->assertSame([8, 2026], [$data['form']['bonus_payroll_month'], $data['form']['bonus_payroll_year']]);
        $this->assertSame('2026-09-03', $data['schedule']['return_date']);
        $this->assertSame('Thứ Năm', $data['schedule']['return_weekday']);

        $this->putJson($this->url(), $this->form(), $this->as('hr'))->assertOk()->assertJsonPath('announcement.status', 'draft');

        $this->getJson($this->url(), $this->as('employee'))->assertNotFound();
        $this->get($this->url('/docx'), $this->as('employee'))->assertForbidden();
        $this->putJson($this->url(), $this->form(), $this->as('employee'))->assertForbidden();
    }

    public function test_bonus_section_only_appears_when_enabled_and_document_has_a_signature_block(): void
    {
        $plain = $this->documentText($this->post($this->url('/preview'), $this->form(['content' => ['signer_name' => 'Nguyễn Văn ZZ']]), $this->as('hr'))->assertOk());
        $this->assertStringContainsString('THÔNG BÁO', $plain);
        $this->assertStringContainsString('Từ ngày 01/09/2026 đến hết ngày 02/09/2026', $plain);
        $this->assertStringNotContainsString('chi thưởng', $plain);
        $this->assertStringContainsString('3. Trách nhiệm các phòng ban:', $plain);
        $this->assertStringContainsString('GIÁM ĐỐC', $plain);
        $this->assertStringContainsString('Nguyễn Văn ZZ', $plain);
        $this->assertStringContainsString('Nơi nhận:', $plain);

        $bonus = $this->documentText($this->post($this->url('/preview'), $this->withBonus(), $this->as('hr'))->assertOk());
        $this->assertStringContainsString('3. Nội dung chi thưởng Quốc khánh ZZ:', $bonus);
        $this->assertStringContainsString('500.000 đồng', $bonus);
        $this->assertStringContainsString('4. Trách nhiệm các phòng ban:', $bonus);
    }

    public function test_signing_flow_locks_content_requires_signed_pdf_then_publishes_it(): void
    {
        $this->postJson($this->url('/prepare-signing'), $this->withBonus(), $this->as('hr'))->assertOk()
            ->assertJsonPath('announcement.status', 'pending_signature')
            ->assertJsonPath('form.document_number', '01-26/TB-ASFY TECH');

        // Đã chốt: không sửa trực tiếp, tải được Word để in; chưa có bản ký thì chưa phát hành được.
        $this->putJson($this->url(), $this->form(), $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('status');
        $this->get($this->url('/docx'), $this->as('hr'))->assertOk();
        $this->postJson($this->url('/publish'), [], $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('signed_file');

        $this->post($this->url('/signed'), ['signed_file' => UploadedFile::fake()->create('ky.docx', 10)], $this->as('hr') + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('signed_file');
        $this->uploadSigned();
        $this->get($this->url('/signed'), $this->as('employee'))->assertNotFound();
        $this->assertSame(0, Notification::where('type', 'holiday.announcement')->count());

        $response = $this->postJson($this->url('/publish'), [], $this->as('hr'))->assertOk();

        $activeUsers = User::where('status', 'active')->count();
        $this->assertSame($activeUsers, $response->json('recipients'));
        $this->assertSame('published', $response->json('announcement.status'));
        $this->assertSame($activeUsers, Notification::where('type', 'holiday.announcement')->count());
        $this->assertSame(0, Holiday::where('group_code', $this->group)->whereNull('notified_at')->count());

        $this->getJson($this->url(), $this->as('employee'))->assertOk()->assertJsonPath('schedule.start_date', '2026-09-01');
        $this->get($this->url('/signed'), $this->as('employee'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get($this->url('/docx'), $this->as('employee'))->assertForbidden();

        $listed = collect(app(HolidayService::class)->listForYear(2026))->firstWhere('group_code', $this->group);
        $this->assertSame('published', $listed['announcement']['status']);
        $this->assertTrue($listed['announcement']['has_bonus']);
    }

    public function test_reopen_discards_signed_copy_and_payroll_period_locks(): void
    {
        $this->putJson($this->url(), $this->withBonus([0, 0, 0]), $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('bonus_rows');

        $duplicate = $this->withBonus();
        $duplicate['bonus_rows'][1]['category'] = 'official';
        $this->putJson($this->url(), $duplicate, $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('bonus_rows');

        // Kỳ lương tháng 8 đã tạo -> chặn ngay từ lúc chốt trình ký.
        Payroll::create(['period_month' => 8, 'period_year' => 2026, 'status' => 'processing', 'currency' => 'VND', 'created_by' => User::first()->id]);
        $this->postJson($this->url('/prepare-signing'), $this->withBonus(), $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('bonus_payroll_month');

        // Mở lại khi đang chờ ký: bỏ bản đã ký, về nháp, sửa được.
        $this->postJson($this->url('/prepare-signing'), $this->withBonus(month: 9), $this->as('hr'))->assertOk();
        $this->uploadSigned();
        $file = HolidayAnnouncement::first()->signed_file;
        Storage::disk('local')->assertExists($file);
        $this->postJson($this->url('/reopen'), [], $this->as('hr'))->assertOk()
            ->assertJsonPath('announcement.status', 'draft')->assertJsonPath('announcement.signed_file_name', null);
        Storage::disk('local')->assertMissing($file);
        $this->putJson($this->url(), $this->withBonus([700000, 200000, 100000], 9), $this->as('hr'))->assertOk();

        // Đã phát hành + đã tạo bảng lương kỳ chi thưởng -> không mở lại được.
        $this->publishSigned($this->withBonus([700000, 200000, 100000], 9));
        Payroll::create(['period_month' => 9, 'period_year' => 2026, 'status' => 'processing', 'currency' => 'VND', 'created_by' => User::first()->id]);
        $this->postJson($this->url('/reopen'), [], $this->as('hr'))->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_payroll_adds_taxable_bonus_by_contract_type_on_the_first_holiday_day(): void
    {
        $department = Department::create(['name' => 'Phong ZZ', 'code' => 'PB-ZZ']);
        $make = function (string $hireDate, array $contracts) use ($department): Employee {
            $employee = Employee::create([
                'full_name' => 'ZZ '.uniqid(), 'company_email' => uniqid().'@qlns.local', 'hire_date' => $hireDate,
                'code' => 'ZZ-'.uniqid(), 'department_id' => $department->id, 'employment_status' => 'active',
            ]);
            foreach ($contracts as [$type, $start, $end]) {
                EmployeeContract::create([
                    'employee_id' => $employee->id, 'contract_number' => 'HD-'.uniqid(), 'contract_type' => $type,
                    'start_date' => $start, 'end_date' => $end, 'agreed_salary' => 10_000_000, 'insurance_salary' => 10_000_000, 'status' => 'active',
                ]);
            }

            return $employee;
        };
        $official = $make('2025-01-01', [['chinh_thuc', '2025-01-01', null]]);
        // Thử việc trong tháng 8 nhưng lên chính thức đúng ngày 01/09 -> hưởng mức chính thức.
        $promoted = $make('2026-07-01', [['thu_viec', '2026-07-01', '2026-08-31'], ['chinh_thuc', '2026-09-01', null]]);
        $intern = $make('2026-06-01', [['thuc_tap', '2026-06-01', '2026-12-31']]);

        // Mới chốt trình ký (chưa phát hành) -> chưa được tính.
        $this->postJson($this->url('/prepare-signing'), $this->withBonus(), $this->as('hr'))->assertOk();
        $this->assertCount(0, app(HolidayBonusCalculator::class)->announcementsForPeriod(8, 2026));
        $this->uploadSigned();
        $this->postJson($this->url('/publish'), [], $this->as('hr'))->assertOk();

        $payroll = app(PayrollService::class)->generateForPeriod(8, 2026, User::first());
        $detail = fn (Employee $e) => PayrollDetail::where('payroll_id', $payroll->id)->where('employee_id', $e->id)->firstOrFail();

        $this->assertEquals(500000, $detail($official)->bonus_amount);
        $this->assertEquals(500000, $detail($promoted)->bonus_amount);
        $this->assertEquals(100000, $detail($intern)->bonus_amount);

        $d = $detail($official);
        $this->assertEqualsWithDelta((float) $d->base_salary + (float) $d->overtime_amount + 500000, (float) $d->gross_salary, 0.01);
        $component = $d->components()->first();
        $this->assertSame('Thưởng Quốc khánh ZZ', $component->component_name_snapshot);
        $this->assertEquals(500000, $component->amount);

        $this->getJson("/api/v1/payrolls/{$payroll->id}", $this->as('admin'))->assertOk();

        // Vào làm sau ngày đầu kỳ nghỉ -> không được thưởng dịp này.
        $late = $make('2026-09-05', [['chinh_thuc', '2026-09-05', null]]);
        $announcements = app(HolidayBonusCalculator::class)->announcementsForPeriod(8, 2026);
        $this->assertSame([], app(HolidayBonusCalculator::class)->bonusesFor($late, $announcements));
    }
}
