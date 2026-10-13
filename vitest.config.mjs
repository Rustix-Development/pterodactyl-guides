import { defineConfig } from 'vitest/config';
import { pterodactylTestAliases, pterodactylTestSetup, pterodactylTestDirectory } from '@pterodactyl/sdk/vitest';

export default defineConfig({
    server: { fs: { allow: [process.cwd(), pterodactylTestDirectory] } },
    resolve: { alias: pterodactylTestAliases() },
    test: {
        environment: 'jsdom',
        setupFiles: [pterodactylTestSetup],
        // The SDK is installed from a tarball, so it sits in node_modules. Inline it so its
        // runtime and the extension share one copy of React and TanStack Query.
        server: { deps: { inline: [/@pterodactyl\/sdk/] } },
    },
});
