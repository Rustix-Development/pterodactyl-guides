<?php

namespace RustixDev\Guides\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Egg;
use RustixDev\Guides\Models\EggRule;
use RustixDev\Guides\Models\Folder;
use RustixDev\Guides\Models\Guide;
use RustixDev\Guides\Models\Repo;
use RustixDev\Guides\Services\GuideCatalog;
use RustixDev\Guides\Services\GuideMatcher;
use RustixDev\Guides\Services\GuideSync;
use RustixDev\Guides\Support\RepoName;
use RustixDev\Guides\Support\Settings;
use RustixDev\Guides\Sync\GitHubSource;
use RustixDev\Guides\Sync\SyncException;

/** Admin → Guides: repos, sync, and which guides each egg gets. */
class AdminController
{
    public const int MAX_REPOS = 20;

    public function __construct(private readonly GuideCatalog $catalog, private readonly GuideSync $sync) {}

    public function index(): JsonResponse
    {
        return new JsonResponse($this->overview());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'repo' => ['required', 'string', 'max:200'],
            'branch' => ['nullable', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);
        $name = RepoName::normalize($data['repo']);
        abort_if($name === null, 422, 'Enter the repo as owner/name, for example myhost/our-guides, or paste its GitHub URL.');
        $branch = trim((string) ($data['branch'] ?? '')) ?: 'main';
        abort_unless(RepoName::validBranch($branch), 422, 'That is not a valid branch name.');
        abort_if(Repo::query()->whereRaw('LOWER(repo) = ?', [strtolower($name)])->exists(), 422, "{$name} is already in the list.");
        abort_if(Repo::query()->where('community', false)->count() >= self::MAX_REPOS, 422, 'At most '.self::MAX_REPOS.' repos can be added.');

        Repo::query()->create([
            'repo' => $name,
            'branch' => $branch,
            'token' => ($token = trim((string) ($data['token'] ?? ''))) === '' ? null : $token,
            'position' => (int) Repo::query()->where('community', false)->max('position') + 1,
            'community' => false,
        ]);

        return new JsonResponse($this->overview(), 201);
    }

    public function update(Request $request, int $repo): JsonResponse
    {
        $repo = Repo::query()->findOrFail($repo);
        abort_if($repo->community, 422, 'The community repo cannot be changed.');
        $data = $request->validate([
            'branch' => ['sometimes', 'string', 'max:100'],
            'token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('branch', $data)) {
            $branch = trim($data['branch']);
            abort_unless(RepoName::validBranch($branch), 422, 'That is not a valid branch name.');
            $repo->branch = $branch;
        }
        if (array_key_exists('token', $data)) {
            $token = trim((string) $data['token']);
            $repo->token = $token === '' ? null : $token;
        }
        if ($repo->isDirty()) {
            // Picked up by the next scheduled run.
            $repo->attempted_at = null;
            $repo->save();
        }

        return new JsonResponse($this->overview());
    }

    public function destroy(int $repo): JsonResponse
    {
        $repo = Repo::query()->findOrFail($repo);
        abort_if($repo->community, 422, 'The community repo is always included and cannot be removed.');
        $directory = GuideSync::directory($repo);
        $repo->delete();
        if ($directory !== null) {
            File::deleteDirectory($directory);
        }

        return new JsonResponse($this->overview());
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.self::MAX_REPOS], 'ids.*' => ['integer', 'distinct']]);
        $ids = array_map('intval', $data['ids']);
        $existing = Repo::query()->where('community', false)->pluck('id')->all();
        sort($existing);
        $sorted = $ids;
        sort($sorted);
        abort_unless($sorted === $existing, 422, 'The list of repos changed. Reload the page and try again.');

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                Repo::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });

        return new JsonResponse($this->overview());
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['interval' => ['required', 'integer', Rule::in(array_keys(Settings::INTERVALS))]]);
        Settings::setInterval((int) $data['interval']);

        return new JsonResponse($this->overview());
    }

    public function sync(): JsonResponse
    {
        @set_time_limit(600);
        try {
            $lines = $this->sync->syncAll();
        } catch (SyncException $exception) {
            abort(409, $exception->getMessage());
        }

        return new JsonResponse([...$this->overview(), 'result' => $lines]);
    }

    public function egg(Request $request, int $egg): JsonResponse
    {
        $egg = Egg::query()->findOrFail($egg);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['auto', EggRule::PIN, EggRule::OFF])],
            'folder' => ['nullable', 'required_if:mode,pin', 'string', 'max:64'],
        ]);

        if ($data['mode'] === 'auto') {
            EggRule::query()->whereKey($egg->id)->delete();
        } else {
            abort_if($data['mode'] === EggRule::PIN && ! array_key_exists((string) $data['folder'], $this->catalog->folders()), 422, 'That guide folder does not exist. Sync first, or pick another one.');
            EggRule::query()->updateOrCreate(['egg_id' => $egg->id], [
                'mode' => $data['mode'],
                'folder' => $data['mode'] === EggRule::PIN ? $data['folder'] : null,
            ]);
        }

        return new JsonResponse($this->overview());
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        $folders = $this->catalog->folders();
        $matches = $this->catalog->matches();
        $repos = Repo::query()->ordered()->get();
        $names = $repos->pluck('repo', 'id')->all();

        $guideCounts = Guide::query()->select('repo_id', DB::raw('COUNT(*) as total'))->groupBy('repo_id')->pluck('total', 'repo_id');
        $folderCounts = Folder::query()->select('repo_id', DB::raw('COUNT(*) as total'))->groupBy('repo_id')->pluck('total', 'repo_id');
        $merged = [];
        foreach (array_keys($folders) as $folder) {
            $merged[$folder] = $this->catalog->guides($folder);
        }

        $rules = EggRule::query()->get()->keyBy('egg_id');
        $eggs = Egg::query()->with('tags')->withCount('servers')->orderBy('name')->get()->map(function (Egg $egg) use ($matches, $rules) {
            $rule = $rules->get($egg->id);
            $found = GuideMatcher::folderFor($matches, GuideCatalog::eggFacts($egg), $rule ? ['mode' => $rule->mode, 'folder' => $rule->folder] : null);

            return [
                'id' => $egg->id,
                'name' => $egg->name,
                'tags' => $egg->tags->pluck('name')->values(),
                'servers' => (int) $egg->servers_count,
                'mode' => $rule->mode ?? 'auto',
                'pinned' => $rule?->folder,
                'pin_missing' => $rule?->mode === EggRule::PIN && ! array_key_exists((string) $rule->folder, $matches),
                'matched' => $found === null ? null : ['folder' => $found['folder'], 'label' => $this->catalog->label($found['folder']), 'via' => $found['via']],
            ];
        });

        // Problems that only show once all repos are merged.
        $problems = [];
        foreach ($folders as $folder => $info) {
            if ($info['match'] === null) {
                $problems[] = ['path' => $folder.'/', 'message' => 'No repo has a usable match.json for this folder, so no egg gets it unless you pin it.'];
            }
            $values = $info['match']['framework']['values'] ?? [];
            foreach ($merged[$folder] as $guide) {
                if ($guide->framework !== null && ! array_key_exists($guide->framework, $values)) {
                    $problems[] = ['path' => $folder.'/'.$guide->slug.'.md', 'message' => "The framework \"{$guide->framework}\" is not one of match.json's framework values, so this guide never shows.", 'repo' => $names[$guide->repo_id] ?? null];
                }
            }
        }

        return [
            'repos' => $repos->map(fn (Repo $repo) => [
                'id' => $repo->id,
                'repo' => $repo->repo,
                'branch' => $repo->branch,
                'url' => $repo->url(),
                'community' => $repo->community,
                'has_token' => $repo->token !== null && $repo->token !== '',
                'revision' => $repo->revision,
                'synced_at' => $repo->synced_at?->toIso8601String(),
                'attempted_at' => $repo->attempted_at?->toIso8601String(),
                'error' => $repo->error,
                'guides' => (int) ($guideCounts[$repo->id] ?? 0),
                'folders' => (int) ($folderCounts[$repo->id] ?? 0),
                'problems' => $repo->problems ?? [],
            ])->values(),
            'folders' => collect($folders)->map(fn (array $info, string $folder) => [
                'folder' => $folder,
                'label' => $this->catalog->label($folder),
                'has_match' => $info['match'] !== null,
                'repos' => array_values(array_map(fn (int $id) => $names[$id] ?? '?', $info['repos'])),
                'guides' => count($merged[$folder]),
                'frameworks' => array_values($info['match']['framework']['values'] ?? []),
            ])->values(),
            'eggs' => $eggs->values(),
            'problems' => $problems,
            'interval' => Settings::interval(),
            'intervals' => collect(Settings::INTERVALS)->map(fn (string $label, int $value) => ['value' => $value, 'label' => $label])->values(),
            'source' => app(GitHubSource::class)->apiUrl(),
        ];
    }
}
