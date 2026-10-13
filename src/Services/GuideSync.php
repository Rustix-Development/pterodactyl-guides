<?php

namespace RustixDev\Guides\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RustixDev\Guides\Models\Folder;
use RustixDev\Guides\Models\Guide;
use RustixDev\Guides\Models\Repo;
use RustixDev\Guides\Support\Settings;
use RustixDev\Guides\Sync\ArchiveExtractor;
use RustixDev\Guides\Sync\GitHubSource;
use RustixDev\Guides\Sync\RepoParser;
use RustixDev\Guides\Sync\SyncException;
use Throwable;

/**
 * Downloads every repo into panel storage and replaces its rows in the cache tables.
 * A repo that fails keeps its previous copy, so the Guides tab never goes empty
 * because GitHub is down or a commit broke something.
 */
class GuideSync
{
    public const string LOCK = 'ext-guides:sync';

    /** Problems kept per repo for the admin page. */
    public const int MAX_PROBLEMS = 200;

    public function __construct(
        private readonly GitHubSource $source,
        private readonly ArchiveExtractor $extractor,
        private readonly RepoParser $parser,
    ) {}

    public static function root(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'ext-guides');
    }

    /** The folder of a repo's synced copy, or null before its first sync. */
    public static function directory(Repo $repo): ?string
    {
        return $repo->directory !== null && preg_match('/^[a-z0-9]{20}$/D', $repo->directory) === 1
            ? self::root().DIRECTORY_SEPARATOR.$repo->directory
            : null;
    }

    public function isDue(Repo $repo): bool
    {
        if ($repo->attempted_at === null) {
            return true;
        }
        $interval = Settings::interval();

        return $interval > 0 && $repo->attempted_at->lte(now()->subMinutes($interval)->addSeconds(30));
    }

    /**
     * Sync every repo (or, with $dueOnly, those whose interval has passed).
     *
     * @return list<string> one line per repo synced
     *
     * @throws SyncException when another sync is running
     */
    public function syncAll(bool $dueOnly = false): array
    {
        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            throw new SyncException('A sync is already running. Try again in a minute.');
        }

        try {
            $lines = [];
            foreach (Repo::query()->ordered()->get() as $repo) {
                if (! $dueOnly || $this->isDue($repo)) {
                    $lines[] = $this->syncRepo($repo);
                }
            }
            $this->removeStaleCopies();

            return $lines;
        } finally {
            $lock->release();
        }
    }

    /** Sync one repo. Never throws: a failure is stored on the repo for the admin page. */
    public function syncRepo(Repo $repo): string
    {
        $root = self::root();
        File::ensureDirectoryExists($root, 0750);
        $archive = $root.DIRECTORY_SEPARATOR.'download-'.Str::lower(Str::random(16)).'.zip';
        $name = Str::lower(Str::random(20));
        $target = $root.DIRECTORY_SEPARATOR.$name;
        $previous = self::directory($repo);

        try {
            $this->source->download($repo->repo, $repo->branch, $repo->token, $archive);
            $revision = ArchiveExtractor::revision($archive);
            $problems = $this->extractor->extract($archive, $target);
            $parsed = $this->parser->parse($target);
            $problems = array_slice([...$problems, ...$parsed['problems']], 0, self::MAX_PROBLEMS);

            DB::transaction(function () use ($repo, $parsed, $problems, $name, $revision): void {
                Folder::query()->where('repo_id', $repo->id)->delete();
                Guide::query()->where('repo_id', $repo->id)->delete();

                $folders = [];
                foreach ($parsed['folders'] as $folder => $match) {
                    $folders[] = ['repo_id' => $repo->id, 'folder' => $folder, 'match' => $match === null ? null : json_encode($match)];
                }
                foreach (array_chunk($folders, 200) as $chunk) {
                    Folder::query()->insert($chunk);
                }
                foreach (array_chunk($parsed['guides'], 25) as $chunk) {
                    Guide::query()->insert(array_map(fn (array $g) => ['repo_id' => $repo->id, ...$g], $chunk));
                }

                $repo->forceFill([
                    'directory' => $name,
                    'revision' => $revision,
                    'problems' => $problems,
                    'synced_at' => now(),
                    'attempted_at' => now(),
                    'error' => null,
                ])->save();
            });

            if ($previous !== null) {
                File::deleteDirectory($previous);
            }

            return sprintf('%s: %d folders, %d guides, %d problems', $repo->repo, count($parsed['folders']), count($parsed['guides']), count($problems));
        } catch (Throwable $throwable) {
            File::deleteDirectory($target);
            if (! $throwable instanceof SyncException) {
                report($throwable);
            }
            $message = $throwable instanceof SyncException ? $throwable->getMessage() : 'Unexpected error: '.$throwable->getMessage();
            $repo->forceFill(['attempted_at' => now(), 'error' => mb_substr($message, 0, 1000)])->save();

            return $repo->repo.': '.$message;
        } finally {
            if (is_file($archive)) {
                @unlink($archive);
            }
        }
    }

    /** Copies no repo points at any more: removed repos, or a sync that died halfway. */
    private function removeStaleCopies(): void
    {
        $root = self::root();
        $used = Repo::query()->whereNotNull('directory')->pluck('directory')->all();
        foreach (File::directories($root) as $directory) {
            if (! in_array(basename($directory), $used, true) && filemtime($directory) < time() - 3600) {
                File::deleteDirectory($directory);
            }
        }
        foreach (File::glob($root.DIRECTORY_SEPARATOR.'download-*.zip') as $file) {
            if (filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }
}
