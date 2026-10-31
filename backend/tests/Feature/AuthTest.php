<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'name' => '테스터',
        'email' => 'tester@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    public function test_register_returns_token_and_user(): void
    {
        $this->postJson('/api/auth/register', $this->payload)
            ->assertCreated()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'email']]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => $this->payload['email']]);

        $this->postJson('/api/auth/register', $this->payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => $this->payload['email']]);

        $this->postJson('/api/auth/login', ['email' => $this->payload['email'], 'password' => 'wrong'])
            ->assertUnprocessable();
    }

    public function test_unauthenticated_api_calls_get_401_json_even_without_an_accept_header(): void
    {
        // 실측: Accept 헤더가 없으면 로그인 화면으로 리다이렉트하려다 "Route [login] not defined" 500이 났다.
        // 프런트는 항상 헤더를 보내서 가려졌지만 curl, 모니터링 도구, 헬스체크는 헤더 없이 부른다.
        foreach (['/api/auth/me', '/api/memos', '/api/usage', '/api/jarvis/limits'] as $url) {
            $this->get($url)->assertStatus(401)->assertHeader('Content-Type', 'application/json');
        }
        $this->post('/api/memos', ['body' => 'x'])->assertStatus(401);
    }

    public function test_token_grants_access_and_logout_revokes_it(): void
    {
        $token = $this->postJson('/api/auth/register', $this->payload)->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // 같은 요청에서 재사용되는 인증 캐시를 비운 뒤 다시 확인
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_registry_requires_auth_and_lists_only_active_tools(): void
    {
        $this->getJson('/api/services')->assertUnauthorized();

        $service = Service::create(['slug' => 'memo', 'name' => '세컨드 브레인']);
        ServiceTool::create([
            'service_id' => $service->id, 'name' => 'memo.search', 'description' => '메모 검색',
            'parameters' => ['type' => 'object'], 'path' => '/tools/search',
        ]);
        ServiceTool::create([
            'service_id' => $service->id, 'name' => 'memo.old', 'description' => '비활성',
            'parameters' => ['type' => 'object'], 'path' => '/tools/old', 'is_active' => false,
        ]);

        $this->withToken(User::factory()->create()->createToken('t')->plainTextToken)
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(1, '0.tools')
            ->assertJsonPath('0.tools.0.name', 'memo.search');
    }
}
