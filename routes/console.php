<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * NFR #4 — Batch Processing: جدولة تقرير المبيعات اليومية
 * يُطلَق تلقائياً كل يوم الساعة 2:00 صباحاً (وقت منخفض الضغط)
 * يعالج مبيعات اليوم السابق في chunks متوازية
 */
Schedule::command('sales:report')->dailyAt('02:00')
    ->withoutOverlapping()   // لا يبدأ دورة جديدة إذا الدورة السابقة لا تزال تعمل
    ->runInBackground()      // لا يحجب الـ scheduler
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Daily sales report scheduled job failed.');
    });
