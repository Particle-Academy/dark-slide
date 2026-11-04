<?php

declare(strict_types=1);

namespace DarkSlide\Archive;

use SimpleXMLElement;

/**
 * The one way an XML part of an uploaded file gets parsed.
 *
 * A part carrying a DOCTYPE is refused, because no presentation part has one and
 * an internal subset is the entry point for entity expansion. A refusal looks
 * exactly like malformed XML, so each caller keeps its own handling of a part
 * it cannot read.
 *
 * The check has two halves and the second is the one that holds:
 *
 *  - {@see prologDeclaresDoctype()} looks at the bytes before anything is
 *    parsed, so the ordinary case never reaches the entity machinery. It reads
 *    only the prolog: searching the whole part for the word would refuse a
 *    legitimate run whose CDATA text is an HTML page.
 *  - The parsed document is then asked whether it has a doctype. A part can
 *    declare any encoding libxml can decode, and no byte scan reads them all,
 *    so the parser's own answer is what decides. Nothing external is loaded in
 *    this parse, libxml's own limits bound its work, and the document is
 *    dropped before any value is read from it.
 *
 * @internal
 */
final class PartXml
{
    /** @return SimpleXMLElement|false false when malformed or refused, as `simplexml_load_string` reports malformed */
    public static function simple(string $xml): SimpleXMLElement|false
    {
        if (self::prologDeclaresDoctype($xml)) {
            return false;
        }
        $prev = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($element === false) {
            return false;
        }

        return dom_import_simplexml($element)->ownerDocument?->doctype === null ? $element : false;
    }

    /**
     * Whether a DOCTYPE comes before the root element, for the encodings a byte
     * walk can read: anything ASCII-compatible, and UTF-16 or UTF-32 once their
     * NUL bytes are dropped (which keeps the ASCII this looks for).
     */
    public static function prologDeclaresDoctype(string $xml): bool
    {
        if (str_contains(substr($xml, 0, 4), "\0")) {
            $xml = str_replace("\0", '', $xml);
        }
        $at = 0;
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($xml, $bom)) {
                $at = strlen($bom);
                break;
            }
        }
        while (true) {
            $at += strspn($xml, " \t\r\n", $at);
            $close = match (true) {
                substr($xml, $at, 2) === '<?' => '?>',
                substr($xml, $at, 4) === '<!--' => '-->',
                default => null,
            };
            if ($close === null) {
                return strcasecmp(substr($xml, $at, 9), '<!DOCTYPE') === 0;
            }
            $end = strpos($xml, $close, $at);
            if ($end === false) {
                return false;
            }
            $at = $end + strlen($close);
        }
    }
}
