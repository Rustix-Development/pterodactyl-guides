<?php

namespace RustixDev\Guides\Sync;

/**
 * Splits a guide into its front matter and body. The front matter is the small subset
 * of YAML guides use: one `key: value` per line, values optionally in quotes.
 */
final class FrontMatter
{
    /**
     * @return array{0: array<string, string>, 1: string}|null  null when there is no front matter block
     */
    public static function split(string $markdown): ?array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        if (str_starts_with($markdown, "\u{FEFF}")) {
            $markdown = substr($markdown, 3);
        }
        if (preg_match('/^---[ \t]*\n(.*?)\n---[ \t]*(?:\n|$)/s', $markdown, $m) !== 1) {
            return null;
        }

        $values = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*)$/', $line, $pair) !== 1) {
                continue;
            }
            $values[strtolower($pair[1])] = self::scalar($pair[2]);
        }

        return [$values, substr($markdown, strlen($m[0]))];
    }

    private static function scalar(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $inner = substr($value, 1, -1);

            return $value[0] === '"' ? stripcslashes($inner) : str_replace("''", "'", $inner);
        }
        // A trailing comment, as YAML allows after a space.
        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }
}
