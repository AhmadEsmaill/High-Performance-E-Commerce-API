<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'description'    => $this->description,
            'category'       => $this->category,
            'price'          => (float) $this->price,
            'stock_quantity' => $this->stock_quantity,
            'is_active'      => $this->is_active,
            'in_stock'       => $this->stock_quantity > 0,
            // Optimistic-locking token — echo this back when adjusting stock.
            'lock_version'   => $this->lock_version,
        ];
    }
}
