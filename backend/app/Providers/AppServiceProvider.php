<?php

namespace App\Providers;

use App\Jarvis\LlmBreaker;
use App\Jarvis\LlmChain;
use App\Jarvis\LlmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 제공자 구성은 JARVIS_LLM_CHAIN에서 정한다. 새 제공자는 LlmClient 구현을 만들고 LlmChain::build에 연결한다.
        $this->app->bind(LlmClient::class, fn ($app) => LlmChain::fromConfig($app->make(LlmBreaker::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
