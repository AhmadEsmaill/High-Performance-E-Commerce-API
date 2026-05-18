<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'items'        => CartItemResource::collection($this->whenLoaded('items')),
            'total_amount' => (float) $this->totalAmount(),
            'items_count'  => $this->items->count(),
        ];
    }
}
