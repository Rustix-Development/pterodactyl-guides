import { Suspense } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { http, type ScreenContext } from '@pterodactyl/sdk';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { adminQuery, guideQuery, guidesQuery, type AdminOverview, type GuidePage, type ServerGuides } from './api';
import { ago, eggChoice, move, parseEggChoice, repoName, repoStatus } from './format';
import { useHasGuides } from './index';
import AdminScreen, { eggOptions } from './screens/AdminScreen';
import GuidesScreen from './screens/GuidesScreen';

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.restoreAllMocks();
    window.history.replaceState(null, '', '/');
    // @ts-expect-error -- jsdom has no matchMedia; some tests add one.
    delete window.matchMedia;
});

const UUID = 'aaaaaaaa-0000-4000-8000-000000000003';

function wide(matches: boolean) {
    window.matchMedia = ((query: string) => ({
        matches,
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
}

const rust: ServerGuides = {
    folder: 'rust',
    label: 'Rust',
    framework: { key: 'oxide', label: 'Oxide' },
    groups: [
        {
            label: 'General',
            framework: null,
            guides: [
                { slug: 'connecting', title: 'How to connect to your server', summary: 'Join from the F1 console.' },
                { slug: 'wiping', title: 'How to wipe your server', summary: null },
            ],
        },
        { label: 'Oxide', framework: 'oxide', guides: [{ slug: 'oxide-plugins', title: 'How to install Oxide plugins', summary: null }] },
    ],
};

const page = (slug: string, html: string): GuidePage => ({ slug, title: `Title of ${slug}`, summary: 'A summary.', group: 'General', html });

function renderGuides(data: ServerGuides, pages: GuidePage[] = []) {
    const server = createTestServer({ uuid: UUID });
    host = createExtensionTestHost({ extensionId: 'guides', prefix: 'rxgd', server });
    host.queryClient.setQueryData(guidesQuery(UUID).queryKey, data);
    for (const p of pages) host.queryClient.setQueryData(guideQuery(UUID, p.slug).queryKey, p);
    render(<GuidesScreen />, { wrapper: host.Wrapper });
}

test('times read naturally', () => {
    const now = Date.parse('2026-10-13T12:00:00Z');
    expect(ago(null, now)).toBe('never');
    expect(ago('2026-10-13T11:59:30Z', now)).toBe('just now');
    expect(ago('2026-10-13T11:59:00Z', now)).toBe('1 minute ago');
    expect(ago('2026-10-13T09:00:00Z', now)).toBe('3 hours ago');
    expect(ago('2026-10-11T12:00:00Z', now)).toBe('2 days ago');
    expect(repoStatus({ synced_at: null, attempted_at: null, error: null }, now)).toBe('Not synced yet');
    expect(repoStatus({ synced_at: '2026-10-13T11:00:00Z', attempted_at: '2026-10-13T11:00:00Z', error: null }, now)).toBe('Synced 1 hour ago');
    expect(repoStatus({ synced_at: '2026-10-12T12:00:00Z', attempted_at: '2026-10-13T11:58:00Z', error: 'x' }, now)).toBe(
        'Last sync failed 2 minutes ago; showing the copy from 1 day ago',
    );
    expect(repoStatus({ synced_at: null, attempted_at: '2026-10-13T11:58:00Z', error: 'x' }, now)).toBe('Sync failed 2 minutes ago');
});

test('repo names and egg choices', () => {
    expect(repoName('myhost/our-guides')).toBe('myhost/our-guides');
    expect(repoName('https://github.com/myhost/our-guides.git')).toBe('myhost/our-guides');
    expect(repoName('https://github.com/myhost/our-guides/tree/dev')).toBe('myhost/our-guides');
    expect(repoName('our-guides')).toBeNull();
    expect(repoName('-bad/x')).toBeNull();
    expect(eggChoice({ mode: 'pin', pinned: 'rust' })).toBe('pin:rust');
    expect(eggChoice({ mode: 'off', pinned: null })).toBe('off');
    expect(parseEggChoice('pin:rust')).toEqual({ mode: 'pin', folder: 'rust' });
    expect(parseEggChoice('auto')).toEqual({ mode: 'auto', folder: null });
    expect(move([1, 2, 3], 0, 1)).toEqual([2, 1, 3]);
    expect(move([1, 2, 3], 0, -1)).toEqual([1, 2, 3]);
    expect(eggOptions([{ folder: 'rust', label: 'Rust', has_match: true, repos: [], guides: 3, frameworks: [] }]).map((o) => o.value)).toEqual([
        'auto',
        'off',
        'pin:rust',
    ]);
});

test('guides are grouped General first, then the framework', async () => {
    renderGuides(rust);

    expect(await screen.findByText('Help for Rust with Oxide')).toBeTruthy();
    const headings = screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent);
    expect(headings).toEqual(['General', 'Oxide']);
    expect(screen.getByText('How to connect to your server')).toBeTruthy();
    expect(screen.getByText('Join from the F1 console.')).toBeTruthy();
    expect(screen.getByText('How to install Oxide plugins')).toBeTruthy();
    // On a phone the list comes first; nothing is open yet.
    expect(screen.queryByRole('article')).toBeNull();
});

test('opening a guide shows it with a way back on phones', async () => {
    renderGuides(rust, [page('wiping', '<p>Wipe <strong>now</strong>.</p><p><a href="#connecting" data-guide="connecting">connect</a></p>'), page('connecting', '<p>Connect to <code>play.example.com</code></p>')]);

    fireEvent.click(await screen.findByText('How to wipe your server'));
    expect(await screen.findByText('Title of wiping')).toBeTruthy();
    expect(screen.getByText('now').tagName).toBe('STRONG');
    expect(window.location.hash).toBe('#wiping');

    // A link to another guide opens it in the tab.
    fireEvent.click(screen.getByText('connect'));
    expect(await screen.findByText('play.example.com')).toBeTruthy();
    expect(window.location.hash).toBe('#connecting');

    fireEvent.click(screen.getByText('All guides'));
    expect(screen.queryByText('Title of connecting')).toBeNull();
    expect(screen.getByText('How to wipe your server')).toBeTruthy();
});

test('on wide screens the first guide is open next to the list', async () => {
    wide(true);
    renderGuides(rust, [page('connecting', '<p>Hello</p>')]);

    expect(await screen.findByText('Title of connecting')).toBeTruthy();
    expect(screen.getByText('How to wipe your server')).toBeTruthy();
    expect(screen.queryByText('All guides')).toBeNull();
    expect(screen.getByRole('button', { name: /How to connect/ }).getAttribute('aria-current')).toBe('page');
});

test('a guide named in the address opens directly', async () => {
    window.history.replaceState(null, '', '/server/x/guides#oxide-plugins');
    renderGuides(rust, [page('oxide-plugins', '<p>Plugins</p>')]);

    expect(await screen.findByText('Title of oxide-plugins')).toBeTruthy();
});

test('a friendly empty state when nothing matches', async () => {
    renderGuides({ folder: null, label: null, framework: null, groups: [] });

    expect(await screen.findByText('No guides for this server yet')).toBeTruthy();
});

function Probe({ context }: { context: ScreenContext }) {
    return <span>{useHasGuides(context) ? 'visible' : 'hidden'}</span>;
}

function renderProbe(permissions: string[] | undefined, data?: ServerGuides) {
    const server = createTestServer({ uuid: UUID, permissions, owner: permissions === undefined });
    host = createExtensionTestHost({ extensionId: 'guides', prefix: 'rxgd', server });
    if (data) host.queryClient.setQueryData(guidesQuery(UUID).queryKey, data);
    const get = vi.spyOn(http, 'get');
    render(
        <Suspense fallback='loading'>
            <Probe context={{ server, user: {} as ScreenContext['user'], config: {} }} />
        </Suspense>,
        { wrapper: host.Wrapper },
    );

    return get;
}

test('the tab shows only where there are guides', async () => {
    renderProbe(undefined, rust);
    expect(await screen.findByText('visible')).toBeTruthy();
    cleanup();
    host?.dispose();

    renderProbe(undefined, { folder: null, label: null, framework: null, groups: [] });
    expect(await screen.findByText('hidden')).toBeTruthy();
});

test('the tab stays hidden from subusers without the permission, without asking the panel', async () => {
    const get = renderProbe(['control.console']);

    expect(await screen.findByText('hidden')).toBeTruthy();
    expect(get).not.toHaveBeenCalled();
});

const overview: AdminOverview = {
    repos: [
        {
            id: 2,
            repo: 'myhost/our-guides',
            branch: 'main',
            url: 'https://github.com/myhost/our-guides/tree/main',
            community: false,
            has_token: true,
            revision: '0123abcd',
            synced_at: '2026-10-13T11:00:00Z',
            attempted_at: '2026-10-13T11:00:00Z',
            error: null,
            guides: 3,
            folders: 2,
            problems: [{ path: 'rust/broken.md', message: 'The front matter has no title, skipped.' }],
        },
        {
            id: 1,
            repo: 'Rustix-Development/pterodactyl-egg-guides',
            branch: 'main',
            url: 'https://github.com/Rustix-Development/pterodactyl-egg-guides/tree/main',
            community: true,
            has_token: false,
            revision: null,
            synced_at: null,
            attempted_at: '2026-10-13T11:00:00Z',
            error: 'Rustix-Development/pterodactyl-egg-guides or its branch "main" was not found.',
            guides: 0,
            folders: 0,
            problems: [],
        },
    ],
    folders: [{ folder: 'rust', label: 'Rust', has_match: true, repos: ['myhost/our-guides'], guides: 3, frameworks: ['Oxide', 'Carbon'] }],
    eggs: [
        { id: 14, name: 'Rust', tags: ['rust'], servers: 2, mode: 'auto', pinned: null, pin_missing: false, matched: { folder: 'rust', label: 'Rust', via: 'tag' } },
        { id: 3, name: 'Rust Modded 2x', tags: [], servers: 1, mode: 'pin', pinned: 'rust', pin_missing: false, matched: { folder: 'rust', label: 'Rust', via: 'pin' } },
        { id: 12, name: 'Teamspeak3 Server', tags: [], servers: 0, mode: 'off', pinned: null, pin_missing: false, matched: null },
    ],
    problems: [{ path: 'rust/x.md', message: 'The framework "primal" is not one of match.json\'s framework values, so this guide never shows.', repo: 'myhost/our-guides' }],
    interval: 60,
    intervals: [
        { value: 60, label: 'Every hour' },
        { value: 0, label: 'Only when I press Sync now' },
    ],
    source: 'https://api.github.com',
};

function renderAdmin(data: AdminOverview = overview) {
    host = createExtensionTestHost({ extensionId: 'guides', prefix: 'rxgd' });
    host.queryClient.setQueryData(adminQuery().queryKey, data);
    render(<AdminScreen />, { wrapper: host.Wrapper });
}

test('the admin page lists repos with the community one locked at the bottom', async () => {
    renderAdmin();

    expect(await screen.findByRole('link', { name: 'myhost/our-guides' })).toBeTruthy();
    expect(screen.getByText('Community, always last')).toBeTruthy();
    expect(screen.getByText(/private, token saved/)).toBeTruthy();
    expect(screen.getByText('Sync failed')).toBeTruthy();
    expect(screen.getByText(/was not found/)).toBeTruthy();
    // Only the host's repo can be removed or edited.
    expect(screen.getAllByText('Remove')).toHaveLength(1);
    expect(screen.getAllByText('Branch & token')).toHaveLength(1);
    expect(screen.getByLabelText('Move myhost/our-guides up')).toHaveProperty('disabled', true);
    // Problems from the repo and from merging.
    expect(screen.getAllByText(/no title, skipped/).length).toBeGreaterThan(0);
    expect(screen.getByText(/"primal"/)).toBeTruthy();
});

test('the admin page shows what every egg matched', async () => {
    renderAdmin();

    expect(await screen.findByText('Rust Modded 2x')).toBeTruthy();
    expect(screen.getByText('rust/ · by tag')).toBeTruthy();
    expect(screen.getByText('rust/ · pinned')).toBeTruthy();
    expect(screen.getByText('Turned off')).toBeTruthy();
    expect(screen.getByText('Oxide, Carbon')).toBeTruthy();
});

test('Sync now asks the panel and shows the new state', async () => {
    const post = vi.spyOn(http, 'post').mockResolvedValue({
        data: { ...overview, repos: overview.repos.map((repo) => ({ ...repo, error: null, synced_at: new Date().toISOString() })) },
    });
    renderAdmin();

    fireEvent.click(await screen.findByText('Sync now'));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/api/admin/extensions/guides/sync', {}, expect.anything()));
    await waitFor(() => expect(screen.queryByText('Sync failed')).toBeNull());
});

test('adding a repo checks the name before sending', async () => {
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: overview });
    renderAdmin();

    fireEvent.click(await screen.findByText('Add repo'));
    fireEvent.change(screen.getByLabelText('GitHub repo'), { target: { value: 'not a repo' } });
    fireEvent.click(screen.getAllByText('Add repo').at(-1)!);
    expect((await screen.findByRole('alert')).textContent).toMatch(/owner\/name/);
    expect(post).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('GitHub repo'), { target: { value: 'https://github.com/other/guides' } });
    fireEvent.change(screen.getByLabelText('Access token (private repos)'), { target: { value: 'github_pat_x' } });
    fireEvent.click(screen.getAllByText('Add repo').at(-1)!);
    await waitFor(() =>
        expect(post).toHaveBeenCalledWith('/api/admin/extensions/guides/repos', { repo: 'other/guides', branch: 'main', token: 'github_pat_x' }),
    );
});

test('reordering sends the new order', async () => {
    const put = vi.spyOn(http, 'put').mockResolvedValue({ data: overview });
    const second = { ...overview.repos[0]!, id: 5, repo: 'second/guides' };
    renderAdmin({ ...overview, repos: [overview.repos[0]!, second, overview.repos[1]!] });

    fireEvent.click(await screen.findByLabelText('Move second/guides up'));
    await waitFor(() => expect(put).toHaveBeenCalledWith('/api/admin/extensions/guides/repos/order', { ids: [5, 2] }));
});
