<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_guides_repos', function (Blueprint $table) {
            $table->id();
            $table->string('repo', 140);
            $table->string('branch', 100)->default('main');
            // Encrypted personal access token for private repos, never sent to browsers.
            $table->text('token')->nullable();
            // Admin repos are read top to bottom; the community repo is always last.
            $table->unsignedInteger('position')->default(0);
            $table->boolean('community')->default(false);

            // The synced copy: a folder under storage/app/ext-guides and what was in it.
            $table->string('directory', 64)->nullable();
            $table->string('revision', 64)->nullable();
            $table->json('problems')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamps();

            $table->unique('repo');
        });

        Schema::create('ext_guides_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repo_id')->constrained('ext_guides_repos')->cascadeOnDelete();
            $table->string('folder', 64);
            // Null when the folder has no usable match.json; its guides still merge into
            // the same folder of a lower repo that has one.
            $table->json('match')->nullable();

            $table->unique(['repo_id', 'folder']);
        });

        Schema::create('ext_guides_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repo_id')->constrained('ext_guides_repos')->cascadeOnDelete();
            $table->string('folder', 64);
            $table->string('slug', 100);
            $table->string('title', 200);
            $table->string('framework', 64)->nullable();
            $table->integer('sort')->default(100);
            $table->string('summary', 300)->nullable();
            $table->mediumText('body');

            $table->unique(['repo_id', 'folder', 'slug']);
            $table->index('folder');
        });

        Schema::create('ext_guides_eggs', function (Blueprint $table) {
            $table->unsignedInteger('egg_id')->primary();
            // "pin" uses the folder below, "off" hides guides for the egg.
            $table->string('mode', 8);
            $table->string('folder', 64)->nullable();
            $table->timestamps();

            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
        });

        DB::table('ext_guides_repos')->insert([
            'repo' => 'Rustix-Development/pterodactyl-egg-guides',
            'branch' => 'main',
            'position' => 0,
            'community' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_guides_eggs');
        Schema::dropIfExists('ext_guides_guides');
        Schema::dropIfExists('ext_guides_folders');
        Schema::dropIfExists('ext_guides_repos');
    }
};
