<?php

namespace RustixDev\Guides\Console;

use Illuminate\Console\Command;
use RustixDev\Guides\Services\GuideSync;
use RustixDev\Guides\Sync\SyncException;

class SyncCommand extends Command
{
    protected $signature = 'guides:sync {--due : Only repos whose sync interval has passed (what the scheduler runs)}';

    protected $description = 'Download the guide repos from GitHub and refresh the panel\'s copy.';

    public function handle(GuideSync $sync): int
    {
        try {
            $lines = $sync->syncAll((bool) $this->option('due'));
        } catch (SyncException $exception) {
            $this->warn($exception->getMessage());

            return self::SUCCESS;
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
