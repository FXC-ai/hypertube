<?php

namespace App\Providers;

use App\Socialite\FortyTwoProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Passport;
use Laravel\Socialite\Socialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The API only uses POST /oauth/token (password and refresh_token grants). Passport's
        // other routes (authorize, device, token/refresh) would need views and flows this app
        // does not have, and answered 500, so only the token route is registered in boot().
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Passport::enablePasswordGrant();

        Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])
            ->middleware('throttle')
            ->name('passport.token');

        Socialite::extend(
            'fortytwo',
            function () {
                return Socialite::buildProvider(
                    FortyTwoProvider::class,
                    config('services.fortytwo')
                );
            }
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): Password => Password::min(8)
            // ->mixedCase()
            // ->letters()
            // ->numbers()
            // ->symbols()
            // ->uncompromised()
        );
    }
}
