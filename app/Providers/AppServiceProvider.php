<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Plaid\PlaidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use Vite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->configureServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureCommands();
        $this->configureModels();
        $this->configureUrl();
        $this->configureVite();
        $this->configureNumbers();
    }

    public function configureCommands(): void
    {
        DB::prohibitDestructiveCommands(
            $this->app->isProduction(),
        );
    }

    public function configureModels(): void
    {
        Model::shouldBeStrict();
        Model::unguard();
    }

    public function configureUrl(): void
    {
        if (! $this->app->isLocal()) {
            URL::forceScheme('https');
        }
    }

    public function configureVite(): void
    {
        Vite::usePrefetchStrategy('aggressive');
    }

    /**
     * currency() (app/Helpers/functions.php) formats via Number::currency(), which defaults to
     * the 'en' locale unless told otherwise — this keeps it in sync with APP_LOCALE instead of
     * needing a second, redundant env var.
     */
    public function configureNumbers(): void
    {
        Number::useLocale(config('app.locale'));
    }

    public function configureServices(): void
    {
        $this->app->singleton(PlaidService::class, fn (Application $app, array $args): PlaidService => new PlaidService(
            $args['environment'] ?? config('plaid.environment'),
            config('plaid.clientId'),
        ));
    }
}
