<?php

namespace RustixDev\Guides\Http\Controllers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use RustixDev\Guides\Models\Repo;
use RustixDev\Guides\Services\GuideCatalog;
use RustixDev\Guides\Services\GuideSync;
use RustixDev\Guides\Sync\ArchiveExtractor;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the images guides use from the synced copy, to users who can see the guide. */
class ImageController
{
    private const array TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public function __invoke(Request $request, GuideCatalog $catalog, string $server, string $repo, string $folder, string $file): BinaryFileResponse
    {
        abort_unless(preg_match('/^'.ArchiveExtractor::FOLDER.'$/D', $folder) === 1 && preg_match('/^'.ArchiveExtractor::IMAGE.'$/Di', $file) === 1 && ctype_digit($repo), 404);

        $model = Server::query()->where('uuid', $server)->first();
        abort_if($model === null || ! $request->user()->can('ext.guides.view', $model), 404);
        abort_unless($catalog->folderForServer($model) === $folder, 404);

        $source = Repo::query()->find((int) $repo);
        $directory = $source ? GuideSync::directory($source) : null;
        abort_if($directory === null, 404);

        $path = realpath($directory.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.$file);
        $base = realpath($directory);
        abort_unless($path !== false && $base !== false && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path), 404);

        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        abort_if($type === null, 404);

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
