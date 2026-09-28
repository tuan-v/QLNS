<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$emps = App\Models\Employee::where('full_name', 'like', '%Ánh%')->orWhere('full_name', 'like', '%Anh%')->get();
foreach ($emps as $emp) {
    echo "=== Employee: {$emp->id} - {$emp->full_name} ===\n";
    $atts = App\Models\Attendance::where('employee_id', $emp->id)->with('workShift')->get();
    $calc = new App\Services\WorkTimeCalculationService();

    foreach ($atts as $a) {
        $dayEq = $calc->dayEquivalentFor($a, $a->workShift);
        echo "  Date: {$a->attendance_date} | in: {$a->first_check_in_at} | out: {$a->last_check_out_at} | actual_mins: {$a->actual_work_minutes} | late: {$a->late_minutes} | excused: " . ($a->late_excused ? 'true' : 'false') . " | early: {$a->early_leave_minutes} | apprv_status: {$a->approval_status} | dayEquivalent: {$dayEq} | shift: " . ($a->workShift->name ?? 'none') . " (std: " . ($a->workShift->standard_work_minutes ?? 'null') . ")\n";
    }
}
