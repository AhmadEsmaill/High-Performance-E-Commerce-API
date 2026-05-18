<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sales:report {date? : تاريخ التقرير YYYY-MM-DD، الافتراضي أمس}')]
#[Description('يُطلق معالجة تقرير المبيعات اليومية عبر Batch Processing')]
class GenerateDailySalesReport extends Command
{
    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->subDay()->toDateString();

        if (! \DateTime::createFromFormat('Y-m-d', $date)) {
            $this->error("Invalid date format. Use YYYY-MM-DD.");
            return self::FAILURE;
        }

        $this->info("Dispatching batch sales report for: {$date}");
        $this->info("Chunk size: " . \App\Jobs\GenerateDailySalesReportJob::CHUNK_SIZE . " orders per chunk");

        \App\Jobs\GenerateDailySalesReportJob::dispatch($date)
            ->onQueue('reports');

        $this->info("✅ GenerateDailySalesReportJob dispatched to [reports] queue.");
        $this->line("Run: php artisan queue:work --queue=reports to process.");

        return self::SUCCESS;
    }
}
