<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'product'   => new ProductResource($this->whenLoaded('product')),
            'quantity'  => $this->quantity,
            'subtotal'  => (float) ($this->quantity * $this->product->price),
        ];
    }
}
