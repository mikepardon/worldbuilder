<?php

use App\Console\Commands\DispatchDueCampaigns;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(DispatchDueCampaigns::class)->everyFiveMinutes();
