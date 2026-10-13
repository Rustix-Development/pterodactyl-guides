import type { EggRow, RepoRow } from './api';

/** "just now", "5 minutes ago", "3 hours ago", "2 days ago". */
export function ago(iso: string | null, now: number = Date.now()): string {
    if (!iso) return 'never';
    const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));
    if (seconds < 60) return 'just now';
    const units: [number, string][] = [
        [86_400, 'day'],
        [3_600, 'hour'],
        [60, 'minute'],
    ];
    for (const [size, unit] of units) {
        if (seconds >= size) {
            const count = Math.floor(seconds / size);

            return `${count} ${unit}${count === 1 ? '' : 's'} ago`;
        }
    }

    return 'just now';
}

/** One line on how a repo's last sync went. */
export function repoStatus(repo: Pick<RepoRow, 'synced_at' | 'attempted_at' | 'error'>, now: number = Date.now()): string {
    if (!repo.attempted_at) return 'Not synced yet';
    if (repo.error) {
        return repo.synced_at
            ? `Last sync failed ${ago(repo.attempted_at, now)}; showing the copy from ${ago(repo.synced_at, now)}`
            : `Sync failed ${ago(repo.attempted_at, now)}`;
    }

    return `Synced ${ago(repo.synced_at, now)}`;
}

export const VIA: Record<NonNullable<EggRow['matched']>['via'], string> = {
    pin: 'pinned',
    tag: 'by tag',
    egg: 'by egg name',
    image: 'by Docker image',
};

/** The value of an egg's dropdown: "auto", "off" or "pin:<folder>". */
export function eggChoice(egg: Pick<EggRow, 'mode' | 'pinned'>): string {
    return egg.mode === 'pin' ? `pin:${egg.pinned ?? ''}` : egg.mode;
}

export function parseEggChoice(value: string): { mode: 'auto' | 'off' | 'pin'; folder: string | null } {
    if (value.startsWith('pin:')) return { mode: 'pin', folder: value.slice(4) };

    return { mode: value === 'off' ? 'off' : 'auto', folder: null };
}

/** Move the item at `index` one place up (-1) or down (+1). */
export function move<T>(items: T[], index: number, direction: -1 | 1): T[] {
    const target = index + direction;
    if (target < 0 || target >= items.length) return items;
    const copy = [...items];
    [copy[index], copy[target]] = [copy[target]!, copy[index]!];

    return copy;
}

/** "owner/name" from what was typed, mirroring the panel's own check. */
export function repoName(input: string): string | null {
    let value = input.trim();
    value = value.replace(/^(?:https?:\/\/)?(?:www\.)?github\.com\//i, '').replace(/^git@github\.com:/i, '');
    value = value.replace(/(?:\.git)?\/*$/, '');
    const [owner, name] = value.split('/');
    if (!owner || !name) return null;
    if (!/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/.test(owner) || !/^[A-Za-z0-9._-]{1,100}$/.test(name) || name === '.' || name === '..') return null;

    return `${owner}/${name}`;
}
