<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->unsignedInteger('total_items_sold')->default(0);
            $table->json('top_products')->nullable();     // [{product_id, name, qty_sold, revenue}]
            $table->json('category_breakdown')->nullable(); // {electronics: {orders, revenue}, ...}
            $table->unsignedInteger('chunks_processed')->default(0);
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->string('batch_id')->nullable(); // Laravel Bus::batch() id
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_sales_reports');
    }
};
