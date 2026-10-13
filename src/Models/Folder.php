<?php

namespace RustixDev\Guides\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A game folder as one repo has it.
 *
 * @property int $id
 * @property int $repo_id
 * @property string $folder
 * @property array<string, mixed>|null $match
 */
class Folder extends Model
{
    public $timestamps = false;

    protected $table = 'ext_guides_folders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['repo_id' => 'integer', 'match' => 'array'];
    }
}
