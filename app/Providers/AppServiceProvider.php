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
    #[\Override]
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

        // shouldBeStrict() above already enables this unconditionally (bundled with
        // preventLazyLoading/preventAccessingMissingAttributes) — this narrows just this one
        // check back to non-production, so an unexpected mass-assignment mismatch is loud
        // feedback in dev/test but never turns into a 500 for a real user in production.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Force https for every generated URL when APP_URL itself is https — not simply whenever
     * APP_ENV isn't local. A blanket "any non-local env forces https" check breaks asset loading
     * entirely (Vite build output, Livewire, Flux — all requested over https then, regardless of
     * how the page itself was served) for anyone testing a real production build locally over
     * plain http, e.g. following this app's own README quick start (`APP_ENV=production` with the
     * default `APP_URL=http://localhost`). A real deployment with a real https APP_URL is
     * unaffected — this still forces https everywhere for it.
     */
    public function configureUrl(): void
    {
        if (! $this->app->isLocal() && str_starts_with((string) config('app.url'), 'https://')) {
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
