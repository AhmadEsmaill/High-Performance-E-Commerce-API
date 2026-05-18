<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailySalesReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'report_date'        => $this->report_date->toDateString(),
            'status'             => $this->status,
            'batch_id'           => $this->batch_id,
            'total_orders'       => $this->total_orders,
            'total_revenue'      => (float) $this->total_revenue,
            'total_items_sold'   => $this->total_items_sold,
            'chunks_processed'   => $this->chunks_processed,
            'top_products'       => $this->top_products ?? [],
            'category_breakdown' => $this->category_breakdown ?? [],
            'generated_at'       => $this->updated_at->toISOString(),
        ];
    }
}
