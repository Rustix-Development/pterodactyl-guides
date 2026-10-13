<?php

/**
 * Builds a zip shaped like GitHub's zipball: everything inside one top folder named
 * <owner>-<repo>-<sha>. Shared by the tests and the local stand-in for GitHub.
 */
function guides_zip_directory(string $directory, string $zipPath, string $root = 'owner-repo-0123abc'): void
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create '.$zipPath);
    }
    $zip->addEmptyDir($root);
    $base = realpath($directory);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($base) + 1);
        if (str_starts_with($relative, '.git/') || $relative === '.git') {
            continue;
        }
        $file->isDir() ? $zip->addEmptyDir($root.'/'.$relative) : $zip->addFile($file->getPathname(), $root.'/'.$relative);
    }
    $zip->close();
}
