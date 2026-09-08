<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

class EmployeeDocumentRepository
{
    public function listByEmployee(Employee $employee): Collection
    {
        return $employee->documents()->latest()->get();
    }

    public function create(array $data): EmployeeDocument
    {
        return EmployeeDocument::create($data);
    }

    public function delete(EmployeeDocument $document): void
    {
        $document->delete();
    }
}
