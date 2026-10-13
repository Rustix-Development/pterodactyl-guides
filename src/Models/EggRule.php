<?php

namespace RustixDev\Guides\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin's choice for one egg: pin a guide folder, or turn guides off.
 *
 * @property int $egg_id
 * @property string $mode
 * @property string|null $folder
 */
class EggRule extends Model
{
    public const string PIN = 'pin';

    public const string OFF = 'off';

    public $incrementing = false;

    protected $table = 'ext_guides_eggs';

    protected $primaryKey = 'egg_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['egg_id' => 'integer'];
    }
}
