<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every request runs in a DB transaction that is rolled back on error
        // responses (DatabaseTransaction middleware). With the database cache
        // store on that same connection, rate-limit counters for failed
        // requests (login lockout, 2FA attempts, route throttles) were rolled
        // back too. A separate connection to the same database keeps cache
        // writes outside that transaction.
        $default = config('database.default');
        $base = config("database.connections.{$default}");
        if (is_array($base) && ! config('database.connections.cache')
            && ($base['database'] ?? null) !== ':memory:') {
            config(['database.connections.cache' => $base]);
            if (! config('cache.stores.database.connection')) {
                config(['cache.stores.database.connection' => 'cache']);
            }
            if (! config('cache.stores.database.lock_connection')) {
                config(['cache.stores.database.lock_connection' => 'cache']);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::policy(\App\Models\Listing::class, \App\Policies\ListingPolicy::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/auth/reset-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
