import type { MouseEvent } from 'react';

/**
 * Shows a guide the panel has already rendered and made safe (raw HTML escaped, unsafe
 * links removed). Links to another guide of the same game stay in the tab.
 */
export default function GuideBody({ html, onOpenGuide }: { html: string; onOpenGuide: (slug: string) => void }) {
    const onClick = (event: MouseEvent<HTMLDivElement>) => {
        const link = (event.target as HTMLElement).closest('a[data-guide]');
        if (link) {
            event.preventDefault();
            onOpenGuide(link.getAttribute('data-guide') ?? '');
        }
    };

    // eslint-disable-next-line react/no-danger -- sanitized by the panel's renderer
    return <div className='rxgd-prose' onClick={onClick} dangerouslySetInnerHTML={{ __html: html }} />;
}
