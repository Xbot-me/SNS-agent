<?php

declare(strict_types=1);

namespace App\Services\Trend;

/**
 * Finds the subjects a headline is about: the workspace's own keywords it
 * mentions, and every pair of neighbouring meaningful words. Pairs are what
 * tell "apple vision" from the many headlines that merely say "apple".
 */
class TopicExtractor
{
    /** English filler words; a pair never contains one. */
    private const array STOPWORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'had', 'her', 'was', 'one', 'our',
        'out', 'has', 'have', 'him', 'his', 'how', 'its', 'may', 'new', 'now', 'old', 'see', 'two', 'way', 'who',
        'did', 'get', 'let', 'say', 'she', 'too', 'use', 'that', 'this', 'with', 'from', 'they', 'will', 'what',
        'when', 'your', 'about', 'after', 'again', 'also', 'been', 'before', 'being', 'between', 'could', 'does',
        'doing', 'down', 'each', 'few', 'into', 'just', 'like', 'make', 'many', 'more', 'most', 'much', 'must',
        'only', 'other', 'over', 'same', 'should', 'some', 'such', 'than', 'then', 'there', 'these', 'those',
        'through', 'under', 'very', 'were', 'where', 'which', 'while', 'why', 'would', 'here', 'says', 'said',
        'review', 'best', 'top', 'first', 'every', 'still', 'off', 'why', 'yes', 'ever', 'amid', 'vs',
    ];

    /**
     * @param  list<string>  $keywords  lowercased
     * @return list<string> lowercased terms, each once
     */
    public function terms(string $text, array $keywords): array
    {
        $terms = [];

        foreach ($keywords as $keyword) {
            if ($this->mentions($text, $keyword)) {
                $terms[] = $keyword;
            }
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($index = 0; $index < count($words) - 1; $index++) {
            if ($this->isMeaningful($words[$index]) && $this->isMeaningful($words[$index + 1])) {
                $terms[] = "{$words[$index]} {$words[$index + 1]}";
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Whether the text names the phrase as whole words ("ai" is not in "said").
     */
    public function mentions(string $text, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($phrase), '/').'(?![\p{L}\p{N}])/u', mb_strtolower($text)) === 1;
    }

    private function isMeaningful(string $word): bool
    {
        return mb_strlen($word) >= 3
            && ! is_numeric($word)
            && ! in_array($word, self::STOPWORDS, true);
    }
}
