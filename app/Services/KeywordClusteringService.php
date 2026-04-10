<?php

declare(strict_types=1);

namespace App\Services;

class KeywordClusteringService
{
    /**
     * Cluster keywords by shared word tokens.
     *
     * @param  string[]  $keywords
     * @return array<int, array{label: string, keywords: string[]}>
     */
    public function cluster(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        // Build token sets for each keyword
        $tokenSets = [];
        foreach ($keywords as $kw) {
            $tokens = $this->tokenize($kw);
            $tokenSets[$kw] = $tokens;
        }

        // Group by shared 2-word sequences (bigrams)
        $clusters = [];
        $assigned = [];

        foreach ($keywords as $kw) {
            if (isset($assigned[$kw])) {
                continue;
            }

            $cluster = [$kw];
            $assigned[$kw] = true;
            $baseTokens = $tokenSets[$kw];

            foreach ($keywords as $other) {
                if ($other === $kw || isset($assigned[$other])) {
                    continue;
                }

                $otherTokens = $tokenSets[$other];
                $shared = array_intersect($baseTokens, $otherTokens);

                // At least 2 shared meaningful tokens → same cluster
                $meaningful = array_filter($shared, fn (string $t) => mb_strlen($t) > 2);
                if (count($meaningful) >= 2) {
                    $cluster[] = $other;
                    $assigned[$other] = true;
                }
            }

            // Generate a label from the most common tokens
            $allTokens = [];
            foreach ($cluster as $c) {
                $allTokens = array_merge($allTokens, $tokenSets[$c]);
            }
            $freq = array_count_values($allTokens);
            arsort($freq);
            $label = implode(' ', array_slice(array_keys($freq), 0, 3));

            $clusters[] = [
                'label' => $label,
                'keywords' => $cluster,
            ];
        }

        // Sort clusters by size descending
        usort($clusters, fn ($a, $b) => count($b['keywords']) - count($a['keywords']));

        return $clusters;
    }

    /**
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Remove very short stopwords
        return array_values(array_filter($tokens, fn (string $t) => mb_strlen($t) > 1));
    }
}
