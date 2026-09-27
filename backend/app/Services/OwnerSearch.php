<?php

namespace App\Services;

class OwnerSearch
{
    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * @param  array{title:string, terms?:list<string>, aliases?:list<string>}  $entry
     */
    public function score(string $query, array $entry): ?int
    {
        $query = $this->normalize($query);
        $queryTokens = $this->tokens($query);
        if ($query === '' || $queryTokens === []) {
            return null;
        }

        $title = $this->normalize($entry['title']);
        $titleTokens = $this->tokens($entry['title']);
        $termTokens = $this->tokens(implode(' ', $entry['terms'] ?? []));
        $aliasTokens = $this->tokens(implode(' ', $entry['aliases'] ?? []));
        $allTokens = array_values(array_unique([...$titleTokens, ...$termTokens, ...$aliasTokens]));

        $score = $title === $query ? 1200 : 0;
        if ($title !== $query && str_contains($title, $query)) {
            $score += 300;
        }

        $matched = 0;
        foreach ($queryTokens as $needle) {
            $best = 0;
            foreach ($titleTokens as $candidate) {
                $best = max($best, $this->tokenScore($needle, $candidate, 220, 160, 105, 55));
            }
            foreach ($termTokens as $candidate) {
                $best = max($best, $this->tokenScore($needle, $candidate, 170, 120, 80, 45));
            }
            foreach ($aliasTokens as $candidate) {
                $best = max($best, $this->tokenScore($needle, $candidate, 120, 90, 60, 35));
            }

            if ($best === 0) {
                return null;
            }

            $matched++;
            $score += $best;
        }

        if ($matched === count($queryTokens) && count($queryTokens) > 1) {
            $score += 180 + (count($queryTokens) * 20);
        }

        return $score;
    }

    private function tokenScore(string $needle, string $candidate, int $exact, int $prefix, int $substring, int $fuzzy): int
    {
        if ($needle === $candidate) {
            return $exact;
        }
        if (str_starts_with($candidate, $needle) || str_starts_with($needle, $candidate)) {
            return min(mb_strlen($needle), mb_strlen($candidate)) >= 3 ? $prefix : 0;
        }
        if (min(mb_strlen($needle), mb_strlen($candidate)) >= 4 && (str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
            return $substring;
        }

        $maxDistance = min(mb_strlen($needle), mb_strlen($candidate)) >= 9 ? 2 : 1;
        if (min(mb_strlen($needle), mb_strlen($candidate)) < 4) {
            return 0;
        }
        if (mb_substr($needle, 0, 1) !== mb_substr($candidate, 0, 1) || abs(mb_strlen($needle) - mb_strlen($candidate)) > $maxDistance) {
            return 0;
        }

        return levenshtein($needle, $candidate) <= $maxDistance ? $fuzzy : 0;
    }
}
