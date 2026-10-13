<?php

namespace RustixDev\Guides\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One guide as one repo has it.
 *
 * @property int $id
 * @property int $repo_id
 * @property string $folder
 * @property string $slug
 * @property string $title
 * @property string|null $framework
 * @property int $sort
 * @property string|null $summary
 * @property string $body
 */
class Guide extends Model
{
    public $timestamps = false;

    protected $table = 'ext_guides_guides';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['repo_id' => 'integer', 'sort' => 'integer'];
    }
}
