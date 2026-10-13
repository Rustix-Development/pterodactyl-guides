<?php

namespace RustixDev\Guides\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use RustixDev\Guides\Models\Guide;
use RustixDev\Guides\Models\Repo;
use RustixDev\Guides\Services\GuideCatalog;
use RustixDev\Guides\Services\GuideRenderer;
use RustixDev\Guides\Services\GuideSync;
use RustixDev\Guides\Support\Placeholders;
use RustixDev\Guides\Sync\ArchiveExtractor;

/** The server's Guides tab. Only reads the synced copy; GitHub is never contacted here. */
class GuideController
{
    public function __construct(private readonly GuideCatalog $catalog, private readonly GuideRenderer $renderer) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $this->catalog->forServer($server);

        return new JsonResponse([
            'folder' => $data['folder'] ?? null,
            'label' => $data['label'] ?? null,
            'framework' => $data['framework'] ?? null,
            'groups' => array_map(fn (array $group) => [
                'label' => $group['label'],
                'framework' => $group['framework'],
                'guides' => array_map(fn (Guide $g) => ['slug' => $g->slug, 'title' => $g->title, 'summary' => $g->summary], $group['guides']),
            ], $data['groups'] ?? []),
        ]);
    }

    public function show(Request $request, Server $server, string $slug): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $this->catalog->forServer($server);
        abort_if($data === null, 404, 'This server has no guides.');

        $visible = [];
        $group = null;
        foreach ($data['groups'] as $candidate) {
            foreach ($candidate['guides'] as $guide) {
                $visible[] = $guide->slug;
                if ($guide->slug === $slug) {
                    $group = $candidate;
                }
            }
        }
        abort_if($group === null, 404, 'That guide does not exist for this server.');

        $guide = $this->catalog->guide($data['folder'], $slug);
        abort_if($guide === null, 404, 'That guide does not exist for this server.');

        $folder = $data['folder'];
        $html = $this->renderer->render(
            $guide->body,
            Placeholders::forServer($server),
            fn (string $file) => $this->imageUrl($server, $folder, $guide->repo_id, $file),
            $visible,
        );

        return new JsonResponse([
            'slug' => $guide->slug,
            'title' => $guide->title,
            'summary' => $guide->summary,
            'group' => $group['label'],
            'html' => $html,
        ]);
    }

    /**
     * The panel URL of an image a guide uses: from the guide's own repo, else from the
     * highest other repo that has the same file in the same folder.
     */
    private function imageUrl(Server $server, string $folder, int $repoId, string $file): ?string
    {
        if (preg_match('/^'.ArchiveExtractor::IMAGE.'$/Di', $file) !== 1) {
            return null;
        }
        $order = array_keys($this->catalog->ranks());
        usort($order, fn (int $a, int $b) => ($b === $repoId) <=> ($a === $repoId));

        $candidates = [];
        foreach (Repo::query()->whereIn('id', $this->catalog->folders()[$folder]['repos'] ?? [])->get() as $repo) {
            $candidates[$repo->id] = $repo;
        }
        foreach ($order as $id) {
            $repo = $candidates[$id] ?? null;
            $directory = $repo ? GuideSync::directory($repo) : null;
            if ($directory !== null && is_file($directory.'/'.$folder.'/images/'.$file)) {
                return '/extensions/guides/'.$server->uuid.'/images/'.$repo->id.'/'.$folder.'/'.rawurlencode($file);
            }
        }

        return null;
    }

    private function authorize(Request $request, Server $server): void
    {
        abort_unless($request->user()->can('ext.guides.view', $server), 403, 'You do not have permission to view guides.');
    }
}
