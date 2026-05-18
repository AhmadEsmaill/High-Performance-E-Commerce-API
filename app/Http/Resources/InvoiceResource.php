<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'invoice_number' => $this->invoice_number,
            'amount'         => (float) $this->amount,
            'status'         => $this->status,
            'generated_at'   => $this->generated_at?->toISOString(),
        ];
    }
}
