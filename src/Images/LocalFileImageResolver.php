<?php

declare(strict_types=1);

namespace DarkSlide\Images;

use DarkSlide\ImageResolver;

/**
 * Resolve `file://…` and bare/relative path `src`s from the local filesystem,
 * optionally rooted at a base directory. Schemed URLs (http, s3, asset, …) are
 * left to other resolvers / the built-in handling.
 */
final class LocalFileImageResolver implements ImageResolver
{
    public function __construct(private string $baseDir = '') {}

    public function resolve(string $src): ?string
    {
        $path = str_starts_with($src, 'file://') ? substr($src, 7) : $src;

        // Only handle scheme-less paths here.
        if (str_contains($path, '://')) {
            return null;
        }

        // Absolute paths (Unix `/…`, UNC `\\…`, Windows `C:\…`) are used as-is;
        // only relative paths are resolved against the base dir.
        $absolute = $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);

        $full = (! $absolute && $this->baseDir !== '')
            ? rtrim($this->baseDir, '/\\').DIRECTORY_SEPARATOR.ltrim($path, '/\\')
            : $path;

        if (! is_file($full)) {
            return null;
        }

        $bytes = file_get_contents($full);

        return $bytes === false ? null : $bytes;
    }
}
