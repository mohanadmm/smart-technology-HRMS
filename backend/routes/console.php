<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:alert-missing')->dailyAt('21:00')->timezone('Africa/Cairo');
Schedule::command('leave:alert-anniversary')->dailyAt('08:00')->timezone('Africa/Cairo');
Schedule::command('logs:prune')->dailyAt('03:00')->timezone('Africa/Cairo');
