<?php

namespace Tests\Feature;

use App\Models\Memo;
use App\Models\User;
use Database\Seeders\SecondBrainToolsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function createMemo(array $attrs = [], ?User $owner = null): Memo
    {
        return ($owner ?? $this->user)->memos()->create($attrs + ['title' => null, 'body' => '기본 본문']);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/memos')->assertUnauthorized();
        $this->postJson('/api/memos', ['body' => 'x'])->assertUnauthorized();
        $this->getJson('/api/tags')->assertUnauthorized();
    }

    public function test_create_normalizes_and_dedupes_tags(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/memos', [
                'title' => '배포 메모',
                'body' => 'nginx 설정 정리',
                'tags' => ['PHP', ' php ', '배포', ''],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'tags')
            ->assertJsonPath('title', '배포 메모');

        $this->assertDatabaseCount('tags', 2);
    }

    public function test_body_is_required_and_tags_are_limited(): void
    {
        $this->actingAs($this->user)->postJson('/api/memos', ['title' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->actingAs($this->user)->postJson('/api/memos', [
            'body' => 'x',
            'tags' => array_map(fn ($i) => "t$i", range(1, 11)),
        ])->assertUnprocessable()->assertJsonValidationErrors('tags');
    }

    public function test_search_matches_body_title_and_tags_case_insensitively(): void
    {
        $byBody = $this->createMemo(['body' => '서버 배포할 때 Nginx 에러가 났다']);
        $byTitle = $this->createMemo(['title' => 'Nginx 정리', 'body' => '내용']);
        $byTag = $this->createMemo(['body' => '다른 내용']);
        $byTag->tags()->attach($this->user->tags()->create(['name' => 'nginx'])->id);
        $this->createMemo(['body' => '관련 없음']);

        $ids = $this->actingAs($this->user)->getJson('/api/memos?q=NGINX')
            ->assertOk()->json('data.*.id');

        $this->assertEqualsCanonicalizing([$byBody->id, $byTitle->id, $byTag->id], $ids);

        $this->actingAs($this->user)->getJson('/api/memos?q=에러')
            ->assertJsonCount(1, 'data');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->createMemo(['body' => '진행률 100% 달성']);
        $this->createMemo(['body' => '진행률 없음']);

        $this->actingAs($this->user)->getJson('/api/memos?q=100%25')
            ->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->getJson('/api/memos?q=%25')
            ->assertJsonCount(1, 'data');
    }

    public function test_filter_by_tag(): void
    {
        $tagged = $this->createMemo();
        $tagged->tags()->attach($this->user->tags()->create(['name' => 'php'])->id);
        $this->createMemo();

        $this->actingAs($this->user)->getJson('/api/memos?tag=php')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tagged->id);
    }

    public function test_users_cannot_see_or_change_each_others_memos(): void
    {
        $other = User::factory()->create();
        $theirs = $this->createMemo(['body' => '남의 비밀 메모'], $other);

        $this->actingAs($this->user)->getJson('/api/memos')->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->getJson("/api/memos/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->putJson("/api/memos/{$theirs->id}", ['body' => '탈취'])->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/api/memos/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->getJson('/api/memos?q=비밀')->assertJsonCount(0, 'data');

        $this->assertSame('남의 비밀 메모', $theirs->fresh()->body);
    }

    public function test_update_replaces_tags_and_delete_removes_memo(): void
    {
        $memo = $this->createMemo();

        $this->actingAs($this->user)
            ->putJson("/api/memos/{$memo->id}", ['body' => '수정됨', 'tags' => ['새태그']])
            ->assertOk()
            ->assertJsonPath('body', '수정됨')
            ->assertJsonPath('tags.0.name', '새태그');

        $this->actingAs($this->user)->deleteJson("/api/memos/{$memo->id}")->assertNoContent();
        $this->assertModelMissing($memo);
    }

    public function test_tags_endpoint_lists_only_own_used_tags(): void
    {
        $memo = $this->createMemo();
        $memo->tags()->attach($this->user->tags()->create(['name' => 'used'])->id);
        $this->user->tags()->create(['name' => 'orphan']);
        User::factory()->create()->tags()->create(['name' => 'theirs']);

        $this->actingAs($this->user)->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'used')
            ->assertJsonMissingPath('0.user_id');
    }

    public function test_second_brain_tools_are_registered_and_seeding_is_idempotent(): void
    {
        $this->seed(SecondBrainToolsSeeder::class);
        $this->seed(SecondBrainToolsSeeder::class);

        $tools = $this->actingAs($this->user)->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('0.slug', 'second-brain')
            ->assertJsonCount(2, '0.tools')
            ->json('0.tools');

        $byName = collect($tools)->keyBy('name');
        $this->assertSame('read', $byName['memo.search']['access']);
        $this->assertTrue($byName['memo.create']['requires_confirmation']);
    }
}
