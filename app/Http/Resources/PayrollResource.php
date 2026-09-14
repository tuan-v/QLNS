<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'period_month' => $this->period_month,
            'period_year' => $this->period_year,
            'status' => $this->status,
            'total_payroll_amount' => $this->total_payroll_amount,
            'closed_at' => $this->closed_at,
            'paid_at' => $this->paid_at,
            'details' => PayrollDetailResource::collection($this->whenLoaded('details')),
        ];
    }
}
