<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bottleneck fix: the product listing runs
 *   WHERE is_active = 1 ORDER BY created_at DESC LIMIT 15
 * On a large table with no supporting index this is a full table scan + filesort.
 * This composite index lets MySQL satisfy both the filter and the ordering from
 * the index (no filesort), turning the scan into a short backward index range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['is_active', 'created_at'], 'products_active_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_active_created_idx');
        });
    }
};
