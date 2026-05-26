<?php

declare(strict_types=1);

namespace MediaManager\Services;

final class ShowAbbreviationGenerator
{
    /** @param list<string> $existingUpper */
    public static function fromTitle(string $title, array $existingUpper = []): string
    {
        $title = trim($title);
        if ($title === '') {
            return 'SHOW';
        }

        $candidates = [];

        if (preg_match_all('/\b([A-Z])[a-z]*/', $title, $m) === 1 && count($m[1]) >= 2) {
            $candidates[] = strtoupper(implode('', $m[1]));
        }

        $words = preg_split('/[\s:\/\-–—]+/', $title) ?: [];
        $stop = ['the', 'with', 'and', 'a', 'an', 'of', 'in', 'on', 'to', 'for', 'live', 'edition'];
        $significant = [];
        foreach ($words as $word) {
            $clean = preg_replace('/[^a-zA-Z0-9]/', '', $word) ?? '';
            if ($clean === '' || in_array(strtolower($clean), $stop, true)) {
                continue;
            }
            $significant[] = $clean;
        }

        if ($significant !== []) {
            $initials = '';
            foreach ($significant as $w) {
                $initials .= strtoupper($w[0]);
            }
            $candidates[] = $initials;
            $candidates[] = strtoupper(substr($significant[0], 0, min(6, strlen($significant[0]))));
        }

        $candidates[] = strtoupper(preg_replace('/[^A-Z0-9]/', '', $title) ?? 'SHOW');

        foreach ($candidates as $candidate) {
            $candidate = strtoupper(substr($candidate, 0, 12));
            if (strlen($candidate) < 2) {
                continue;
            }
            if (!in_array($candidate, $existingUpper, true)) {
                return $candidate;
            }
        }

        $base = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $title) ?? 'SHOW', 0, 8));
        $n = 2;
        while (in_array($base . $n, $existingUpper, true)) {
            $n++;
        }

        return $base . $n;
    }
}
