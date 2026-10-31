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
                'description' => '사용자의 메모에서 질문과 관련된 메모를 찾는다. 단어가 일치하는 메모(키워드)와, 사용자가 의미 검색을 켠 경우 표현이 달라도 뜻이 비슷한 메모(의미)를 함께 찾아 관련도 순으로 최대 5개를 돌려준다. 각 결과에는 제목, 본문 앞부분, 태그, 근거가 된 문단(excerpt)이 있다. 질문에 답할 때는 이 결과만 근거로 쓰고, 결과에 없는 내용은 모른다고 답한다. q에는 사용자가 한 말을 "서버"처럼 한두 단어로 줄이거나 키워드로 바꾸지 말고 문장 그대로 넣는다. 의미 검색은 문장 전체가 있어야 뜻이 비슷한 메모를 찾고, 단어 하나만 넣으면 놓친다.',
                'http_method' => 'GET',
                'path' => '/api/memos/search',
                'access' => 'read',
                'requires_confirmation' => false,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'q' => ['type' => 'string', 'description' => '사용자의 질문이나 찾을 내용을 문장 그대로. 단어로 줄이지 않는다'],
                        'tag' => ['type' => 'string', 'description' => '이 태그가 붙은 메모만 찾을 때의 태그 이름 (선택)'],
                    ],
                    'required' => ['q'],
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
