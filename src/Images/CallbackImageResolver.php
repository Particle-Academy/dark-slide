<?php

declare(strict_types=1);

namespace DarkSlide\Images;

use Closure;
use DarkSlide\ImageResolver;

/**
 * Wrap a closure as an {@see ImageResolver} — the zero-ceremony way to wire an
 * HTTP / S3 / asset-library resolver without a dedicated class:
 *
 *   Agent::toBytes($deck, ['images' => new CallbackImageResolver(
 *       fn (string $src) => $assets->bytesFor($src),
 *   )]);
 */
final class CallbackImageResolver implements ImageResolver
{
    /** @param Closure(string):?string $resolver */
    public function __construct(private Closure $resolver) {}

    public function resolve(string $src): ?string
    {
        return ($this->resolver)($src);
    }
}
