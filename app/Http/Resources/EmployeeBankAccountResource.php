<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'account_number' => $this->account_number,
            'account_holder' => $this->account_holder,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'verified_at' => $this->verified_at,
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier?->user_name),
            'created_at' => $this->created_at,
        ];
    }
}
