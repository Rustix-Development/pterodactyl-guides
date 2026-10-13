<?php

namespace RustixDev\Guides\Services;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use RustixDev\Guides\Models\EggRule;
use RustixDev\Guides\Models\Folder;
use RustixDev\Guides\Models\Guide;
use RustixDev\Guides\Models\Repo;

/**
 * The synced guides of every repo merged into one set, as the format describes: repos
 * are read top to bottom with the community repo last, a higher repo's match.json wins,
 * and a guide with the same slug in a higher repo replaces the lower one.
 */
class GuideCatalog
{
    /** @var array<int, int>|null repo id => rank (0 is highest) */
    private ?array $ranks = null;

    /** @var array<string, array{match: array<string, mixed>|null, repos: list<int>}>|null */
    private ?array $folders = null;

    /** @return array<int, int> */
    public function ranks(): array
    {
        return $this->ranks ??= array_flip(Repo::query()->ordered()->pluck('id')->all());
    }

    /**
     * Every folder in priority order (the rank of the repo its match.json comes from,
     * then name), with the repos that have it.
     *
     * @return array<string, array{match: array<string, mixed>|null, repos: list<int>}>
     */
    public function folders(): array
    {
        if ($this->folders !== null) {
            return $this->folders;
        }

        $ranks = $this->ranks();
        $rows = Folder::query()->get(['repo_id', 'folder', 'match'])
            ->filter(fn (Folder $row) => isset($ranks[$row->repo_id]))
            ->sortBy(fn (Folder $row) => $ranks[$row->repo_id]);

        $folders = [];
        foreach ($rows as $row) {
            $entry = $folders[$row->folder] ?? ['match' => null, 'repos' => [], 'rank' => PHP_INT_MAX];
            $entry['repos'][] = $row->repo_id;
            if ($entry['match'] === null && is_array($row->match)) {
                $entry['match'] = $row->match;
                $entry['rank'] = $ranks[$row->repo_id];
            }
            $folders[$row->folder] = $entry;
        }

        uksort($folders, fn (string $a, string $b) => [$folders[$a]['rank'], $a] <=> [$folders[$b]['rank'], $b]);

        return $this->folders = array_map(fn (array $f) => ['match' => $f['match'], 'repos' => $f['repos']], $folders);
    }

    /** @return array<string, array<string, mixed>|null> folder => match, for the matcher */
    public function matches(): array
    {
        return array_map(fn (array $f) => $f['match'], $this->folders());
    }

    /** The label of a folder: its match.json name, else the folder name. */
    public function label(string $folder): string
    {
        return (string) ($this->folders()[$folder]['match']['name'] ?? $folder);
    }

    /**
     * The merged guides of a folder without their bodies, sorted by order then title.
     *
     * @return list<Guide>
     */
    public function guides(string $folder): array
    {
        $ranks = $this->ranks();
        $bySlug = [];
        $rows = Guide::query()->where('folder', $folder)->get(['id', 'repo_id', 'folder', 'slug', 'title', 'framework', 'sort', 'summary'])
            ->filter(fn (Guide $g) => isset($ranks[$g->repo_id]))
            ->sortBy(fn (Guide $g) => $ranks[$g->repo_id]);
        foreach ($rows as $guide) {
            $bySlug[$guide->slug] ??= $guide;
        }

        $guides = array_values($bySlug);
        usort($guides, fn (Guide $a, Guide $b) => [$a->sort, mb_strtolower($a->title), $a->slug] <=> [$b->sort, mb_strtolower($b->title), $b->slug]);

        return $guides;
    }

    /** One guide with its body, from the highest repo that has it. */
    public function guide(string $folder, string $slug): ?Guide
    {
        $ranks = $this->ranks();

        return Guide::query()->where('folder', $folder)->where('slug', $slug)->get()
            ->filter(fn (Guide $g) => isset($ranks[$g->repo_id]))
            ->sortBy(fn (Guide $g) => $ranks[$g->repo_id])
            ->first();
    }

    /** @return array{name: string, tags: list<string>, images: list<string>} */
    public static function eggFacts(Egg $egg): array
    {
        $egg->loadMissing('tags');
        $tags = [];
        foreach ($egg->tags as $tag) {
            $tags[] = mb_strtolower((string) $tag->slug);
            $tags[] = mb_strtolower((string) $tag->name);
        }

        return [
            'name' => (string) $egg->name,
            'tags' => array_values(array_unique($tags)),
            'images' => array_values(array_map('strval', $egg->docker_images ?? [])),
        ];
    }

    /** @return array{mode: string, folder: string|null}|null */
    public static function ruleFor(int $eggId): ?array
    {
        $rule = EggRule::query()->find($eggId);

        return $rule ? ['mode' => $rule->mode, 'folder' => $rule->folder] : null;
    }

    /** The guide folder the server uses, or null when none matches or guides are off for its egg. */
    public function folderForServer(Server $server): ?string
    {
        $server->loadMissing('egg.tags');
        if ($server->egg === null) {
            return null;
        }

        $facts = self::eggFacts($server->egg);
        // Matched on the image the server runs, which may not be one of the egg's defaults.
        $facts['images'] = [(string) $server->image];

        return GuideMatcher::folderFor($this->matches(), $facts, self::ruleFor($server->egg->id))['folder'] ?? null;
    }

    /**
     * What the server's Guides tab shows: the folder, the framework and the guides
     * grouped "General" first, then the framework's own. Null when nothing matches.
     *
     * @return array{folder: string, label: string, framework: array{key: string, label: string}|null, groups: list<array{label: string, framework: string|null, guides: list<Guide>}>}|null
     */
    public function forServer(Server $server): ?array
    {
        $server->loadMissing('egg.tags');
        if ($server->egg === null) {
            return null;
        }

        $folder = $this->folderForServer($server);
        if ($folder === null) {
            return null;
        }

        $facts = self::eggFacts($server->egg);
        $match = $this->folders()[$folder]['match'];
        $variables = [];
        foreach ($server->variables as $variable) {
            $variables[$variable->env_variable] = $variable->server_value ?? $variable->default_value;
        }
        $key = GuideMatcher::frameworkFor($match, $facts['tags'], $variables);

        $general = [];
        $framework = [];
        foreach ($this->guides($folder) as $guide) {
            if ($guide->framework === null) {
                $general[] = $guide;
            } elseif ($key !== null && $guide->framework === $key) {
                $framework[] = $guide;
            }
        }

        $groups = [];
        if ($general !== []) {
            $groups[] = ['label' => 'General', 'framework' => null, 'guides' => $general];
        }
        $frameworkLabel = $key !== null ? (string) $match['framework']['values'][$key] : null;
        if ($framework !== []) {
            $groups[] = ['label' => (string) $frameworkLabel, 'framework' => $key, 'guides' => $framework];
        }

        return [
            'folder' => $folder,
            'label' => $this->label($folder),
            'framework' => $key !== null ? ['key' => $key, 'label' => (string) $frameworkLabel] : null,
            'groups' => $groups,
        ];
    }
}
