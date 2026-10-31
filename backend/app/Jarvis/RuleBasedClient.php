<?php

namespace App\Jarvis;

/**
 * API 키 없이 자비스의 도구 호출 흐름을 써 볼 수 있는 규칙 기반 구현.
 *  "저장: 내용"  → memo.create 호출
 *  그 외 문장    → 군더더기 단어를 뺀 나머지로 memo.search 호출
 * 실제 LLM이 아니므로 자연어 이해는 하지 않는다.
 */
class RuleBasedClient implements LlmClient
{
    private const FILLER = ['find', 'search', 'memo', 'memos', 'please', '찾아줘', '찾아', '검색해줘', '검색', '알려줘', '알려', '보여줘', '메모', '관련', '내가', '써둔', '해줘', '줘'];

    public function generate(string $system, array $conversation, array $tools): LlmReply
    {
        $last = end($conversation);

        // 도구 결과를 받았으면 결과를 요약해서 답한다.
        if ($last && $last['role'] === 'tool') {
            return new LlmReply($this->summarize($last['result']));
        }

        $text = trim((string) ($last['text'] ?? ''));

        if (preg_match('/^(저장|기억|메모|save|remember)\s*[:：]\s*(.+)$/us', $text, $m)) {
            return new LlmReply(null, [['name' => 'memo.create', 'args' => ['body' => trim($m[2])]]]);
        }

        $query = trim(str_replace(self::FILLER, ' ', preg_replace('/[?!.]/u', ' ', $text)));
        $query = trim(preg_replace('/\s+/u', ' ', $query));

        // 군더더기를 빼고 남은 게 없으면(예: "메모") 입력 전체를 검색어로 쓴다. memo.search는 q가 필수다.
        return new LlmReply(null, [['name' => 'memo.search', 'args' => ['q' => $query === '' ? $text : $query]]]);
    }

    private function summarize(array $result): string
    {
        if (isset($result['error'])) {
            return '도구 호출에 실패했어요: '.$result['error'];
        }

        $items = $result['data'] ?? [];
        if (! $items) {
            return '일치하는 메모를 찾지 못했어요.';
        }

        $lines = array_map(
            fn ($m) => '- '.mb_strimwidth(($m['title'] ?? '') !== '' ? $m['title'].': '.$m['body'] : $m['body'], 0, 90, '…'),
            array_slice($items, 0, 5),
        );

        return '메모 '.count($items)."개를 찾았어요.\n".implode("\n", $lines);
    }
}
