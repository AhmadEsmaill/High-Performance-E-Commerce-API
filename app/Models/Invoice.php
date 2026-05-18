<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = ['order_id', 'invoice_number', 'amount', 'status', 'generated_at'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
