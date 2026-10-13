<?php

namespace RustixDev\Guides\Sync;

/**
 * Reads an extracted repo: every game folder's match.json and guides. A broken file
 * is skipped and reported; it never stops the rest of the repo from being used.
 */
final class RepoParser
{
    /**
     * @return array{
     *     folders: array<string, array<string, mixed>|null>,
     *     guides: list<array{folder: string, slug: string, title: string, framework: string|null, sort: int, summary: string|null, body: string}>,
     *     problems: list<array{path: string, message: string}>
     * }
     */
    public function parse(string $directory): array
    {
        $folders = [];
        $guides = [];
        $problems = [];

        $names = is_dir($directory) ? scandir($directory) : [];
        sort($names);
        foreach ($names as $folder) {
            if (preg_match('/^'.ArchiveExtractor::FOLDER.'$/D', $folder) !== 1 || ! is_dir($directory.'/'.$folder)) {
                continue;
            }
            $path = $directory.'/'.$folder;

            $match = null;
            if (is_file($path.'/match.json')) {
                try {
                    $match = MatchFile::parse((string) file_get_contents($path.'/match.json'), $warning);
                    if ($warning !== null) {
                        $problems[] = ['path' => $folder.'/match.json', 'message' => $warning];
                    }
                } catch (SyncException $exception) {
                    $problems[] = ['path' => $folder.'/match.json', 'message' => $exception->getMessage().' The folder is matched only through other repos.'];
                }
            }

            $files = glob($path.'/*.md') ?: [];
            sort($files);
            $found = 0;
            foreach ($files as $file) {
                $slug = basename($file, '.md');
                if (preg_match('/^'.ArchiveExtractor::SLUG.'$/D', $slug) !== 1) {
                    continue;
                }
                try {
                    $guides[] = ['folder' => $folder, 'slug' => $slug, ...self::guide((string) file_get_contents($file))];
                    $found++;
                } catch (SyncException $exception) {
                    $problems[] = ['path' => $folder.'/'.$slug.'.md', 'message' => $exception->getMessage()];
                }
            }

            if ($match !== null || $found > 0) {
                $folders[$folder] = $match;
            } elseif (! is_file($path.'/match.json')) {
                $problems[] = ['path' => $folder.'/', 'message' => 'No match.json and no guides, skipped.'];
            }
        }

        return ['folders' => $folders, 'guides' => $guides, 'problems' => $problems];
    }

    /**
     * @return array{title: string, framework: string|null, sort: int, summary: string|null, body: string}
     *
     * @throws SyncException
     */
    public static function guide(string $markdown): array
    {
        if (! mb_check_encoding($markdown, 'UTF-8')) {
            throw new SyncException('Not valid UTF-8 text, skipped.');
        }
        $parts = FrontMatter::split($markdown);
        if ($parts === null) {
            throw new SyncException('No front matter (a block between --- lines at the top with at least a title), skipped.');
        }
        [$meta, $body] = $parts;

        $title = trim($meta['title'] ?? '');
        if ($title === '') {
            throw new SyncException('The front matter has no title, skipped.');
        }

        $framework = strtolower(trim($meta['framework'] ?? ''));
        if ($framework !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $framework) !== 1) {
            throw new SyncException('The framework "'.mb_substr($framework, 0, 64).'" is not a valid key, skipped.');
        }

        $order = trim($meta['order'] ?? '');
        $summary = trim($meta['summary'] ?? '');

        return [
            'title' => mb_substr($title, 0, 200),
            'framework' => $framework === '' || $framework === 'vanilla' ? null : $framework,
            'sort' => preg_match('/^-?\d{1,6}$/D', $order) === 1 ? (int) $order : 100,
            'summary' => $summary === '' ? null : mb_substr($summary, 0, 300),
            'body' => trim($body, "\n"),
        ];
    }
}
