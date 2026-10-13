<?php

// A stand-in for the GitHub API's zipball endpoint, for trying the extension on a local
// panel before the guide repos exist on GitHub:
//
//   GUIDES_COMMUNITY=/path/to/egg-guides php -S 127.0.0.1:8099 tests/fake-github.php
//   GUIDES_GITHUB_API_URL=http://127.0.0.1:8099 php artisan guides:sync
//
// Rustix-Development/pterodactyl-egg-guides is served from GUIDES_COMMUNITY (default:
// tests/fixtures/community) and <anyone>/host-guides from tests/fixtures/host. Repos named
// private-<something> answer 404 unless a token is sent, like a private repo does.

require __DIR__.'/support.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/repos/([^/]+)/([^/]+)/zipball/(.+)$#', $path, $m) !== 1) {
    http_response_code(404);
    exit;
}
[, $owner, $repo, $branch] = $m;
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

$directory = match (true) {
    "{$owner}/{$repo}" === 'Rustix-Development/pterodactyl-egg-guides' => getenv('GUIDES_COMMUNITY') ?: __DIR__.'/fixtures/community',
    $repo === 'host-guides', $repo === 'private-guides' => __DIR__.'/fixtures/host',
    default => null,
};
if ($directory === null || $branch !== 'main' || (str_starts_with($repo, 'private-') && $token !== 'Bearer test-token')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"message":"Not Found"}';
    exit;
}

$zip = tempnam(sys_get_temp_dir(), 'guides');
guides_zip_directory($directory, $zip, "{$owner}-{$repo}-".substr(sha1($directory), 0, 7));
header('Content-Type: application/zip');
readfile($zip);
unlink($zip);
