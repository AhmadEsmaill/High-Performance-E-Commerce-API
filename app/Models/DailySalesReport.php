<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesReport extends Model
{
    protected $fillable = [
        'report_date', 'total_orders', 'total_revenue',
        'total_items_sold', 'top_products', 'category_breakdown',
        'chunks_processed', 'status', 'batch_id',
    ];

    protected $casts = [
        'report_date'        => 'date',
        'total_revenue'      => 'decimal:2',
        'top_products'       => 'array',
        'category_breakdown' => 'array',
    ];
}
