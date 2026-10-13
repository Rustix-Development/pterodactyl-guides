<?php

namespace RustixDev\Guides\Sync;

use ZipArchive;

/**
 * Copies the files the extension uses out of a repo archive and nothing else.
 *
 * Entry names are never used as paths as they are: each one must match one of three
 * strict patterns (a game folder's match.json, a guide, or an image under images/), so
 * "..", absolute paths, backslashes and hidden or underscore folders cannot get through.
 * Files are written from their content, so a symlink entry is just skipped. Every file
 * has a size limit, checked on the bytes read and not on what the archive claims.
 */
final class ArchiveExtractor
{
    public const int MAX_ENTRIES = 10000;

    public const int MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    public const int MAX_GUIDE_BYTES = 256 * 1024;

    public const int MAX_MATCH_BYTES = 16 * 1024;

    public const int MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const string FOLDER = '[a-z0-9][a-z0-9-]{0,63}';

    public const string SLUG = '[a-z0-9][a-z0-9-]{0,99}';

    public const string IMAGE = '[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.(?:png|jpe?g|webp|gif)';

    /** @var list<array{path: string, message: string}> */
    private array $problems = [];

    /**
     * Extract into $target, which must not exist yet. Returns the problems found with
     * single files; anything wrong with the archive as a whole throws.
     *
     * @return list<array{path: string, message: string}>
     *
     * @throws SyncException
     */
    public function extract(string $archive, string $target): array
    {
        $this->problems = [];
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new SyncException('The download is not a valid zip archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new SyncException('The repo has more than '.self::MAX_ENTRIES.' files.');
            }

            $root = $this->commonRoot($zip);
            $total = 0;
            if (! @mkdir($target, 0750, true) && ! is_dir($target)) {
                throw new SyncException('Could not create the folder for the synced copy.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $path = $root !== null ? substr($name, strlen($root)) : $name;

                $limit = $this->limitFor($path);
                if ($limit === null) {
                    continue;
                }
                if ($this->isSymlink($zip, $i)) {
                    $this->problem($path, 'Symbolic links are not followed.');

                    continue;
                }

                $content = $zip->getFromIndex($i, $limit + 1);
                if (! is_string($content)) {
                    $this->problem($path, 'Could not be read from the archive.');

                    continue;
                }
                if (strlen($content) > $limit) {
                    $this->problem($path, sprintf('Larger than %d KB, skipped.', intdiv($limit, 1024)));

                    continue;
                }
                if (str_contains($path, '/images/') && ! self::looksLikeImage($content)) {
                    $this->problem($path, 'Not a PNG, JPEG, WebP or GIF image, skipped.');

                    continue;
                }

                $total += strlen($content);
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new SyncException(sprintf('The guide files add up to more than %d MB.', intdiv(self::MAX_TOTAL_BYTES, 1024 * 1024)));
                }

                $file = $target.'/'.$path;
                if (! is_dir(dirname($file))) {
                    mkdir(dirname($file), 0750, true);
                }
                file_put_contents($file, $content);
            }
        } finally {
            $zip->close();
        }

        return $this->problems;
    }

    /**
     * The size limit for a path the extension uses, or null to ignore it. Markdown files
     * in a game folder that break the naming rules are reported, not ignored silently.
     */
    private function limitFor(string $path): ?int
    {
        $folder = self::FOLDER;
        if (preg_match('#^'.$folder.'/match\.json$#D', $path) === 1) {
            return self::MAX_MATCH_BYTES;
        }
        if (preg_match('#^'.$folder.'/'.self::SLUG.'\.md$#D', $path) === 1) {
            return self::MAX_GUIDE_BYTES;
        }
        if (preg_match('#^'.$folder.'/images/'.self::IMAGE.'$#Di', $path) === 1) {
            return self::MAX_IMAGE_BYTES;
        }
        if (preg_match('#^'.$folder.'/[^/]+\.md$#D', $path) === 1 && ! preg_match('#/(readme|contributing|license)\.md$#i', $path)) {
            $this->problem($path, 'Guide file names must be lower case letters, digits and hyphens, skipped.');
        }

        return null;
    }

    /**
     * GitHub archives put everything in one top folder named after the repo and commit.
     * Returns it with its trailing slash, or null when entries do not share one.
     */
    private function commonRoot(ZipArchive $zip): ?string
    {
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $slash = strpos($name, '/');
            if ($slash === false) {
                return null;
            }
            $first = substr($name, 0, $slash + 1);
            if ($root !== null && $first !== $root) {
                return null;
            }
            $root = $first;
        }

        return $root;
    }

    /** The commit-ish part of GitHub's top folder name (owner-repo-<sha>), if any. */
    public static function revision(string $archive): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            return null;
        }
        $name = (string) $zip->getNameIndex(0);
        $zip->close();

        return preg_match('#^[^/]*-([0-9a-f]{7,40})/#', $name, $m) === 1 ? $m[1] : null;
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $os = $attributes = 0;
        if (! $zip->getExternalAttributesIndex($index, $os, $attributes) || $os !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (($attributes >> 16) & 0170000) === 0120000;
    }

    public static function looksLikeImage(string $content): bool
    {
        return str_starts_with($content, "\x89PNG\r\n\x1a\n")
            || str_starts_with($content, "\xFF\xD8\xFF")
            || str_starts_with($content, 'GIF87a') || str_starts_with($content, 'GIF89a')
            || (str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP');
    }

    private function problem(string $path, string $message): void
    {
        $this->problems[] = ['path' => $path, 'message' => $message];
    }
}
