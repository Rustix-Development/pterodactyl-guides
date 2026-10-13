<?php

namespace RustixDev\Guides\Support;

final class RepoName
{
    /**
     * "owner/name" from what an admin typed: the short form, a github.com URL or a
     * clone URL. Null when it is none of those.
     */
    public static function normalize(string $input): ?string
    {
        $value = trim($input);
        $value = (string) preg_replace('#^(?:https?://)?(?:www\.)?github\.com/#i', '', $value);
        $value = (string) preg_replace('#^git@github\.com:#i', '', $value);
        $value = (string) preg_replace('#(?:\.git)?/*$#', '', $value);
        // A pasted URL may go on to /tree/<branch> or similar.
        $parts = explode('/', $value);
        if (count($parts) < 2) {
            return null;
        }
        [$owner, $name] = $parts;

        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/D', $owner) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $name) !== 1 || $name === '.' || $name === '..') {
            return null;
        }

        return $owner.'/'.$name;
    }

    public static function validBranch(string $branch): bool
    {
        return preg_match('#^[A-Za-z0-9._/-]{1,100}$#D', $branch) === 1
            && ! str_contains($branch, '..')
            && ! str_starts_with($branch, '/')
            && ! str_ends_with($branch, '/');
    }
}
