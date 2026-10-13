<?php

namespace RustixDev\Guides\Sync;

/**
 * Reads a folder's match.json into the shape the matcher uses. Unknown keys are ignored;
 * a file without a usable name is refused, and a broken framework block is dropped.
 */
final class MatchFile
{
    /**
     * @return array{name: string, tags: list<string>, eggs: list<string>, images: list<string>, framework: array{variable: string|null, values: array<string, string>}|null}
     *
     * @throws SyncException with the reason the file is unusable
     */
    public static function parse(string $json, ?string &$warning = null): array
    {
        $warning = null;
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SyncException('match.json is not valid JSON: '.$exception->getMessage().'.');
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new SyncException('match.json must be a JSON object.');
        }

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new SyncException('match.json needs a "name" of 1 to 100 characters.');
        }

        $match = [
            'name' => $name,
            'tags' => array_map('strtolower', self::strings($data['tags'] ?? [])),
            'eggs' => self::strings($data['eggs'] ?? []),
            'images' => self::strings($data['images'] ?? []),
            'framework' => null,
        ];

        if (isset($data['framework'])) {
            $framework = $data['framework'];
            $variable = is_array($framework) && is_string($framework['variable'] ?? null) ? trim($framework['variable']) : '';
            $values = [];
            foreach ((is_array($framework) && is_array($framework['values'] ?? null) ? $framework['values'] : []) as $key => $label) {
                if (is_string($key) && is_string($label) && trim($key) !== '' && trim($label) !== '' && strlen($key) <= 64) {
                    $values[strtolower(trim($key))] = mb_substr(trim($label), 0, 100);
                }
            }
            unset($values['vanilla']);

            if ($variable !== '' && preg_match('/^[A-Za-z0-9_]{1,191}$/D', $variable) !== 1) {
                $warning = 'The framework "variable" must be a startup variable name such as FRAMEWORK; the framework block is ignored.';
            } elseif ($values === []) {
                $warning = 'The "framework" block needs at least one entry in "values"; it is ignored.';
            } else {
                // Without a variable, only an egg tag equal to a key picks the framework.
                $match['framework'] = ['variable' => $variable === '' ? null : $variable, 'values' => $values];
            }
        }

        return $match;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '' && strlen($item) <= 200) {
                $strings[] = trim($item);
            }
        }

        return array_slice(array_values(array_unique($strings)), 0, 100);
    }
}
