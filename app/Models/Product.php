<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'description', 'category', 'price', 'stock_quantity', 'lock_version', 'is_active'];

    protected $casts = [
        'price'          => 'decimal:2',
        'stock_quantity' => 'integer',
        'lock_version'   => 'integer',
        'is_active'      => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isInStock(int $requested = 1): bool
    {
        return $this->stock_quantity >= $requested;
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
