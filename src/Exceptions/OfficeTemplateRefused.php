<?php

declare(strict_types=1);

namespace DarkSlide\Exceptions;

use RuntimeException;

/**
 * An Office or LibreOffice template package refused, with a constant `reason`:
 *
 * - `macro_enabled`   a .potm (or a template carrying a VBA project). Never read
 *                     and never converted: the macros are not ours to carry.
 * - `family_mismatch` it declares a template of another family, or its parts
 *                     are not the family it declares.
 * - `already_template` / `already_document`  conversion asked for what it is.
 * - `not_convertible` there is no single declaration that can be rewritten.
 * - `bad_target`      the conversion target is not `document` or `template`.
 *
 * Branch on `reason`; the message is for people.
 */
final class OfficeTemplateRefused extends RuntimeException
{
    private const MESSAGES = [
        'macro_enabled' => 'Macro-enabled templates (.potm) are refused: re-save it as a .potx in PowerPoint.',
        'family_mismatch' => 'This package declares a template of a different kind than its contents.',
        'already_template' => 'This package is already a template.',
        'already_document' => 'This package is already a document.',
        'not_convertible' => 'This package has no single PowerPoint content-type declaration that can be rewritten (OpenDocument presentations are not read by dark-slide).',
        'bad_target' => 'The conversion target must be "document" or "template".',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Template refused ('.$reason.'): '.(self::MESSAGES[$reason] ?? $reason));
    }
}
