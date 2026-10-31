<?php

namespace App\Providers;

use App\Jarvis\GeminiClient;
use App\Jarvis\LlmClient;
use App\Jarvis\RuleBasedClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 제공자를 바꾸려면 JARVIS_PROVIDER만 바꾸면 된다. 새 제공자는 LlmClient 구현을 추가하고 여기에 연결한다.
        $this->app->bind(LlmClient::class, fn () => match (config('jarvis.provider')) {
            'gemini' => new GeminiClient(
                config('jarvis.gemini.api_key'),
                config('jarvis.gemini.model'),
                config('jarvis.gemini.timeout'),
            ),
            default => new RuleBasedClient,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 무료 LLM 한도를 한 사용자가 다 쓰지 못하게 사용자별로 분당, 일일 호출을 제한한다.
        RateLimiter::for('jarvis', fn (Request $request) => [
            Limit::perMinute(config('jarvis.rate_limit.per_minute'))->by('jarvis-min:'.$request->user()?->id),
            Limit::perDay(config('jarvis.rate_limit.per_day'))->by('jarvis-day:'.$request->user()?->id),
        ]);
    }
}
