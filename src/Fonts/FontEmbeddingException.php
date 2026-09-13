<?php

declare(strict_types=1);

namespace DarkSlide\Fonts;

use RuntimeException;

/**
 * A font the host asked to embed that cannot be embedded.
 *
 * Thrown rather than skipped. A deck that silently leaves out a font it was
 * asked to carry renders in a substitute face on every machine without it, which
 * is the exact failure embedding exists to prevent, and nothing would say so.
 */
final class FontEmbeddingException extends RuntimeException
{
}
