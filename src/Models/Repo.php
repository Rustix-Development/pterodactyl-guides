<?php

namespace RustixDev\Guides\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A GitHub repo guides are read from.
 *
 * @property int $id
 * @property string $repo
 * @property string $branch
 * @property string|null $token
 * @property int $position
 * @property bool $community
 * @property string|null $directory
 * @property string|null $revision
 * @property list<array{path: string, message: string}>|null $problems
 * @property Carbon|null $synced_at
 * @property Carbon|null $attempted_at
 * @property string|null $error
 */
class Repo extends Model
{
    public const string COMMUNITY = 'Rustix-Development/pterodactyl-egg-guides';

    protected $table = 'ext_guides_repos';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'position' => 'integer',
            'community' => 'boolean',
            'problems' => 'array',
            'synced_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }

    /** Highest priority first: admin repos in their order, the community repo last. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('community')->orderBy('position')->orderBy('id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'repo_id');
    }

    public function guides(): HasMany
    {
        return $this->hasMany(Guide::class, 'repo_id');
    }

    public function url(): string
    {
        return 'https://github.com/'.$this->repo.'/tree/'.rawurlencode($this->branch);
    }
}
