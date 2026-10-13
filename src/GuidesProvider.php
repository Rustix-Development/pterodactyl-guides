<?php

namespace RustixDev\Guides;

use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use RustixDev\Guides\Console\SyncCommand;
use RustixDev\Guides\Support\Settings;

class GuidesProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->registerAuthenticatedWebRoutes($this->extensionPath('routes', 'web.php'));

        $this->registerPermissions('Guides', [
            'view' => 'View guides: see the Guides tab with help articles for this server\'s game.',
        ]);

        $intervals = [];
        foreach (Settings::INTERVALS as $value => $label) {
            $intervals[] = ['value' => $value, 'label' => $label];
        }
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('sync_interval', 'sync_interval', Settings::DEFAULT_INTERVAL, ['integer'])
                ->field('select', $intervals)
                ->label('Sync guides')
                ->help('How often the panel downloads the guide repos from GitHub. Repos, tokens and Sync now are under Admin → Guides.'),
        ]));

        $this->registerCommands([SyncCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            // Cheap when nothing is due: each repo is only downloaded once its interval has passed.
            $schedule->command('guides:sync --due')->everyFiveMinutes()->withoutOverlapping(30);
        });
    }
}
