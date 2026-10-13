import './styles.css';
import { useSuspenseQuery } from '@tanstack/react-query';
import { definePterodactylExtension, useServerPermission, type ScreenContext } from '@pterodactyl/sdk';
import { EMPTY, guidesQuery } from './api';

/** The tab only shows on servers that have guides, to users allowed to read them. */
export function useHasGuides({ server }: ScreenContext): boolean {
    const allowed = useServerPermission('ext.guides.view');
    const uuid = server?.attributes.uuid ?? '';
    const query = guidesQuery(uuid);
    const { data } = useSuspenseQuery(
        allowed && uuid !== '' ? query : { queryKey: [...query.queryKey, 'not-allowed'], queryFn: async () => EMPTY },
    );

    return data.groups.length > 0;
}

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('guides', () => import('./screens/GuidesScreen'), { visible: useHasGuides });
        screens.register('admin', () => import('./screens/AdminScreen'));
    },
});
