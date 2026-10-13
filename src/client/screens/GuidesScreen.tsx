import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, BookOpen } from 'lucide-react';
import {
    Alert,
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    httpErrorToHuman,
    Icon,
    ServerContentBlock,
    Spinner,
    useCurrentServerRequired,
} from '@pterodactyl/sdk';
import { guideQuery, guidesQuery, type GuideGroup } from '../api';
import GuideBody from '../components/GuideBody';

const readHash = () => (typeof window === 'undefined' ? '' : decodeURIComponent(window.location.hash.replace(/^#/, '')));

function useWide(): boolean {
    const query = '(min-width: 768px)';
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && !!window.matchMedia?.(query).matches);
    useEffect(() => {
        const media = window.matchMedia?.(query);
        if (!media) return;
        const update = () => setWide(media.matches);
        media.addEventListener('change', update);

        return () => media.removeEventListener('change', update);
    }, []);

    return wide;
}

function GuideList({ groups, selected, onSelect }: { groups: GuideGroup[]; selected: string | null; onSelect: (slug: string) => void }) {
    return (
        <nav aria-label='Guides' className='rxgd:flex rxgd:flex-col rxgd:gap-4'>
            {groups.map((group) => (
                <section key={group.label} className='rxgd:min-w-0'>
                    <h3 className='rxgd:m-0 rxgd:mb-1.5 rxgd:px-1 rxgd:text-xs rxgd:font-semibold rxgd:uppercase rxgd:tracking-wide rxgd:text-muted-foreground'>
                        {group.label}
                    </h3>
                    <ul className='rxgd:m-0 rxgd:flex rxgd:list-none rxgd:flex-col rxgd:gap-1 rxgd:p-0'>
                        {group.guides.map((guide) => {
                            const active = guide.slug === selected;

                            return (
                                <li key={guide.slug}>
                                    <button
                                        type='button'
                                        aria-current={active ? 'page' : undefined}
                                        onClick={() => onSelect(guide.slug)}
                                        className={`rxgd:w-full rxgd:cursor-pointer rxgd:rounded-md rxgd:border rxgd:px-3 rxgd:py-2 rxgd:text-left rxgd:transition-colors ${
                                            active
                                                ? 'rxgd:border-primary rxgd:bg-card rxgd:text-card-foreground rxgd:shadow-[inset_3px_0_0_var(--primary)]'
                                                : 'rxgd:border-border rxgd:bg-card rxgd:text-card-foreground rxgd:hover:border-primary/60'
                                        }`}
                                    >
                                        <span className='rxgd:block rxgd:text-sm rxgd:font-medium'>{guide.title}</span>
                                        {guide.summary && <span className='rxgd:mt-0.5 rxgd:block rxgd:text-xs rxgd:text-muted-foreground'>{guide.summary}</span>}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </nav>
    );
}

function GuideView({ uuid, slug, onOpenGuide }: { uuid: string; slug: string; onOpenGuide: (slug: string) => void }) {
    const { data, error, isLoading } = useQuery(guideQuery(uuid, slug));

    if (isLoading) return <Spinner centered />;
    if (error || !data) return <Alert type='danger'>{httpErrorToHuman(error)}</Alert>;

    return (
        <article className='rxgd:min-w-0'>
            <header className='rxgd:mb-4 rxgd:border-b rxgd:border-border rxgd:pb-3'>
                <p className='rxgd:m-0 rxgd:text-xs rxgd:font-medium rxgd:uppercase rxgd:tracking-wide rxgd:text-muted-foreground'>{data.group}</p>
                <h2 className='rxgd:m-0 rxgd:text-xl rxgd:font-semibold'>{data.title}</h2>
                {data.summary && <p className='rxgd:m-0 rxgd:mt-1 rxgd:text-sm rxgd:text-muted-foreground'>{data.summary}</p>}
            </header>
            <GuideBody html={data.html} onOpenGuide={onOpenGuide} />
        </article>
    );
}

export default function GuidesScreen() {
    const server = useCurrentServerRequired();
    const uuid = server.attributes.uuid;
    const { data, error, isLoading } = useQuery(guidesQuery(uuid));
    const wide = useWide();
    const [chosen, setChosen] = useState<string | null>(() => readHash() || null);

    const groups = data?.groups ?? [];
    const slugs = groups.flatMap((group) => group.guides.map((guide) => guide.slug));
    const known = chosen !== null && slugs.includes(chosen) ? chosen : null;
    // On wide screens the first guide is open from the start; on phones the list shows first.
    const selected = known ?? (wide ? (slugs[0] ?? null) : null);

    const open = (slug: string | null) => {
        setChosen(slug);
        if (typeof window !== 'undefined') {
            window.history.replaceState(window.history.state, '', slug ? `#${slug}` : window.location.pathname + window.location.search);
            if (slug && !wide) window.scrollTo({ top: 0 });
        }
    };

    let content;
    if (isLoading) {
        content = <Spinner centered />;
    } else if (error) {
        content = <Alert type='danger'>{httpErrorToHuman(error)}</Alert>;
    } else if (groups.length === 0) {
        content = (
            <Empty className='rxgd:border'>
                <EmptyHeader>
                    <EmptyMedia variant='icon'>
                        <Icon icon={BookOpen} />
                    </EmptyMedia>
                    <EmptyTitle>No guides for this server yet</EmptyTitle>
                    <EmptyDescription>There are no help articles for this game on the panel yet. Check back later.</EmptyDescription>
                </EmptyHeader>
            </Empty>
        );
    } else {
        content = (
            <div className='rxgd:grid rxgd:items-start rxgd:gap-4 rxgd:md:grid-cols-[minmax(0,17rem)_minmax(0,1fr)]'>
                <div className={selected && !wide ? 'rxgd:hidden' : 'rxgd:min-w-0'}>
                    <GuideList groups={groups} selected={selected} onSelect={open} />
                </div>
                {selected && (
                    <div className='rxgd:min-w-0 rxgd:rounded-md rxgd:border rxgd:border-border rxgd:bg-card rxgd:p-4 rxgd:text-card-foreground rxgd:md:p-6'>
                        {!wide && (
                            <button
                                type='button'
                                onClick={() => open(null)}
                                className='rxgd:mb-3 rxgd:inline-flex rxgd:cursor-pointer rxgd:items-center rxgd:gap-1 rxgd:border-0 rxgd:bg-transparent rxgd:p-0 rxgd:text-sm rxgd:text-muted-foreground rxgd:hover:text-foreground'
                            >
                                <Icon icon={ArrowLeft} className='rxgd:size-4' /> All guides
                            </button>
                        )}
                        <GuideView uuid={uuid} slug={selected} onOpenGuide={open} />
                    </div>
                )}
            </div>
        );
    }

    return (
        <ServerContentBlock title='Guides'>
            <div className='rxgd:mb-4 rxgd:min-w-0'>
                <h2 className='rxgd:m-0 rxgd:text-lg rxgd:font-medium'>Guides</h2>
                {data?.label && (
                    <p className='rxgd:m-0 rxgd:text-sm rxgd:text-muted-foreground'>
                        Help for {data.label}
                        {data.framework ? ` with ${data.framework.label}` : ''}
                    </p>
                )}
            </div>
            {content}
        </ServerContentBlock>
    );
}
