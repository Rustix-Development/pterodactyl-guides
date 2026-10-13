<?php

// Checks for the sync, parsing, matching and rendering code. Runs against a Pterodactyl v2
// panel checkout for Laravel and CommonMark, without touching the panel's database:
//   PANEL=/var/www/pterodactyl php tests/guides.php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RustixDev\Guides\Models\EggRule;
use RustixDev\Guides\Models\Repo;
use RustixDev\Guides\Services\GuideCatalog;
use RustixDev\Guides\Services\GuideMatcher;
use RustixDev\Guides\Services\GuideRenderer;
use RustixDev\Guides\Services\GuideSync;
use RustixDev\Guides\Support\Placeholders;
use RustixDev\Guides\Support\RepoName;
use RustixDev\Guides\Support\ServerDomains;
use RustixDev\Guides\Sync\ArchiveExtractor;
use RustixDev\Guides\Sync\FrontMatter;
use RustixDev\Guides\Sync\GitHubSource;
use RustixDev\Guides\Sync\MatchFile;
use RustixDev\Guides\Sync\RepoParser;
use RustixDev\Guides\Sync\SyncException;

$panel = getenv('PANEL') ?: '/var/www/pterodactyl';
if (! is_file($panel.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set PANEL to a Pterodactyl v2 panel folder.\n");
    exit(2);
}

// This checkout's classes win over an installed copy of the extension.
$autoload = function (string $class): void {
    $prefix = 'RustixDev\\Guides\\';
    if (str_starts_with($class, $prefix) && is_file($file = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php')) {
        require $file;
    }
};
spl_autoload_register($autoload, true, true);
require $panel.'/vendor/autoload.php';
$app = require $panel.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
spl_autoload_unregister($autoload);
spl_autoload_register($autoload, true, true);
require __DIR__.'/support.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ').$label.($ok || $detail === '' ? '' : "\n     ".$detail)."\n";
    $failures += $ok ? 0 : 1;
}
function throws(callable $fn, ?string $contains = null): bool
{
    try {
        $fn();
    } catch (SyncException $exception) {
        return $contains === null || str_contains($exception->getMessage(), $contains);
    }

    return false;
}

$tmp = sys_get_temp_dir().'/guides-test-'.bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$app->useStoragePath($tmp.'/storage');
config(['cache.default' => 'array']);

// --- Repo names -------------------------------------------------------------------
check('repo: owner/name', RepoName::normalize('myhost/our-guides') === 'myhost/our-guides');
check('repo: github URL with tree path', RepoName::normalize('https://github.com/myhost/our-guides/tree/main') === 'myhost/our-guides');
check('repo: clone URL', RepoName::normalize('git@github.com:myhost/our-guides.git') === 'myhost/our-guides');
check('repo: refuses junk', RepoName::normalize('not a repo') === null && RepoName::normalize('a/..') === null && RepoName::normalize('https://evil.example/a/b') === null);
check('branch names', RepoName::validBranch('main') && RepoName::validBranch('release/v2') && ! RepoName::validBranch('../x') && ! RepoName::validBranch('a b'));

// --- Front matter and guides ------------------------------------------------------
$guide = RepoParser::guide("\u{FEFF}---\r\ntitle: \"How to: op\"\r\nframework: Oxide\r\norder: 20\r\nsummary: One line # comment\r\n---\r\n\r\nBody {{server.name}}\r\n");
check('guide: front matter with BOM and CRLF', $guide['title'] === 'How to: op' && $guide['framework'] === 'oxide' && $guide['sort'] === 20 && $guide['summary'] === 'One line' && $guide['body'] === 'Body {{server.name}}');
$guide = RepoParser::guide("---\ntitle: Plain\norder: soon\nframework: vanilla\n---\nText");
check('guide: defaults (order 100, vanilla, no summary)', $guide['sort'] === 100 && $guide['framework'] === null && $guide['summary'] === null);
check('guide: no front matter is refused', throws(fn () => RepoParser::guide('# Just text'), 'front matter'));
check('guide: no title is refused', throws(fn () => RepoParser::guide("---\norder: 1\n---\nx"), 'no title'));
check('guide: bad framework key is refused', throws(fn () => RepoParser::guide("---\ntitle: x\nframework: \"<b>\"\n---\n"), 'not a valid key'));
check('guide: invalid UTF-8 is refused', throws(fn () => RepoParser::guide("---\ntitle: x\n---\n\xff\xfe"), 'UTF-8'));
check('front matter: single quotes', FrontMatter::split("---\ntitle: 'It''s here'\n---\n")[0]['title'] === "It's here");

// --- match.json -------------------------------------------------------------------
$match = MatchFile::parse('{"name":"Rust","tags":["Rust"],"eggs":["Rust",5],"images":["ghcr.io/pterodactyl/games:rust"],"framework":{"variable":"FRAMEWORK","values":{"Oxide":"Oxide","carbon":"Carbon","vanilla":"Vanilla"}},"unknown":1}');
check('match: parsed and normalized', $match['tags'] === ['rust'] && $match['eggs'] === ['Rust'] && $match['framework'] === ['variable' => 'FRAMEWORK', 'values' => ['oxide' => 'Oxide', 'carbon' => 'Carbon']]);
$match = MatchFile::parse('{"name":"Minecraft","framework":{"values":{"paper":"Paper"}}}', $warning);
check('match: framework without a variable (tags pick it)', $match['framework'] === ['variable' => null, 'values' => ['paper' => 'Paper']] && $warning === null);
$match = MatchFile::parse('{"name":"X","framework":{"variable":"bad name"}}', $warning);
check('match: broken framework is dropped with a warning', $match['framework'] === null && $warning !== null);
check('match: invalid JSON', throws(fn () => MatchFile::parse('{"name":'), 'not valid JSON'));
check('match: name required', throws(fn () => MatchFile::parse('{"tags":["x"]}'), '"name"'));
check('match: must be an object', throws(fn () => MatchFile::parse('["x"]'), 'object'));

// --- Archive extraction -----------------------------------------------------------
$zipPath = $tmp.'/evil.zip';
$zip = new ZipArchive;
$zip->open($zipPath, ZipArchive::CREATE);
$png = file_get_contents(__DIR__.'/fixtures/host/rust/images/startup.png');
$entries = [
    'repo-abc1234/README.md' => '# readme',
    'repo-abc1234/rust/match.json' => '{"name":"Rust"}',
    'repo-abc1234/rust/admin.md' => "---\ntitle: Admin\n---\nx",
    'repo-abc1234/rust/images/shot.png' => $png,
    'repo-abc1234/rust/images/fake.png' => 'GIF? no',
    'repo-abc1234/rust/Admin.md' => 'bad name',
    'repo-abc1234/rust/big.md' => str_repeat('a', ArchiveExtractor::MAX_GUIDE_BYTES + 1),
    'repo-abc1234/_template/guide.md' => 'ignored',
    'repo-abc1234/.github/x.md' => 'ignored',
    'repo-abc1234/../escape.md' => 'escape',
    'repo-abc1234/rust/../../escape2.md' => 'escape',
    'repo-abc1234/rust/..%2f..%2fescape3.md' => 'escape',
    'repo-abc1234/rust\\..\\..\\escape4.md' => 'escape',
    'repo-abc1234/rust/sub/deep.md' => 'too deep',
    'repo-abc1234/rust/run.php' => '<?php echo 1;',
];
foreach ($entries as $name => $content) {
    $zip->addFromString($name, $content);
}
$zip->addFromString('repo-abc1234/rust/link.md', '/etc/passwd');
$zip->setExternalAttributesName('repo-abc1234/rust/link.md', ZipArchive::OPSYS_UNIX, (0120777 << 16));
$zip->close();

$target = $tmp.'/extract/copy';
$problems = (new ArchiveExtractor)->extract($zipPath, $target);
$written = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp.'/extract', FilesystemIterator::SKIP_DOTS)) as $file) {
    $written[] = substr($file->getPathname(), strlen($tmp.'/extract/'));
}
sort($written);
check('extract: only the files guides use', $written === ['copy/rust/admin.md', 'copy/rust/images/shot.png', 'copy/rust/match.json'], implode(', ', $written));
check('extract: nothing escaped the target', ! file_exists($tmp.'/escape.md') && ! file_exists($tmp.'/extract/escape.md') && ! file_exists($tmp.'/escape2.md'));
$paths = array_column($problems, 'path');
check('extract: reports too large, fake image, bad name and symlink', in_array('rust/big.md', $paths, true) && in_array('rust/images/fake.png', $paths, true) && in_array('rust/Admin.md', $paths, true) && in_array('rust/link.md', $paths, true), json_encode($problems));
check('extract: revision from the top folder', ArchiveExtractor::revision($zipPath) === 'abc1234');
file_put_contents($tmp.'/notzip.zip', 'nope');
check('extract: refuses a non-zip', throws(fn () => (new ArchiveExtractor)->extract($tmp.'/notzip.zip', $tmp.'/x'), 'zip'));

// --- Parsing a whole repo ---------------------------------------------------------
guides_zip_directory(__DIR__.'/fixtures/host', $tmp.'/host.zip', 'myhost-host-guides-1111111');
$problems = (new ArchiveExtractor)->extract($tmp.'/host.zip', $tmp.'/host');
$parsed = (new RepoParser)->parse($tmp.'/host');
$slugs = array_map(fn ($g) => $g['folder'].'/'.$g['slug'], $parsed['guides']);
sort($slugs);
check('parse: guides of the host fixture', $slugs === ['ark/taming', 'mygame/start', 'rust/admin', 'rust/support'], implode(', ', $slugs));
check('parse: folder with broken match.json keeps its guides, match null', array_key_exists('ark', $parsed['folders']) && $parsed['folders']['ark'] === null);
check('parse: BadFolder (upper case) is ignored', ! array_key_exists('BadFolder', $parsed['folders']));
$allProblems = array_column([...$problems, ...$parsed['problems']], 'path');
check('parse: broken files reported', in_array('ark/match.json', $allProblems, true) && in_array('rust/broken.md', $allProblems, true) && in_array('rust/Uppercase.md', $allProblems, true) && in_array('rust/images/fake.png', $allProblems, true), implode(', ', $allProblems));

guides_zip_directory(__DIR__.'/fixtures/community', $tmp.'/community.zip', 'Rustix-Development-pterodactyl-egg-guides-2222222');
(new ArchiveExtractor)->extract($tmp.'/community.zip', $tmp.'/community');
$community = (new RepoParser)->parse($tmp.'/community');
check('parse: community repo has rust and minecraft, no _template', isset($community['folders']['rust'], $community['folders']['minecraft']) && ! isset($community['folders']['_template']) && $community['problems'] === [], json_encode($community['problems']));

// --- Matching ---------------------------------------------------------------------
$folders = [
    'rust' => ['name' => 'Rust', 'tags' => ['rust'], 'eggs' => ['Rust'], 'images' => ['ghcr.io/pterodactyl/games:rust'], 'framework' => ['variable' => 'FRAMEWORK', 'values' => ['oxide' => 'Oxide', 'carbon' => 'Carbon']]],
    'minecraft' => ['name' => 'Minecraft', 'tags' => ['minecraft'], 'eggs' => ['Paper'], 'images' => [], 'framework' => ['variable' => null, 'values' => ['paper' => 'Paper']]],
    'mygame' => ['name' => 'My Game', 'tags' => [], 'eggs' => ['Custom'], 'images' => ['ghcr.io/myhost/mygame'], 'framework' => null],
    'nomatch' => null,
];
$egg = fn (string $name, array $tags = [], array $images = []) => ['name' => $name, 'tags' => $tags, 'images' => $images];
check('match: by tag (case-insensitive)', GuideMatcher::folderFor($folders, $egg('Whatever', ['RUST']), null) === ['folder' => 'rust', 'via' => 'tag']);
check('match: tags beat egg names', GuideMatcher::folderFor($folders, $egg('Rust', ['minecraft']), null)['folder'] === 'minecraft');
check('match: by egg name, exact', GuideMatcher::folderFor($folders, $egg('paper'), null) === ['folder' => 'minecraft', 'via' => 'egg'] && GuideMatcher::folderFor($folders, $egg('Paper 2'), null) === null);
check('match: by image prefix', GuideMatcher::folderFor($folders, $egg('X', [], ['ghcr.io/myhost/mygame:1.2']), null) === ['folder' => 'mygame', 'via' => 'image']);
check('match: pin wins', GuideMatcher::folderFor($folders, $egg('Rust', ['rust']), ['mode' => 'pin', 'folder' => 'mygame']) === ['folder' => 'mygame', 'via' => 'pin']);
check('match: pin to a folder without match.json', GuideMatcher::folderFor($folders, $egg('X'), ['mode' => 'pin', 'folder' => 'nomatch']) === ['folder' => 'nomatch', 'via' => 'pin']);
check('match: missing pin falls back', GuideMatcher::folderFor($folders, $egg('Rust'), ['mode' => 'pin', 'folder' => 'gone'])['folder'] === 'rust');
check('match: off', GuideMatcher::folderFor($folders, $egg('Rust', ['rust']), ['mode' => 'off', 'folder' => null]) === null);
check('match: nothing', GuideMatcher::folderFor($folders, $egg('Teamspeak3 Server', ['voice']), null) === null);

$rust = $folders['rust'];
check('framework: from the variable', GuideMatcher::frameworkFor($rust, [], ['FRAMEWORK' => 'Carbon']) === 'carbon');
check('framework: vanilla, empty or unknown is vanilla', GuideMatcher::frameworkFor($rust, [], ['FRAMEWORK' => 'vanilla']) === null && GuideMatcher::frameworkFor($rust, [], ['FRAMEWORK' => '']) === null && GuideMatcher::frameworkFor($rust, [], ['FRAMEWORK' => 'rustedit']) === null);
check('framework: the variable wins over a tag', GuideMatcher::frameworkFor($rust, ['oxide'], ['FRAMEWORK' => 'carbon']) === 'carbon' && GuideMatcher::frameworkFor($rust, ['oxide'], ['FRAMEWORK' => 'vanilla']) === null);
check('framework: a tag when the egg has no variable', GuideMatcher::frameworkFor($rust, ['oxide'], ['OTHER' => 'x']) === 'oxide' && GuideMatcher::frameworkFor($folders['minecraft'], ['minecraft', 'paper'], []) === 'paper');
check('framework: none declared', GuideMatcher::frameworkFor($folders['mygame'], ['oxide'], []) === null && GuideMatcher::frameworkFor(null, [], []) === null);

// --- Placeholders -----------------------------------------------------------------
$plain = Placeholders::build('My <Server>', '203.0.113.5', '28015', null);
check('placeholders: ip and port', $plain === ['server.name' => 'My <Server>', 'server.address' => '203.0.113.5:28015', 'server.ip' => '203.0.113.5', 'server.port' => '28015', 'server.domain' => '203.0.113.5:28015']);
$domain = Placeholders::build('S', '203.0.113.5', '28015', ['name' => 'play.example.com', 'srv' => false]);
check('placeholders: domain without SRV keeps the port', $domain['server.address'] === 'play.example.com:28015' && $domain['server.domain'] === 'play.example.com');
$srv = Placeholders::build('S', '203.0.113.5', '25565', ['name' => 'mc.example.com', 'srv' => true]);
check('placeholders: SRV domain needs no port', $srv['server.address'] === 'mc.example.com');
check('placeholders: IPv6 in brackets', Placeholders::build('S', '2001:db8::1', '27015', null)['server.address'] === '[2001:db8::1]:27015');
check('placeholders: unknown ones stay', Placeholders::fill('{{server.name}} {{ server.port }} {{server.secret}} {{user.email}}', $plain) === 'My <Server> 28015 {{server.secret}} {{user.email}}');

// --- Domains extension rows -------------------------------------------------------
$rows = [
    ['kind' => 'subdomain', 'name' => 'old.example.com', 'allocation_id' => 7, 'port' => 1, 'srv_service' => null, 'dns_records' => '{}', 'dns_status' => 'active', 'dns_error' => null],
    ['kind' => 'subdomain', 'name' => 'srv.example.com', 'allocation_id' => null, 'port' => 1, 'srv_service' => '_minecraft._tcp', 'dns_records' => '{"srv":"id"}', 'dns_status' => 'active', 'dns_error' => null],
    ['kind' => 'subdomain', 'name' => 'other.example.com', 'allocation_id' => 8, 'port' => 1, 'srv_service' => null, 'dns_records' => '{}', 'dns_status' => 'active', 'dns_error' => null],
    ['kind' => 'own', 'name' => 'pending.example.org', 'allocation_id' => 7, 'port' => 1, 'srv_service' => null, 'dns_records' => '{}', 'dns_status' => 'pending', 'dns_error' => null],
];
check('domains: SRV domain of the primary allocation wins', ServerDomains::pick($rows, 7) === ['name' => 'srv.example.com', 'srv' => true]);
check('domains: only live domains of the primary allocation', ServerDomains::pick([$rows[0], $rows[2], $rows[3]], 7) === ['name' => 'old.example.com', 'srv' => false] && ServerDomains::pick([$rows[3]], 7) === null);

// --- Rendering --------------------------------------------------------------------
$renderer = new GuideRenderer;
$images = fn (string $file) => $file === 'startup.png' ? '/extensions/guides/u/images/1/rust/startup.png' : null;
$html = $renderer->render(RepoParser::guide((string) file_get_contents(__DIR__.'/fixtures/host/rust/admin.md'))['body'], $plain, $images, ['wiping']);
check('render: raw HTML is escaped', ! str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));
check('render: placeholder values are escaped', str_contains($html, 'My &lt;Server&gt;') && ! str_contains($html, '<Server>'));
check('render: javascript: links are dropped', ! str_contains(strtolower($html), 'javascript:'), $html);
check('render: unsafe link becomes text', str_contains($html, '<p>Bad link and'), $html);
check('render: external links open in a new tab', preg_match('#<a (?=[^>]*href="https://example\.com/help")(?=[^>]*target="_blank")(?=[^>]*rel="noopener noreferrer")[^>]*>our site</a>#', $html) === 1, $html);
check('render: links to another guide stay in the panel', preg_match('#<a (?=[^>]*href="\#wiping")(?=[^>]*data-guide="wiping")[^>]*>wiping</a>#', $html) === 1, $html);
check('render: relative images come from the panel', str_contains($html, 'src="/extensions/guides/u/images/1/rust/startup.png"') && str_contains($html, 'loading="lazy"'));
$html = $renderer->render("![gone](images/missing.png) ![remote](https://tracker.example/pixel.gif) [file](../secrets.txt) [rel](other.md) ![x](images/../../etc.png)\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n`{{server.address}}` [join](steam://connect/{{server.address}})", $plain, $images, ['wiping']);
check('render: missing image becomes its text', str_contains($html, 'gone') && ! str_contains($html, 'missing.png'), $html);
check('render: remote images are not loaded', ! str_contains($html, '<img') && str_contains($html, 'href="https://tracker.example/pixel.gif"'), $html);
check('render: other relative links are plain text', ! str_contains($html, 'secrets.txt') && ! str_contains($html, 'other.md') && str_contains($html, 'file') && ! str_contains($html, 'etc.png'), $html);
check('render: tables scroll on their own', str_contains($html, '<div class="rxgd-table"><table>'));
check('render: placeholders in code and links', str_contains($html, '<code>203.0.113.5:28015</code>') && str_contains($html, 'href="steam://connect/203.0.113.5:28015"'), $html);
check('render: data URIs and vbscript are dropped', ! str_contains($renderer->render('[a](data:text/html;base64,PHNjcmlwdD4=) [b](vbscript:x) <img src=x onerror=alert(1)>', $plain, $images), 'href="data:') && ! str_contains($renderer->render('[b](VBScript:x)', $plain, $images), 'href'));

// --- Downloading from GitHub ------------------------------------------------------
$source = new GitHubSource;
// The panel's .env may point at a local stand-in; these checks start from the default.
putenv('GUIDES_GITHUB_API_URL');
unset($_ENV['GUIDES_GITHUB_API_URL'], $_SERVER['GUIDES_GITHUB_API_URL']);
check('github: archive URL', $source->archiveUrl('Rustix-Development/pterodactyl-egg-guides', 'release/v2') === 'https://api.github.com/repos/Rustix-Development/pterodactyl-egg-guides/zipball/release/v2');
putenv('GUIDES_GITHUB_API_URL=http://127.0.0.1:9/');
$_ENV['GUIDES_GITHUB_API_URL'] = $_SERVER['GUIDES_GITHUB_API_URL'] = 'http://127.0.0.1:9/';
check('github: API address can be changed for testing', $source->archiveUrl('a/b', 'main') === 'http://127.0.0.1:9/repos/a/b/zipball/main');
putenv('GUIDES_GITHUB_API_URL');
unset($_ENV['GUIDES_GITHUB_API_URL'], $_SERVER['GUIDES_GITHUB_API_URL']);

$sent = [];
Http::fake(function (ClientRequest $request) use (&$sent, $tmp) {
    $sent[] = $request;
    $url = $request->url();

    return match (true) {
        str_contains($url, '/missing/') => Http::response('{"message":"Not Found"}', 404),
        str_contains($url, '/badtoken/') => Http::response('{"message":"Bad credentials"}', 401),
        str_contains($url, '/limited/') => Http::response('{}', 403, ['X-RateLimit-Remaining' => '0']),
        str_contains($url, '/html/') => Http::response('<html>', 200),
        str_contains($url, '/huge/') => Http::response("PK\x03\x04".str_repeat('x', GitHubSource::MAX_DOWNLOAD_BYTES), 200),
        str_contains($url, '/Rustix-Development/pterodactyl-egg-guides/') => Http::response(file_get_contents($tmp.'/community.zip'), 200),
        str_contains($url, '/myhost/host-guides/') => Http::response(file_get_contents($tmp.'/host.zip'), 200),
        default => Http::response('', 500),
    };
});
$file = $tmp.'/download.zip';
$source->download('myhost/host-guides', 'main', 'secret-token', $file);
check('github: download saved', is_file($file) && filesize($file) === filesize($tmp.'/host.zip'));
$last = end($sent);
check('github: token sent as a bearer token', $last->header('Authorization') === ['Bearer secret-token'] && $last->header('Accept') === ['application/vnd.github+json']);
$source->download('myhost/host-guides', 'main', null, $file);
check('github: no token, no Authorization header', end($sent)->header('Authorization') === []);
check('github: 404 explains a private repo needs a token', throws(fn () => $source->download('a/missing', 'main', null, $file), 'add a token'));
check('github: 401', throws(fn () => $source->download('a/badtoken', 'main', 'x', $file), 'refused the token'));
check('github: rate limit', throws(fn () => $source->download('a/limited', 'main', null, $file), 'rate limit'));
check('github: not a zip', throws(fn () => $source->download('a/html', 'main', null, $file), 'zip'));
check('github: size limit', throws(fn () => $source->download('a/huge', 'main', null, $file), 'larger than'));

// --- Sync and merge (on a throwaway SQLite database) ------------------------------
config(['database.connections.guides_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
DB::setDefaultConnection('guides_test');
Schema::create('eggs', fn ($table) => $table->increments('id'));
(require __DIR__.'/../database/migrations/2026_10_13_000901_create_guides_tables.php')->up();

$community = Repo::query()->where('community', true)->first();
check('migration: community repo is there', $community?->repo === Repo::COMMUNITY && $community->branch === 'main');
$host = Repo::query()->create(['repo' => 'myhost/host-guides', 'branch' => 'main', 'token' => 'secret-token', 'position' => 1]);
check('repo token stored encrypted', DB::table('ext_guides_repos')->where('id', $host->id)->value('token') !== 'secret-token' && $host->fresh()->token === 'secret-token');

$sync = app(GuideSync::class);
$lines = $sync->syncAll();
check('sync: both repos', count($lines) === 2 && Repo::query()->whereNull('error')->count() === 2, implode(' | ', $lines));
$host->refresh();
$community->refresh();
check('sync: copy stored under storage', GuideSync::directory($host) !== null && is_file(GuideSync::directory($host).'/rust/images/startup.png') && str_starts_with(GuideSync::directory($host), $tmp.'/storage/app/ext-guides/'));
check('sync: problems kept for the admin page', in_array('rust/broken.md', array_column($host->problems ?? [], 'path'), true));
check('sync: revision recorded', $community->revision === '2222222');
check('sync: not due again right away', ! $sync->isDue($host));

$catalog = new GuideCatalog;
$rustGuides = array_map(fn ($g) => $g->slug.'@'.$g->repo_id, $catalog->guides('rust'));
check('merge: host guide replaces the community one with the same slug', in_array('admin@'.$host->id, $rustGuides, true) && ! in_array('admin@'.$community->id, $rustGuides, true), implode(', ', $rustGuides));
check('merge: guides from both repos', in_array('support@'.$host->id, $rustGuides, true) && in_array('wiping@'.$community->id, $rustGuides, true));
check('merge: sorted by order then title', $catalog->guides('rust')[0]->slug === 'support');
check('merge: community match.json used when the host has none', $catalog->matches()['rust']['name'] === 'Rust');
check('merge: folders of every repo', array_keys($catalog->folders()) !== [] && isset($catalog->folders()['mygame'], $catalog->folders()['minecraft'], $catalog->folders()['ark']));
check('merge: higher repo first in folder order', array_search('mygame', array_keys($catalog->folders()), true) < array_search('rust', array_keys($catalog->folders()), true));

// The host repo moving below would let the community guide win; here the community repo
// is fixed last, so moving positions among admin repos never changes that.
$broken = Repo::query()->create(['repo' => 'a/missing', 'branch' => 'main', 'position' => 0]);
$before = $catalog->guides('rust');
$sync->syncRepo($broken);
check('sync: a failing repo records its error', str_contains((string) $broken->fresh()->error, 'not found'));
$sync->syncRepo($host->fresh()->forceFill(['repo' => 'a/html']));
check('sync: a failing re-sync keeps the previous copy', (new GuideCatalog)->guides('rust') == $before && is_dir((string) GuideSync::directory($host->fresh())));

$lock = Cache::lock(GuideSync::LOCK, 60);
$lock->get();
check('sync: only one at a time', throws(fn () => $sync->syncAll(), 'already running'));
$lock->release();

EggRule::query()->create(['egg_id' => 1, 'mode' => 'off']);
check('egg rules are read', GuideCatalog::ruleFor(1) === ['mode' => 'off', 'folder' => null] && GuideCatalog::ruleFor(2) === null);

File::deleteDirectory($tmp);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
