<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class SecondBrainToolsSeeder extends Seeder
{
    public function run(): void
    {
        $service = Service::updateOrCreate(
            ['slug' => 'second-brain'],
            [
                'name' => '세컨드 브레인',
                'description' => '메모를 저장하고 태그와 검색으로 다시 찾습니다.',
                'base_url' => null, // 같은 앱 안의 모듈
                'is_active' => true,
            ],
        );

        $tools = [
            [
                'name' => 'memo.search',
                'description' => '사용자의 메모를 키워드나 태그로 검색한다. 제목, 본문, 태그 이름에서 부분 일치로 찾고 최신순으로 돌려준다.',
                'http_method' => 'GET',
                'path' => '/api/memos',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'q' => ['type' => 'string', 'description' => '검색어'],
                        'tag' => ['type' => 'string', 'description' => '이 태그가 붙은 메모만 찾을 때의 태그 이름'],
                    ],
                ],
            ],
            [
                'name' => 'memo.create',
                'description' => '새 메모를 저장한다.',
                'http_method' => 'POST',
                'path' => '/api/memos',
                'access' => 'write',
                'requires_confirmation' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => '메모 제목 (선택)'],
                        'body' => ['type' => 'string', 'description' => '메모 본문'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '태그 이름 목록 (선택, 최대 10개)'],
                    ],
                    'required' => ['body'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            $service->tools()->updateOrCreate(['name' => $tool['name']], $tool + ['is_active' => true]);
        }
    }
}
