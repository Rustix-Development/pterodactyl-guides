<?php

namespace RustixDev\Guides\Sync;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Downloads a branch of a GitHub repo as a zip archive through the panel's HTTP client.
 *
 * The API address can be changed with the GUIDES_GITHUB_API_URL environment variable
 * (GitHub Enterprise, or a local stand-in when testing); it defaults to api.github.com.
 */
class GitHubSource
{
    public const string DEFAULT_API = 'https://api.github.com';

    public const int MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

    public function apiUrl(): string
    {
        $url = env('GUIDES_GITHUB_API_URL');

        return rtrim(is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : self::DEFAULT_API, '/');
    }

    public function archiveUrl(string $repo, string $branch): string
    {
        [$owner, $name] = explode('/', $repo, 2);

        return $this->apiUrl().'/repos/'.rawurlencode($owner).'/'.rawurlencode($name).'/zipball/'.str_replace('%2F', '/', rawurlencode($branch));
    }

    /**
     * Download into $path.
     *
     * @throws SyncException
     */
    public function download(string $repo, string $branch, ?string $token, string $path): void
    {
        $request = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'pterodactyl-guides',
        ])
            ->connectTimeout(10)
            ->timeout(90)
            ->withOptions([
                'on_headers' => function (ResponseInterface $response): void {
                    $length = filter_var($response->getHeaderLine('Content-Length'), FILTER_VALIDATE_INT);
                    if ($length !== false && $length > self::MAX_DOWNLOAD_BYTES) {
                        throw new SyncException($this->tooLarge());
                    }
                },
                // Archives are usually sent without a length, so count while they arrive.
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > self::MAX_DOWNLOAD_BYTES) {
                        throw new SyncException($this->tooLarge());
                    }
                },
            ]);
        if ($token !== null && $token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->get($this->archiveUrl($repo, $branch));
        } catch (Throwable $throwable) {
            for ($cause = $throwable; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof SyncException) {
                    throw $cause;
                }
            }
            if ($throwable instanceof ConnectionException || $throwable instanceof \GuzzleHttp\Exception\TransferException) {
                throw new SyncException('Could not reach GitHub: '.mb_substr($throwable->getMessage(), 0, 300));
            }

            throw $throwable;
        }

        $status = $response->status();
        if (! $response->successful()) {
            throw new SyncException(match (true) {
                $status === 404 => $token
                    ? "{$repo} or its branch \"{$branch}\" was not found, or the token cannot read it."
                    : "{$repo} or its branch \"{$branch}\" was not found. If the repo is private, add a token.",
                $status === 401 => 'GitHub refused the token. Check that it is valid and has not expired.',
                $status === 403 || $status === 429 => $response->header('X-RateLimit-Remaining') === '0'
                    ? 'GitHub rate limit reached. Try again later, or add a token to get a higher limit.'
                    : 'GitHub refused the download (HTTP '.$status.').',
                default => 'GitHub answered with HTTP '.$status.'.',
            });
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            throw new SyncException($this->tooLarge());
        }
        if (! str_starts_with($body, "PK\x03\x04") && ! str_starts_with($body, "PK\x05\x06")) {
            throw new SyncException('GitHub did not send a zip archive.');
        }
        if (file_put_contents($path, $body) === false) {
            throw new SyncException('Could not save the download.');
        }
    }

    private function tooLarge(): string
    {
        return sprintf('The repo archive is larger than %d MB.', intdiv(self::MAX_DOWNLOAD_BYTES, 1024 * 1024));
    }
}
