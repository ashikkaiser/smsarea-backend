<?php

namespace App\Support;

/**
 * Detects iOS-style SMS tapback lines (e.g. Loved "hello") so inbound SMS can be
 * flagged even when the Android app does not set is_reaction.
 */
final class SmsReactionParser
{
    /** @return array{type: string, action: 'add'|'remove', target: string}|null */
    public static function parse(?string $body): ?array
    {
        if ($body === null || $body === '') {
            return null;
        }

        $normalized = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $body,
        );
        $normalized = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        $standard = [
            'liked' => 'like',
            'like' => 'like',
            'disliked' => 'dislike',
            'dislike' => 'dislike',
            'loved' => 'love',
            'love' => 'love',
            'heart' => 'love',
            'laughed at' => 'laugh',
            'laughed' => 'laugh',
            'laugh' => 'laugh',
            'emphasized' => 'emphasis',
            'emphasised' => 'emphasis',
            'emphasize' => 'emphasis',
            'emphasise' => 'emphasis',
            'questioned' => 'question',
            'question' => 'question',
        ];

        $standardKeys = implode('|', array_keys($standard));

        // iOS / some carriers use quotes; others send markdown-style **text** or *text*.
        // Try `**…**` before single `*…*` so "Loved **hello**" matches correctly.
        $targetPatternStrict = '(?:\*\*([^*]+)\*\*|\*([^*]+)\*|"([^"]+)"|\'([^\']+)\'|\x{201C}([^\x{201D}]+)\x{201D})';

        // Custom "Reacted … to …" may use an unquoted target on some carriers.
        // Wrap so `|(.+)` does not bind to the whole pattern (PCRE alternation precedence).
        $targetPatternLoose = '(?:'.$targetPatternStrict.'|(.+))';

        $pickTarget = static function (array $m, int $start): string {
            for ($i = $start; $i < count($m); $i++) {
                $v = trim((string) ($m[$i] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }

            return '';
        };

        // Standard remove: Removed (a )?loved from "x"
        if (preg_match(
            '/^Removed\s(?:a\s)?('.$standardKeys.')\s+from\s+'.$targetPatternStrict.'$/iu',
            $normalized,
            $m,
        )) {
            $raw = strtolower($m[1]);
            $canonical = $standard[$raw] ?? $raw;
            $target = $pickTarget($m, 2);
            if ($target === '') {
                return null;
            }

            return ['type' => $canonical, 'action' => 'remove', 'target' => $target];
        }

        // Standard add: loved "x" (allow multiple spaces after verb)
        if (preg_match(
            '/^('.$standardKeys.')\s+'.$targetPatternStrict.'$/iu',
            $normalized,
            $m,
        )) {
            $raw = strtolower($m[1]);
            $canonical = $standard[$raw] ?? $raw;
            $target = $pickTarget($m, 2);
            if ($target === '') {
                return null;
            }

            return ['type' => $canonical, 'action' => 'add', 'target' => $target];
        }

        // Custom add: Reacted 😂 to "x"
        if (preg_match(
            '/^Reacted\s(.+?)\s+to\s+'.$targetPatternLoose.'$/iu',
            $normalized,
            $m,
        )) {
            $target = $pickTarget($m, 2);
            if ($target === '') {
                return null;
            }

            return ['type' => trim($m[1]), 'action' => 'add', 'target' => $target];
        }

        // Custom remove
        if (preg_match(
            '/^Removed\s(.+?)\s+from\s+'.$targetPatternLoose.'$/iu',
            $normalized,
            $m,
        )) {
            $target = $pickTarget($m, 2);
            if ($target === '') {
                return null;
            }

            return ['type' => trim($m[1]), 'action' => 'remove', 'target' => $target];
        }

        return null;
    }
}
