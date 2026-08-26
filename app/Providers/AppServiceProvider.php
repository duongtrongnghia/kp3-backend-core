<?php

declare(strict_types=1);

namespace App\Providers;

use App\Interfaces\SmsProviderInterface;
use App\Services\Sms\LogSmsProvider;
use App\Services\Sms\TwilioSmsProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind SmsProviderInterface to the configured provider.
        // SMS_PROVIDER=twilio → TwilioSmsProvider; default → LogSmsProvider (dev safe).
        $this->app->singleton(
            SmsProviderInterface::class,
            function () {
                return match (config('sms.provider', 'log')) {
                    'twilio' => new TwilioSmsProvider,
                    default => new LogSmsProvider,
                };
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
