<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'payment_status' => $this->payment_status,
            'total_amount'   => (float) $this->total_amount,
            'notes'          => $this->notes,
            'items'          => OrderItemResource::collection($this->whenLoaded('items')),
            'invoice'        => new InvoiceResource($this->whenLoaded('invoice')),
            'created_at'     => $this->created_at->toISOString(),
        ];
    }
}
