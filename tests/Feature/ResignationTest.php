<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\ResignationRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Đơn xin nghỉ việc (2026-09-29, theo yêu cầu người dùng) — xem
// App\Services\ResignationService.
class ResignationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email, string $password = 'Secret@123'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'resign-'.uniqid().'@qlns.local', 'user_name' => 'Resign User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYear(),
            'code' => 'NV-'.uniqid(),
            'employment_status' => 'active',
            'user_id' => $user->id,
        ], $overrides));

        return [$employee, $user];
    }

    private function makeHr(): User
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');

        return $hrUser;
    }

    public function test_employee_submits_request_and_hr_is_notified(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $hr = $this->makeHr();

        $response = $this->postJson('/api/v1/resignations', [
            'last_working_date' => now()->addDays(30)->toDateString(),
            'reason' => 'Chuyen cong tac',
        ], $this->auth($this->loginAs($user->email)));

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'resignation.pending']);
        // Chưa duyệt thì trạng thái nhân viên KHÔNG đổi.
        $this->assertSame('active', $employee->fresh()->employment_status);
    }

    public function test_cannot_submit_second_request_while_one_is_open(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email);
        $payload = ['last_working_date' => now()->addDays(30)->toDateString(), 'reason' => 'x'];

        $this->postJson('/api/v1/resignations', $payload, $this->auth($token))->assertStatus(201);
        $this->postJson('/api/v1/resignations', $payload, $this->auth($token))
            ->assertStatus(422)->assertJsonValidationErrors('last_working_date');
    }

    public function test_last_working_date_cannot_be_in_the_past(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();

        $this->postJson('/api/v1/resignations', [
            'last_working_date' => now()->subDay()->toDateString(),
            'reason' => 'x',
        ], $this->auth($this->loginAs($user->email)))
            ->assertStatus(422)->assertJsonValidationErrors('last_working_date');
    }

    public function test_employee_can_cancel_own_pending_request(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email);
        $id = $this->postJson('/api/v1/resignations', [
            'last_working_date' => now()->addDays(10)->toDateString(), 'reason' => 'x',
        ], $this->auth($token))->json('data.id');

        $this->postJson("/api/v1/resignations/{$id}/cancel", [], $this->auth($token))
            ->assertStatus(200)->assertJsonPath('data.status', 'cancelled');
    }

    public function test_plain_employee_cannot_list_or_decide(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();

        $this->getJson('/api/v1/resignations', $this->auth($this->loginAs($user->email)))->assertStatus(403);
    }

    // Manager chỉ thấy/duyệt đơn của nhân viên mình quản lý trực tiếp
    // (manager_id tự suy từ Trưởng phòng — ReportingLineService).
    public function test_manager_only_sees_and_decides_requests_of_direct_reports(): void
    {
        $department = Department::create(['name' => 'Phong A', 'code' => 'PB-RA']);
        [$managerEmployee, $managerUser] = $this->makeEmployeeWithLogin('Manager', ['department_id' => $department->id]);
        [$report] = $this->makeEmployeeWithLogin('Employee', ['department_id' => $department->id, 'manager_id' => $managerEmployee->id]);
        [$stranger] = $this->makeEmployeeWithLogin('Employee');
        $ownRequest = ResignationRequest::create(['employee_id' => $report->id, 'last_working_date' => now()->addDays(20), 'reason' => 'x']);
        $otherRequest = ResignationRequest::create(['employee_id' => $stranger->id, 'last_working_date' => now()->addDays(20), 'reason' => 'x']);
        $token = $this->loginAs($managerUser->email);

        $ids = collect($this->getJson('/api/v1/resignations', $this->auth($token))->json('data'))->pluck('id')->all();
        $this->assertSame([$ownRequest->id], $ids);

        $this->getJson("/api/v1/resignations/{$ownRequest->id}", $this->auth($token))
            ->assertStatus(200)->assertJsonPath('can_decide', true);
        $this->putJson("/api/v1/resignations/{$otherRequest->id}/decide", ['status' => 'approved'], $this->auth($token))
            ->assertStatus(404);
    }

    public function test_detail_contains_full_employee_information_for_review(): void
    {
        [$employee] = $this->makeEmployeeWithLogin();
        $request = ResignationRequest::create(['employee_id' => $employee->id, 'last_working_date' => now()->addDays(20), 'reason' => 'Ly do chi tiet']);
        $token = $this->loginAs($this->makeHr()->email);

        $this->getJson("/api/v1/resignations/{$request->id}", $this->auth($token))
            ->assertStatus(200)
            ->assertJsonPath('data.reason', 'Ly do chi tiet')
            ->assertJsonPath('data.employee.full_name', $employee->full_name)
            ->assertJsonPath('data.employee.employment_status', 'active')
            ->assertJsonPath('can_decide', true);
    }

    public function test_approving_after_last_working_date_marks_employee_resigned_immediately(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $contract = EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'HDCT-T1', 'contract_type' => 'chinh_thuc',
            'start_date' => now()->subYear(), 'agreed_salary' => 1000, 'insurance_salary' => 1000, 'status' => 'active',
        ]);
        $request = ResignationRequest::create([
            'employee_id' => $employee->id, 'last_working_date' => now()->subDay(), 'reason' => 'x',
        ]);
        $token = $this->loginAs($this->makeHr()->email);

        $this->putJson("/api/v1/resignations/{$request->id}/decide", ['status' => 'approved'], $this->auth($token))
            ->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $employee->refresh();
        $this->assertSame('resigned', $employee->employment_status);
        $this->assertSame(now()->subDay()->toDateString(), $employee->termination_date->toDateString());
        $this->assertSame('terminated', $contract->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'resignation.decided']);
    }

    public function test_approved_future_request_is_applied_by_daily_job_after_last_working_date(): void
    {
        [$employee] = $this->makeEmployeeWithLogin();
        $request = ResignationRequest::create([
            'employee_id' => $employee->id, 'last_working_date' => now()->addDays(3), 'reason' => 'x',
        ]);
        $token = $this->loginAs($this->makeHr()->email);

        $this->putJson("/api/v1/resignations/{$request->id}/decide", ['status' => 'approved'], $this->auth($token))->assertStatus(200);
        // Chưa qua ngày làm việc cuối -> vẫn đang làm.
        $this->assertSame('active', $employee->fresh()->employment_status);

        $this->travel(4)->days();
        $this->artisan('resignations:apply')->assertSuccessful();

        $this->assertSame('resigned', $employee->fresh()->employment_status);
        $this->assertNotNull($request->fresh()->applied_at);
    }

    public function test_reject_requires_reason_and_keeps_employee_status(): void
    {
        [$employee] = $this->makeEmployeeWithLogin();
        $request = ResignationRequest::create(['employee_id' => $employee->id, 'last_working_date' => now()->addDays(3), 'reason' => 'x']);
        $token = $this->loginAs($this->makeHr()->email);

        $this->putJson("/api/v1/resignations/{$request->id}/decide", ['status' => 'rejected'], $this->auth($token))
            ->assertStatus(422)->assertJsonValidationErrors('note');

        $this->putJson("/api/v1/resignations/{$request->id}/decide", ['status' => 'rejected', 'note' => 'Chua ban giao xong'], $this->auth($token))
            ->assertStatus(200)->assertJsonPath('data.status', 'rejected');

        $this->assertSame('active', $employee->fresh()->employment_status);
    }

    public function test_cannot_decide_twice(): void
    {
        [$employee] = $this->makeEmployeeWithLogin();
        $request = ResignationRequest::create(['employee_id' => $employee->id, 'last_working_date' => now()->addDays(3), 'reason' => 'x']);
        $token = $this->loginAs($this->makeHr()->email);

        $this->putJson("/api/v1/resignations/{$request->id}/decide", ['status' => 'approved'], $this->auth($token))->assertStatus(200);
        $this->putJson("/api/v1/resignations/{$request->id}/decide", ['status' => 'rejected', 'note' => 'x'], $this->auth($token))
            ->assertStatus(422);
    }

    // ---- Báo trước ĐỦ ngày theo BLLĐ 2019 Điều 35 -> chỉ thông báo, không cần duyệt (2026-09-30) ----

    private function giveContract(Employee $employee, string $type, ?string $start, ?string $end): void
    {
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'HD-'.uniqid(), 'contract_type' => $type,
            'start_date' => $start ?? now()->subYear()->toDateString(), 'end_date' => $end,
            'agreed_salary' => 10000000, 'insurance_salary' => 10000000, 'status' => 'active',
        ]);
    }

    private function submit(User $user, int $daysAhead): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/resignations', [
            'last_working_date' => now()->addDays($daysAhead)->toDateString(),
            'reason' => 'Chuyen cong tac',
        ], $this->auth($this->loginAs($user->email)));
    }

    public function test_indefinite_contract_with_45_days_notice_is_only_a_notice(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->giveContract($employee, 'chinh_thuc', null, null);
        $hr = $this->makeHr();

        $this->submit($user, 45)->assertStatus(201)
            ->assertJsonPath('data.status', 'notified')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.notice_days_required', 45)
            ->assertJsonPath('data.notice_days_given', 45);

        // HR vẫn được BÁO (loại thông báo riêng), nhưng không có gì để duyệt.
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'resignation.notice']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $hr->id, 'type' => 'resignation.pending']);
    }

    public function test_indefinite_contract_with_short_notice_waits_for_approval(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->giveContract($employee, 'chinh_thuc', null, null);
        $hr = $this->makeHr();

        $this->submit($user, 44)->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requires_approval', true)
            ->assertJsonPath('data.notice_days_required', 45);

        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'resignation.pending']);
    }

    public function test_fixed_term_contract_notice_days_follow_the_contract_length(): void
    {
        [$twoYear, $userA] = $this->makeEmployeeWithLogin();
        $this->giveContract($twoYear, 'chinh_thuc', now()->subMonths(2)->toDateString(), now()->addMonths(22)->subDay()->toDateString());
        [$sixMonth, $userB] = $this->makeEmployeeWithLogin();
        $this->giveContract($sixMonth, 'chinh_thuc', now()->subMonth()->toDateString(), now()->addMonths(5)->subDay()->toDateString());

        $this->submit($userA, 30)->assertJsonPath('data.status', 'notified')->assertJsonPath('data.notice_days_required', 30);
        $this->submit($userB, 3)->assertJsonPath('data.status', 'notified')->assertJsonPath('data.notice_days_required', 3);
    }

    public function test_probation_contract_needs_no_advance_notice(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee', ['employment_status' => 'probation']);
        $this->giveContract($employee, 'thu_viec', now()->subMonth()->toDateString(), now()->addMonths(2)->toDateString());

        $this->submit($user, 0)->assertStatus(201)
            ->assertJsonPath('data.status', 'notified')
            ->assertJsonPath('data.notice_days_required', 0);
    }

    public function test_policy_endpoint_tells_the_employee_the_earliest_last_working_date(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->giveContract($employee, 'chinh_thuc', null, null);

        $this->getJson('/api/v1/resignations/policy', $this->auth($this->loginAs($user->email)))
            ->assertStatus(200)
            ->assertJsonPath('required_days', 45)
            ->assertJsonPath('earliest_last_working_date', now()->addDays(45)->toDateString());
    }

    public function test_notice_only_request_cannot_be_decided_but_can_be_withdrawn(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->giveContract($employee, 'chinh_thuc', null, null);
        $id = $this->submit($user, 45)->json('data.id');
        $hr = $this->makeHr();

        $this->putJson("/api/v1/resignations/{$id}/decide", ['status' => 'approved'], $this->auth($this->loginAs($hr->email)))
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/resignations/{$id}/cancel", [], $this->auth($this->loginAs($user->email)))
            ->assertStatus(200)->assertJsonPath('data.status', 'cancelled');
    }

    public function test_notice_only_request_makes_the_employee_resigned_after_the_last_day(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->giveContract($employee, 'chinh_thuc', null, null);
        $this->submit($user, 45)->assertStatus(201);

        $this->assertSame(0, app(\App\Services\ResignationService::class)->applyAllDue());
        $this->travel(46)->days();
        $this->assertSame(1, app(\App\Services\ResignationService::class)->applyAllDue());

        $employee->refresh();
        $this->assertSame('resigned', $employee->employment_status);
        $this->assertSame(now()->subDay()->toDateString(), $employee->termination_date->toDateString());
    }
}
