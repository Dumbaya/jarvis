<?php

namespace App\Providers;

use App\Jarvis\LlmBreaker;
use App\Jarvis\LlmChain;
use App\Jarvis\LlmClient;
use App\Rag\Embedder;
use App\Rag\GeminiEmbedder;
use App\Rag\OpenAiCompatibleEmbedder;
use App\Rag\UnconfiguredEmbedder;
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

        // 임베딩 제공자. 키(또는 로컬 서버)가 없으면 "쓸 수 없음"으로 응답하는 객체를 주어 키워드 검색으로 대체되게 한다.
        $this->app->bind(Embedder::class, function () {
            $config = config('jarvis.embedding');

            return match ($config['provider']) {
                'ollama' => new OpenAiCompatibleEmbedder(config('jarvis.providers.ollama.base_url'), null, $config['model'], $config['dimensions']),
                default => config('jarvis.providers.gemini.api_key')
                    ? new GeminiEmbedder(config('jarvis.providers.gemini.api_key'), $config['model'], $config['dimensions'])
                    : new UnconfiguredEmbedder($config['model']),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
