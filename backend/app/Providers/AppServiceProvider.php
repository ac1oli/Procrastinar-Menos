<?php

namespace App\Providers;

use App\Modules\Consent\Events\ConsentRevoked;
use App\Modules\Conversations\Listeners\ApplyHistoryRevocation;
use App\Modules\Memory\Listeners\ApplyMemoryRevocation;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Gateway\HttpNexoGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NexoGateway::class, fn () => new HttpNexoGateway(
            baseUrl: (string) config('nexo.ai_service.url'),
            token: config('nexo.ai_service.token'),
            connectTimeout: (float) config('nexo.ai_service.connect_timeout'),
            replyTimeout: (float) config('nexo.ai_service.reply_timeout'),
            embedTimeout: (float) config('nexo.ai_service.embed_timeout'),
            healthTimeout: (float) config('nexo.ai_service.health_timeout'),
            embeddingDimensions: (int) config('nexo.embedding_dimensions'),
            queryEmbedTimeout: (float) config('nexo.ai_service.query_embed_timeout'),
            suggestTimeout: (float) config('nexo.ai_service.suggest_timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // IDs são UUID; valores inválidos viram 404 em vez de erro de tipo no PostgreSQL.
        Route::patterns([
            'device' => '[0-9a-fA-F-]{36}',
            'routineBlock' => '[0-9a-fA-F-]{36}',
            'goal' => '[0-9a-fA-F-]{36}',
            'conversation' => '[0-9a-fA-F-]{36}',
            'message' => '[0-9a-fA-F-]{36}',
            'memory' => '[0-9a-fA-F-]{36}',
            'suggestion' => '[0-9a-fA-F-]{36}',
        ]);

        // Cada módulo dono de dados aplica a escolha da pessoa ao revogar (D7).
        Event::listen(ConsentRevoked::class, ApplyHistoryRevocation::class);
        Event::listen(ConsentRevoked::class, ApplyMemoryRevocation::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Login/registro: limite por e-mail + IP contra força bruta.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Inferência é cara (GPU local): limite próprio por usuário.
        RateLimiter::for('nexo', fn (Request $request) => Limit::perMinute((int) config('nexo.messages_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
