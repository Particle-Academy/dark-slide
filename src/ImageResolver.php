<?php

declare(strict_types=1);

namespace DarkSlide;

/**
 * Defer image-byte resolution to the consumer.
 *
 * dark-slide deliberately doesn't fetch URLs — a sensible boundary. But every
 * consumer with externally-hosted images otherwise re-implements the same
 * "walk the deck, download each `src`, rewrite to a data URI before write"
 * dance. An ImageResolver hands that one job to the host: the writer pulls bytes
 * on demand at export time, so the in-memory deck keeps its canonical `src`
 * references.
 *
 * Pass one via the `images` write option:
 *
 *   Agent::toBytes($deck, ['images' => new LocalFileImageResolver('/var/assets')]);
 */
interface ImageResolver
{
    /**
     * Resolve a `src` reference (a URL, `asset:42`, `file://…`, `s3://…`, …) to
     * raw image bytes. Return `null` to leave the element to the built-in
     * handling (data: URI / local path / opted-in http) or a placeholder.
     */
    public function resolve(string $src): ?string;
}
