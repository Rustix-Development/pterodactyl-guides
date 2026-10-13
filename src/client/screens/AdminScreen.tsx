import { useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowUp, ExternalLink, Lock } from 'lucide-react';
import { Alert, Button, Dialog, httpErrorToHuman, Icon, Input, Label, PageContentBlock, Select, Spinner, Table, toast } from '@pterodactyl/sdk';
import { adminApi, adminQuery, type AdminOverview, type EggRow, type FolderRow, type Problem, type RepoRow } from '../api';
import { eggChoice, move, parseEggChoice, repoName, repoStatus, VIA } from '../format';

function Card({ title, description, action, children }: { title: string; description?: ReactNode; action?: ReactNode; children: ReactNode }) {
    return (
        <section className='rxgd:mb-6 rxgd:min-w-0'>
            <div className='rxgd:mb-3 rxgd:flex rxgd:flex-wrap rxgd:items-end rxgd:justify-between rxgd:gap-3'>
                <div className='rxgd:min-w-0'>
                    <h2 className='rxgd:m-0 rxgd:text-lg rxgd:font-medium'>{title}</h2>
                    {description && <p className='rxgd:m-0 rxgd:text-sm rxgd:text-muted-foreground'>{description}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string | null; children: ReactNode }) {
    return (
        <div className='rxgd:min-w-0'>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? (
                <p role='alert' className='rxgd:mt-1 rxgd:text-xs rxgd:text-destructive'>
                    {error}
                </p>
            ) : (
                hint && <p className='rxgd:mt-1 rxgd:text-xs rxgd:text-muted-foreground'>{hint}</p>
            )}
        </div>
    );
}

function Tag({ tone = 'muted', children }: { tone?: 'muted' | 'good' | 'bad' | 'warn'; children: ReactNode }) {
    const tones = {
        muted: 'rxgd:bg-muted rxgd:text-muted-foreground',
        good: 'rxgd:bg-success rxgd:text-success-foreground',
        bad: 'rxgd:bg-destructive rxgd:text-destructive-foreground',
        warn: 'rxgd:bg-warning rxgd:text-warning-foreground',
    };

    return <span className={`rxgd:inline-block rxgd:whitespace-nowrap rxgd:rounded rxgd:px-2 rxgd:py-0.5 rxgd:text-xs rxgd:font-medium ${tones[tone]}`}>{children}</span>;
}

function RepoForm({
    repo,
    pending,
    onCancel,
    onSubmit,
}: {
    repo: RepoRow | null;
    pending: boolean;
    onCancel: () => void;
    onSubmit: (input: { repo: string; branch: string; token: string | null }) => void;
}) {
    const [name, setName] = useState(repo?.repo ?? '');
    const [branch, setBranch] = useState(repo?.branch ?? 'main');
    const [token, setToken] = useState('');
    const [clearToken, setClearToken] = useState(false);
    const [submitted, setSubmitted] = useState(false);
    const normalized = repo ? repo.repo : repoName(name);
    const nameError = repo ? null : normalized ? null : 'Enter owner/name, like myhost/our-guides, or paste the GitHub URL.';
    const branchError = /^[A-Za-z0-9._/-]{1,100}$/.test(branch.trim()) && !branch.includes('..') ? null : 'Enter a branch name.';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSubmitted(true);
        if (nameError || branchError || !normalized) return;
        onSubmit({ repo: normalized, branch: branch.trim(), token: clearToken ? '' : token.trim() === '' ? null : token.trim() });
    };

    return (
        <form onSubmit={submit} noValidate className='rxgd:flex rxgd:flex-col rxgd:gap-4'>
            {!repo && (
                <Field id='rxgd-repo' label='GitHub repo' error={submitted ? nameError : null} hint='The repo must follow the guide format: one folder per game with a match.json.'>
                    <Input id='rxgd-repo' value={name} autoComplete='off' placeholder='myhost/our-guides' onChange={(e) => setName(e.target.value)} />
                </Field>
            )}
            <Field id='rxgd-branch' label='Branch' error={submitted ? branchError : null}>
                <Input id='rxgd-branch' value={branch} autoComplete='off' onChange={(e) => setBranch(e.target.value)} />
            </Field>
            <Field
                id='rxgd-token'
                label='Access token (private repos)'
                hint={
                    repo?.has_token
                        ? 'A token is saved. Leave this empty to keep it.'
                        : 'A fine-grained GitHub token with read access to the repo’s contents. Stored encrypted, never shown again.'
                }
            >
                <Input
                    id='rxgd-token'
                    type='password'
                    value={token}
                    autoComplete='off'
                    disabled={clearToken}
                    placeholder={repo?.has_token ? '••••••••' : 'github_pat_…'}
                    onChange={(e) => setToken(e.target.value)}
                />
            </Field>
            {repo?.has_token && (
                <label className='rxgd:flex rxgd:items-center rxgd:gap-2 rxgd:text-sm'>
                    <input type='checkbox' checked={clearToken} onChange={(e) => setClearToken(e.target.checked)} />
                    Remove the saved token
                </label>
            )}
            <Dialog.Footer>
                <Button isSecondary onClick={onCancel} disabled={pending}>
                    Cancel
                </Button>
                <Button type='submit' isLoading={pending}>
                    {repo ? 'Save' : 'Add repo'}
                </Button>
            </Dialog.Footer>
        </form>
    );
}

function ProblemList({ problems }: { problems: Problem[] }) {
    return (
        <ul className='rxgd:m-0 rxgd:flex rxgd:list-none rxgd:flex-col rxgd:gap-1.5 rxgd:p-0 rxgd:text-sm'>
            {problems.map((problem, index) => (
                <li key={index} className='rxgd:min-w-0 rxgd:wrap-anywhere'>
                    <span className='rxgd:font-mono rxgd:text-xs'>
                        {problem.repo ? `${problem.repo}: ` : ''}
                        {problem.path}
                    </span>
                    <span className='rxgd:block rxgd:text-muted-foreground'>{problem.message}</span>
                </li>
            ))}
        </ul>
    );
}

function RepoItem({
    repo,
    index,
    count,
    busy,
    onMove,
    onEdit,
    onRemove,
}: {
    repo: RepoRow;
    index: number;
    count: number;
    busy: boolean;
    onMove: (direction: -1 | 1) => void;
    onEdit: () => void;
    onRemove: () => void;
}) {
    const failed = repo.error !== null;

    return (
        <li className='rxgd:flex rxgd:min-w-0 rxgd:flex-col rxgd:gap-2 rxgd:rounded-md rxgd:border rxgd:border-border rxgd:bg-card rxgd:p-3 rxgd:text-card-foreground rxgd:sm:p-4'>
            <div className='rxgd:flex rxgd:flex-wrap rxgd:items-start rxgd:justify-between rxgd:gap-2'>
                <div className='rxgd:flex rxgd:min-w-0 rxgd:items-start rxgd:gap-3'>
                    <span className='rxgd:mt-0.5 rxgd:flex rxgd:size-6 rxgd:shrink-0 rxgd:items-center rxgd:justify-center rxgd:rounded-full rxgd:bg-muted rxgd:text-xs rxgd:font-semibold rxgd:text-muted-foreground'>
                        {index + 1}
                    </span>
                    <div className='rxgd:min-w-0'>
                        <a
                            href={repo.url}
                            target='_blank'
                            rel='noopener noreferrer'
                            className='rxgd:inline-flex rxgd:max-w-full rxgd:items-center rxgd:gap-1 rxgd:break-all rxgd:font-mono rxgd:text-sm rxgd:font-medium rxgd:text-foreground rxgd:hover:underline'
                        >
                            {repo.repo}
                            <Icon icon={ExternalLink} className='rxgd:size-3.5 rxgd:shrink-0 rxgd:text-muted-foreground' />
                        </a>
                        <p className='rxgd:m-0 rxgd:text-xs rxgd:text-muted-foreground'>
                            Branch {repo.branch}
                            {repo.has_token ? ' · private, token saved' : ''}
                            {repo.revision ? ` · ${repo.revision.slice(0, 7)}` : ''}
                            {repo.attempted_at && !failed ? ` · ${repo.folders} games, ${repo.guides} guides` : ''}
                        </p>
                    </div>
                </div>
                <div className='rxgd:flex rxgd:flex-wrap rxgd:items-center rxgd:gap-1'>
                    {repo.community ? <Tag>Community, always last</Tag> : null}
                    <Tag tone={failed ? 'bad' : repo.synced_at ? 'good' : 'warn'}>{failed ? 'Sync failed' : repo.synced_at ? 'Synced' : 'Waiting'}</Tag>
                </div>
            </div>

            <p className='rxgd:m-0 rxgd:text-sm rxgd:text-muted-foreground'>{repoStatus(repo)}</p>
            {failed && (
                <Alert type='danger'>
                    <span className='rxgd:wrap-anywhere'>{repo.error}</span>
                </Alert>
            )}
            {repo.problems.length > 0 && (
                <details className='rxgd:min-w-0'>
                    <summary className='rxgd:cursor-pointer rxgd:text-sm rxgd:font-medium rxgd:text-warning'>
                        {repo.problems.length} {repo.problems.length === 1 ? 'file was' : 'files were'} skipped
                    </summary>
                    <div className='rxgd:mt-2'>
                        <ProblemList problems={repo.problems} />
                    </div>
                </details>
            )}

            {repo.community ? (
                <p className='rxgd:m-0 rxgd:inline-flex rxgd:items-center rxgd:gap-1 rxgd:text-xs rxgd:text-muted-foreground'>
                    <Icon icon={Lock} className='rxgd:size-3' /> Included on every panel. Your repos above it win when both have the same guide.
                </p>
            ) : (
                <div className='rxgd:flex rxgd:flex-wrap rxgd:gap-2'>
                    <Button size='small' isSecondary disabled={busy || index === 0} onClick={() => onMove(-1)} aria-label={`Move ${repo.repo} up`}>
                        <Icon icon={ArrowUp} />
                    </Button>
                    <Button size='small' isSecondary disabled={busy || index >= count - 1} onClick={() => onMove(1)} aria-label={`Move ${repo.repo} down`}>
                        <Icon icon={ArrowDown} />
                    </Button>
                    <Button size='small' isSecondary disabled={busy} onClick={onEdit}>
                        Branch &amp; token
                    </Button>
                    <Button.Danger size='small' className='rxgd:ml-auto' disabled={busy} onClick={onRemove}>
                        Remove
                    </Button.Danger>
                </div>
            )}
        </li>
    );
}

export function eggOptions(folders: FolderRow[]) {
    return [
        { value: 'auto', label: 'Automatic' },
        { value: 'off', label: 'No guides' },
        ...folders.map((folder) => ({ value: `pin:${folder.folder}`, label: `Always ${folder.label} (${folder.folder}/)` })),
    ];
}

function EggTable({ data, pending, onChange }: { data: AdminOverview; pending: number | null; onChange: (egg: EggRow, value: string) => void }) {
    const options = eggOptions(data.folders);

    return (
        <Table
            rows={data.eggs}
            keyOf={(egg) => String(egg.id)}
            emptyState='There are no eggs on this panel.'
            columns={[
                {
                    id: 'egg',
                    label: 'Egg',
                    cell: (egg) => (
                        <span className='rxgd:block rxgd:min-w-40 rxgd:max-w-xs'>
                            <span className='rxgd:block rxgd:font-medium'>{egg.name}</span>
                            <span className='rxgd:block rxgd:text-xs rxgd:text-muted-foreground'>
                                {egg.servers} {egg.servers === 1 ? 'server' : 'servers'}
                                {egg.tags.length > 0 ? ` · tags: ${egg.tags.join(', ')}` : ''}
                            </span>
                        </span>
                    ),
                },
                {
                    id: 'matched',
                    label: 'Guides',
                    cell: (egg) => (
                        <span className='rxgd:block rxgd:min-w-36'>
                            {egg.mode === 'off' ? (
                                <span className='rxgd:text-muted-foreground'>Turned off</span>
                            ) : egg.matched ? (
                                <>
                                    <span className='rxgd:block'>{egg.matched.label}</span>
                                    <span className='rxgd:block rxgd:text-xs rxgd:text-muted-foreground'>
                                        {egg.matched.folder}/ · {VIA[egg.matched.via]}
                                    </span>
                                </>
                            ) : (
                                <span className='rxgd:text-muted-foreground'>No match</span>
                            )}
                            {egg.pin_missing && (
                                <span className='rxgd:block rxgd:text-xs rxgd:text-destructive'>Pinned folder {egg.pinned}/ no longer exists</span>
                            )}
                        </span>
                    ),
                },
                {
                    id: 'choice',
                    label: 'Use',
                    cell: (egg) => (
                        <div className='rxgd:min-w-52 rxgd:max-w-72'>
                            <Select
                                id={`rxgd-egg-${egg.id}`}
                                aria-describedby={undefined}
                                value={eggChoice(egg)}
                                disabled={pending === egg.id}
                                options={
                                    egg.pin_missing ? [...options, { value: eggChoice(egg), label: `Always ${egg.pinned}/ (missing)` }] : options
                                }
                                onChange={(value) => onChange(egg, String(value))}
                            />
                        </div>
                    ),
                },
            ]}
        />
    );
}

export default function AdminScreen() {
    const client = useQueryClient();
    const { data, isLoading, error } = useQuery(adminQuery());
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<RepoRow | null>(null);
    const [removing, setRemoving] = useState<RepoRow | null>(null);
    const [eggPending, setEggPending] = useState<number | null>(null);

    const store = (overview: AdminOverview) => client.setQueryData(adminQuery().queryKey, overview);
    const onError = (err: unknown) => toast.error(httpErrorToHuman(err));

    const add = useMutation({
        mutationFn: adminApi.addRepo,
        onSuccess: (overview) => {
            store(overview);
            setAdding(false);
            toast.success('Repo added. Press Sync now to download it, or wait for the next sync.');
        },
        onError,
    });
    const edit = useMutation({
        mutationFn: ({ id, input }: { id: number; input: { branch?: string; token?: string | null } }) => adminApi.updateRepo(id, input),
        onSuccess: (overview) => {
            store(overview);
            setEditing(null);
            toast.success('Saved. The repo is downloaded again on the next sync.');
        },
        onError,
    });
    const remove = useMutation({
        mutationFn: (id: number) => adminApi.removeRepo(id),
        onSuccess: (overview) => {
            store(overview);
            setRemoving(null);
        },
        onError,
    });
    const order = useMutation({ mutationFn: adminApi.order, onSuccess: store, onError });
    const interval = useMutation({
        mutationFn: adminApi.interval,
        onSuccess: (overview) => {
            store(overview);
            toast.success('Sync schedule saved.');
        },
        onError,
    });
    const sync = useMutation({
        mutationFn: adminApi.sync,
        onSuccess: (overview) => {
            store(overview);
            const failed = overview.repos.filter((repo) => repo.error).length;
            if (failed > 0) toast.error(`${failed} ${failed === 1 ? 'repo' : 'repos'} could not be synced. See below.`);
            else toast.success('Guides synced.');
        },
        onError,
    });
    const egg = useMutation({
        mutationFn: ({ id, value }: { id: number; value: string }) => {
            const choice = parseEggChoice(value);

            return adminApi.egg(id, choice.mode, choice.folder);
        },
        onMutate: ({ id }) => setEggPending(id),
        onSettled: () => setEggPending(null),
        onSuccess: store,
        onError,
    });

    if (isLoading || !data) {
        return <PageContentBlock title='Guides'>{error ? <Alert type='danger'>{httpErrorToHuman(error)}</Alert> : <Spinner centered />}</PageContentBlock>;
    }

    const own = data.repos.filter((repo) => !repo.community);
    const lastSync = data.repos.map((repo) => repo.synced_at).filter((value): value is string => value !== null).sort()[0] ?? null;
    const problems = [...data.problems, ...data.repos.flatMap((repo) => repo.problems.map((problem) => ({ ...problem, repo: repo.repo })))];
    const reorder = (index: number, direction: -1 | 1) => order.mutate(move(own, index, direction).map((repo) => repo.id));

    return (
        <PageContentBlock title='Guides'>
            <section className='rxgd:mb-6 rxgd:flex rxgd:flex-col rxgd:gap-4 rxgd:rounded-md rxgd:border rxgd:border-border rxgd:bg-card rxgd:p-4 rxgd:text-card-foreground rxgd:sm:flex-row rxgd:sm:items-end rxgd:sm:justify-between'>
                <div className='rxgd:min-w-0'>
                    <h2 className='rxgd:m-0 rxgd:text-lg rxgd:font-medium'>Sync</h2>
                    <p className='rxgd:m-0 rxgd:text-sm rxgd:text-muted-foreground'>
                        The panel keeps its own copy of every repo, so the Guides tab never waits on GitHub.{' '}
                        {lastSync ? `Oldest copy: ${repoStatus({ synced_at: lastSync, attempted_at: lastSync, error: null }).toLowerCase()}.` : 'Nothing synced yet.'}
                    </p>
                    {data.source !== 'https://api.github.com' && (
                        <p className='rxgd:m-0 rxgd:mt-1 rxgd:break-all rxgd:text-xs rxgd:text-muted-foreground'>Downloading from {data.source}</p>
                    )}
                </div>
                <div className='rxgd:flex rxgd:flex-wrap rxgd:items-end rxgd:gap-2'>
                    <div className='rxgd:w-60 rxgd:max-w-full'>
                        <Label htmlFor='rxgd-interval'>Sync automatically</Label>
                        <Select
                            id='rxgd-interval'
                            value={data.interval}
                            disabled={interval.isPending}
                            options={data.intervals}
                            onChange={(value) => interval.mutate(Number(value))}
                        />
                    </div>
                    <Button isLoading={sync.isPending} onClick={() => sync.mutate()}>
                        Sync now
                    </Button>
                </div>
            </section>

            <Card
                title='Guide repos'
                description='Read from top to bottom. When two repos have the same game folder their guides are merged; a guide or match.json higher up wins.'
                action={<Button onClick={() => setAdding(true)}>Add repo</Button>}
            >
                <ul className='rxgd:m-0 rxgd:flex rxgd:list-none rxgd:flex-col rxgd:gap-3 rxgd:p-0'>
                    {data.repos.map((repo) => {
                        const index = repo.community ? own.length : own.indexOf(repo);

                        return (
                            <RepoItem
                                key={repo.id}
                                repo={repo}
                                index={index}
                                count={own.length}
                                busy={order.isPending || sync.isPending}
                                onMove={(direction) => reorder(index, direction)}
                                onEdit={() => setEditing(repo)}
                                onRemove={() => setRemoving(repo)}
                            />
                        );
                    })}
                </ul>
            </Card>

            <Card
                title='Eggs'
                description='Which guides each egg gets. Automatic follows the repos’ match.json files (egg tags, then egg name, then Docker image); pin a folder for custom eggs, or turn guides off.'
            >
                <EggTable data={data} pending={eggPending} onChange={(row, value) => egg.mutate({ id: row.id, value })} />
            </Card>

            {data.folders.length > 0 && (
                <Card title='Games' description='Every game folder in the synced repos.'>
                    <Table
                        rows={data.folders}
                        keyOf={(folder) => folder.folder}
                        emptyState='No games yet.'
                        columns={[
                            {
                                id: 'name',
                                label: 'Game',
                                cell: (folder) => (
                                    <span className='rxgd:block rxgd:min-w-32'>
                                        <span className='rxgd:block rxgd:font-medium'>{folder.label}</span>
                                        <span className='rxgd:block rxgd:font-mono rxgd:text-xs rxgd:text-muted-foreground'>{folder.folder}/</span>
                                    </span>
                                ),
                            },
                            { id: 'guides', label: 'Guides', cell: (folder) => String(folder.guides) },
                            { id: 'frameworks', label: 'Frameworks', cell: (folder) => (folder.frameworks.length > 0 ? folder.frameworks.join(', ') : '—') },
                            {
                                id: 'repos',
                                label: 'From',
                                cell: (folder) => <span className='rxgd:block rxgd:min-w-40 rxgd:break-all rxgd:text-xs'>{folder.repos.join(', ')}</span>,
                            },
                        ]}
                    />
                </Card>
            )}

            {problems.length > 0 && (
                <Card title='Problems' description='These files were skipped. Everything else still works; fix them in the repo and sync again.'>
                    <div className='rxgd:rounded-md rxgd:border rxgd:border-border rxgd:bg-card rxgd:p-4'>
                        <ProblemList problems={problems} />
                    </div>
                </Card>
            )}

            <Dialog open={adding} onClose={() => setAdding(false)} title='Add a guide repo'>
                {adding && (
                    <RepoForm
                        repo={null}
                        pending={add.isPending}
                        onCancel={() => setAdding(false)}
                        onSubmit={(input) => add.mutate({ repo: input.repo, branch: input.branch, token: input.token ?? '' })}
                    />
                )}
            </Dialog>
            <Dialog open={editing !== null} onClose={() => setEditing(null)} title={editing?.repo ?? ''}>
                {editing && (
                    <RepoForm
                        repo={editing}
                        pending={edit.isPending}
                        onCancel={() => setEditing(null)}
                        onSubmit={(input) =>
                            edit.mutate({ id: editing.id, input: input.token === null ? { branch: input.branch } : { branch: input.branch, token: input.token } })
                        }
                    />
                )}
            </Dialog>
            <Dialog.Confirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title={`Remove ${removing?.repo ?? ''}?`}
                confirm='Remove'
                pending={remove.isPending}
                onConfirmed={() => removing && remove.mutate(removing.id)}
            >
                Its guides disappear from every server right away. Pins to folders that only this repo has stop working.
            </Dialog.Confirm>
        </PageContentBlock>
    );
}
