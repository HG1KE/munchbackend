<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $abandonedSchedule = config('abandoned_checkout.schedule_every_minute', true)
            ? $schedule->command('sms:dispatch-abandoned-checkouts')->everyMinute()
            : $schedule->command('sms:dispatch-abandoned-checkouts')->everyFiveMinutes();

        $abandonedSchedule->withoutOverlapping(4);

        if (config('abandoned_checkout.schedule_on_one_server', false)) {
            $abandonedSchedule->onOneServer();
        }

        $schedule->command('sms:dispatch-reorder-reminders')
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('live-presence:prune')
            ->daily()
            ->withoutOverlapping(10)
            ->onOneServer();

        $schedule->call(function () {
            \App\CentralLogics\Helpers::update_daily_product_stock();
        })->dailyAt('00:05')->name('daily-product-stock-reset')->withoutOverlapping(30);

        $orderAutomationMinutes = max(1, (int) config('order_automation.schedule_every_minutes', 5));
        $orderAutomationSchedule = $orderAutomationMinutes === 1
            ? $schedule->command('orders:auto-complete-eligible')->everyMinute()
            : $schedule->command('orders:auto-complete-eligible')->everyFiveMinutes();

        $orderAutomationSchedule
            ->withoutOverlapping(10)
            ->name('orders-auto-complete-eligible');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
