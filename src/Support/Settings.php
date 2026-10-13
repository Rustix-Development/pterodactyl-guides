<?php

namespace RustixDev\Guides\Support;

use Pterodactyl\Facades\Extensions;
use Throwable;

final class Settings
{
    /** Minutes between automatic syncs => label. 0 turns automatic syncs off. */
    public const array INTERVALS = [
        15 => 'Every 15 minutes',
        30 => 'Every 30 minutes',
        60 => 'Every hour',
        180 => 'Every 3 hours',
        360 => 'Every 6 hours',
        720 => 'Every 12 hours',
        1440 => 'Once a day',
        0 => 'Only when I press Sync now',
    ];

    public const int DEFAULT_INTERVAL = 60;

    public static function interval(): int
    {
        try {
            $value = Extensions::settings('guides')->get('sync_interval', self::DEFAULT_INTERVAL);
        } catch (Throwable) {
            $value = self::DEFAULT_INTERVAL;
        }

        return is_numeric($value) && array_key_exists((int) $value, self::INTERVALS) ? (int) $value : self::DEFAULT_INTERVAL;
    }

    public static function setInterval(int $minutes): void
    {
        Extensions::settings('guides')->set('sync_interval', $minutes);
    }
}
