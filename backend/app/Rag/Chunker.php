<?php

namespace App\Rag;

/**
 * 긴 메모를 임베딩하기 좋은 크기의 조각으로 나눈다. 한 벡터에 너무 많은 내용이 섞이면 의미가 흐려지고,
 * 검색 결과로 보여 줄 근거 문단도 길어진다. 문단 경계를 우선하고, 한 문단이 너무 길 때만 글자 수로 자른다.
 * 한국어(멀티바이트)를 다루므로 mb_ 함수만 쓴다.
 */
class Chunker
{
    /**
     * @return array<int, string> 비어 있지 않은 조각들(최대 $max개)
     */
    public static function split(string $text, int $size = 800, int $overlap = 100, int $max = 25): array
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text));
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $chunks = [];
        $buffer = '';
        $flush = function () use (&$chunks, &$buffer) {
            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }
        };

        foreach (preg_split('/\n{2,}/u', $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > $size) {
                $flush();
                array_push($chunks, ...self::hardSplit($paragraph, $size, $overlap));

                continue;
            }

            // 이 문단을 붙이면 크기를 넘으면 지금까지 모은 것을 한 조각으로 확정한다.
            if ($buffer !== '' && mb_strlen($buffer) + 2 + mb_strlen($paragraph) > $size) {
                $flush();
            }
            $buffer = $buffer === '' ? $paragraph : $buffer."\n\n".$paragraph;
        }
        $flush();

        return array_slice($chunks, 0, $max);
    }

    /** 한 문단이 $size보다 길 때 글자 수로 자른다. 단어 중간에서 끊기지 않게 가능하면 공백에서 자르고, 앞뒤로 $overlap만큼 겹친다. */
    private static function hardSplit(string $text, int $size, int $overlap): array
    {
        $length = mb_strlen($text);
        $pieces = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $size, $length);

            if ($end < $length) {
                // 끝 부분 20% 안에서 마지막 공백을 찾아 거기서 자른다.
                $window = mb_substr($text, $start + (int) ($size * 0.8), $end - $start - (int) ($size * 0.8));
                $space = mb_strrpos($window, ' ');
                if ($space !== false) {
                    $end = $start + (int) ($size * 0.8) + $space;
                }
            }

            $piece = trim(mb_substr($text, $start, $end - $start));
            if ($piece !== '') {
                $pieces[] = $piece;
            }
            if ($end >= $length) {
                break;
            }

            // 겹치게 뒤로 물러나되, 항상 앞으로 나아가도록 보장한다(무한 루프 방지).
            $start = max($end - $overlap, $start + 1);
        }

        return $pieces;
    }
}
