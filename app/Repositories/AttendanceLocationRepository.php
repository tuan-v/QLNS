<?php

namespace App\Repositories;

use App\Models\AttendanceLocation;
use Illuminate\Pagination\LengthAwarePaginator;

class AttendanceLocationRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return AttendanceLocation::query()->latest()->paginate($perPage);
    }

    public function find(int $id): ?AttendanceLocation
    {
        return AttendanceLocation::query()->find($id);
    }

    public function create(array $data): AttendanceLocation
    {
        return AttendanceLocation::create($data);
    }

    public function update(AttendanceLocation $attendanceLocation, array $data): AttendanceLocation
    {
        $attendanceLocation->update($data);

        return $attendanceLocation;
    }

    public function delete(AttendanceLocation $attendanceLocation): void
    {
        $attendanceLocation->delete();
    }
}
