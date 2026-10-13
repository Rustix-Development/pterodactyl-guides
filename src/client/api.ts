import { queryOptions } from '@tanstack/react-query';
import { http } from '@pterodactyl/sdk';

export interface GuideSummary {
    slug: string;
    title: string;
    summary: string | null;
}

export interface GuideGroup {
    label: string;
    /** null for the "General" (vanilla) group. */
    framework: string | null;
    guides: GuideSummary[];
}

export interface ServerGuides {
    folder: string | null;
    label: string | null;
    framework: { key: string; label: string } | null;
    groups: GuideGroup[];
}

export interface GuidePage {
    slug: string;
    title: string;
    summary: string | null;
    group: string;
    /** Rendered on the panel: raw HTML in the guide is escaped, unsafe links are removed. */
    html: string;
}

export interface Problem {
    path: string;
    message: string;
    repo?: string | null;
}

export interface RepoRow {
    id: number;
    repo: string;
    branch: string;
    url: string;
    community: boolean;
    has_token: boolean;
    revision: string | null;
    synced_at: string | null;
    attempted_at: string | null;
    error: string | null;
    guides: number;
    folders: number;
    problems: Problem[];
}

export interface FolderRow {
    folder: string;
    label: string;
    has_match: boolean;
    repos: string[];
    guides: number;
    frameworks: string[];
}

export type EggMode = 'auto' | 'pin' | 'off';

export interface EggRow {
    id: number;
    name: string;
    tags: string[];
    servers: number;
    mode: EggMode;
    pinned: string | null;
    pin_missing: boolean;
    matched: { folder: string; label: string; via: 'pin' | 'tag' | 'egg' | 'image' } | null;
}

export interface AdminOverview {
    repos: RepoRow[];
    folders: FolderRow[];
    eggs: EggRow[];
    problems: Problem[];
    interval: number;
    intervals: { value: number; label: string }[];
    source: string;
    result?: string[];
}

export const EMPTY: ServerGuides = { folder: null, label: null, framework: null, groups: [] };

const base = (uuid: string) => `/api/client/servers/${uuid}/extensions/guides`;
const admin = '/api/admin/extensions/guides';

export const guidesQuery = (uuid: string) =>
    queryOptions({
        queryKey: ['guides', uuid],
        queryFn: async () => (await http.get<ServerGuides>(`${base(uuid)}/guides`)).data,
        staleTime: 60_000,
    });

export const guideQuery = (uuid: string, slug: string) =>
    queryOptions({
        queryKey: ['guides', uuid, 'guide', slug],
        queryFn: async () => (await http.get<GuidePage>(`${base(uuid)}/guides/${slug}`)).data,
        staleTime: 60_000,
    });

export const adminQuery = () =>
    queryOptions({
        queryKey: ['guides', 'admin'],
        queryFn: async () => (await http.get<AdminOverview>(`${admin}/overview`)).data,
    });

export const adminApi = {
    addRepo: async (input: { repo: string; branch: string; token: string }) => (await http.post<AdminOverview>(`${admin}/repos`, input)).data,
    updateRepo: async (id: number, input: { branch?: string; token?: string | null }) =>
        (await http.patch<AdminOverview>(`${admin}/repos/${id}`, input)).data,
    removeRepo: async (id: number) => (await http.delete<AdminOverview>(`${admin}/repos/${id}`)).data,
    order: async (ids: number[]) => (await http.put<AdminOverview>(`${admin}/repos/order`, { ids })).data,
    interval: async (interval: number) => (await http.put<AdminOverview>(`${admin}/interval`, { interval })).data,
    sync: async () => (await http.post<AdminOverview>(`${admin}/sync`, {}, { timeout: 600_000 })).data,
    egg: async (id: number, mode: EggMode, folder: string | null) => (await http.put<AdminOverview>(`${admin}/eggs/${id}`, { mode, folder })).data,
};
