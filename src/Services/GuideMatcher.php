<?php

namespace RustixDev\Guides\Services;

/**
 * Decides which guide folder an egg uses and which framework a server runs, following
 * the repo format: an admin pin first, then egg tags, then the egg name, then the
 * Docker image. Pure logic on plain arrays, so it can be checked without a panel.
 */
final class GuideMatcher
{
    /**
     * @param  array<string, array<string, mixed>|null>  $folders  merged folders in priority order => match.json (null when none is usable)
     * @param  array{name: string, tags: list<string>, images: list<string>}  $egg  tags as slugs and names
     * @param  array{mode: string, folder: string|null}|null  $rule  the admin's choice for the egg
     * @return array{folder: string, via: string}|null
     */
    public static function folderFor(array $folders, array $egg, ?array $rule): ?array
    {
        if ($rule !== null && $rule['mode'] === 'off') {
            return null;
        }
        if ($rule !== null && $rule['mode'] === 'pin' && $rule['folder'] !== null && array_key_exists($rule['folder'], $folders)) {
            return ['folder' => $rule['folder'], 'via' => 'pin'];
        }

        $tags = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $egg['tags']);
        foreach ($folders as $folder => $match) {
            if ($match !== null && array_intersect(array_map('mb_strtolower', $match['tags'] ?? []), $tags) !== []) {
                return ['folder' => (string) $folder, 'via' => 'tag'];
            }
        }

        $name = mb_strtolower(trim($egg['name']));
        foreach ($folders as $folder => $match) {
            foreach ($match['eggs'] ?? [] as $candidate) {
                if (mb_strtolower(trim($candidate)) === $name) {
                    return ['folder' => (string) $folder, 'via' => 'egg'];
                }
            }
        }

        foreach ($folders as $folder => $match) {
            foreach ($match['images'] ?? [] as $prefix) {
                foreach ($egg['images'] as $image) {
                    if ($prefix !== '' && str_starts_with(mb_strtolower($image), mb_strtolower($prefix))) {
                        return ['folder' => (string) $folder, 'via' => 'image'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * The framework key the server runs, or null for vanilla. When the egg has the
     * framework variable its value decides; otherwise an egg tag equal to a key does.
     *
     * @param  array<string, mixed>|null  $match
     * @param  list<string>  $tags
     * @param  array<string, string|null>  $variables  env name => value
     */
    public static function frameworkFor(?array $match, array $tags, array $variables): ?string
    {
        $framework = $match['framework'] ?? null;
        if (! is_array($framework) || ! is_array($framework['values'] ?? null)) {
            return null;
        }
        $values = array_change_key_case($framework['values'], CASE_LOWER);

        $variable = (string) ($framework['variable'] ?? '');
        foreach ($variables as $env => $value) {
            if ($variable !== '' && strcasecmp((string) $env, $variable) === 0) {
                $key = mb_strtolower(trim((string) $value));

                return array_key_exists($key, $values) ? $key : null;
            }
        }

        foreach ($tags as $tag) {
            $key = mb_strtolower(trim($tag));
            if (array_key_exists($key, $values)) {
                return $key;
            }
        }

        return null;
    }
}
