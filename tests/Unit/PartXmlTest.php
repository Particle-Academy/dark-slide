<?php

declare(strict_types=1);

use DarkSlide\Archive\PartXml;

const DSPX_DOCTYPE = '<!DOCTYPE r [<!ENTITY x "HSINT">]>';

/** An encoding the byte walk does not read, and libxml decodes through iconv. */
function dspxUtf7Doctype(): string
{
    return '<?xml version="1.0" encoding="UTF-7"?>'
        .'+ADw-+ACE-DOCTYPE r +AFs-+ADw-+ACE-ENTITY x +ACI-HSINT+ACI-+AD4-+AF0-+AD4-'
        .'+ADw-r+AD4-+ACY-x+ADs-+ADw-/r+AD4-';
}

describe('the prolog check', function () {
    it('finds a DOCTYPE before the root', function (string $xml) {
        expect(PartXml::prologDeclaresDoctype($xml))->toBeTrue();
    })->with([
        'bare' => [DSPX_DOCTYPE.'<r/>'],
        'after a declaration' => ['<?xml version="1.0"?>'.DSPX_DOCTYPE.'<r/>'],
        'after whitespace and a comment' => ["<?xml version=\"1.0\"?>\n <!-- c -->\n".DSPX_DOCTYPE.'<r/>'],
        'after a UTF-8 BOM' => ["\xEF\xBB\xBF".DSPX_DOCTYPE.'<r/>'],
        'past a long comment' => ['<!-- '.str_repeat('p', 100000).' -->'.DSPX_DOCTYPE.'<r/>'],
        'after a processing instruction' => ['<?pi a?>'.DSPX_DOCTYPE.'<r/>'],
    ]);

    it('finds one in UTF-16', function (string $bom, string $encoding) {
        $xml = $bom.mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>'.DSPX_DOCTYPE.'<r/>', $encoding, 'UTF-8');

        expect(PartXml::prologDeclaresDoctype($xml))->toBeTrue();
    })->with([
        'LE with a BOM' => ["\xFF\xFE", 'UTF-16LE'],
        'BE with a BOM' => ["\xFE\xFF", 'UTF-16BE'],
        'LE without' => ['', 'UTF-16LE'],
        'BE without' => ['', 'UTF-16BE'],
    ]);

    it('finds none where there is none', function (string $xml) {
        expect(PartXml::prologDeclaresDoctype($xml))->toBeFalse();
    })->with([
        'empty' => [''],
        'a root' => ['<r/>'],
        'text after the root' => ['<r><![CDATA[<!DOCTYPE html>]]></r>'],
        'in a comment' => ['<!-- <!DOCTYPE x> --><r/>'],
        'in a processing instruction' => ['<?pi <!DOCTYPE x>?><r/>'],
        'an unterminated comment' => ['<!-- '.DSPX_DOCTYPE],
        'an unterminated declaration' => ['<?xml '.DSPX_DOCTYPE],
    ]);
});

describe('the gate', function () {
    it('returns what the primitive returns for a clean part', function () {
        $sx = PartXml::simple('<?xml version="1.0"?><r a="1"><b>t</b></r>');

        expect($sx)->toBeInstanceOf(SimpleXMLElement::class)
            ->and((string) $sx->b)->toBe('t')
            ->and((string) PartXml::dom('<r a="1"/>')?->documentElement?->getAttribute('a'))->toBe('1');
    });

    it('fails malformed XML the way it fails a DOCTYPE', function () {
        expect(PartXml::simple('<r>'))->toBeFalse()
            ->and(PartXml::dom('<r>'))->toBeNull()
            ->and(PartXml::simple(DSPX_DOCTYPE.'<r/>'))->toBeFalse()
            ->and(PartXml::dom(DSPX_DOCTYPE.'<r/>'))->toBeNull();
    });

    it('refuses a DOCTYPE the prolog check cannot read, by asking the parser', function () {
        $utf7 = dspxUtf7Doctype();
        $live = @simplexml_load_string($utf7);
        if ($live === false || (string) $live !== 'HSINT') {
            $this->markTestSkipped('this libxml has no UTF-7 decoder, so there is nothing to refuse');
        }

        expect(PartXml::prologDeclaresDoctype($utf7))->toBeFalse()
            ->and(PartXml::declaresDoctype($utf7))->toBeTrue()
            ->and(PartXml::simple($utf7))->toBeFalse()
            ->and(PartXml::dom($utf7))->toBeNull();
    });

    it('tells a DOCTYPE from malformed XML when asked', function () {
        expect(PartXml::declaresDoctype('<r>'))->toBeFalse()
            ->and(PartXml::declaresDoctype(''))->toBeFalse()
            ->and(PartXml::declaresDoctype('<r/>'))->toBeFalse()
            ->and(PartXml::declaresDoctype('<?xml version="1.0"?><!DOCTYPE r'))->toBeTrue();
    });

    it('leaves the caller\'s libxml error handling as it found it', function () {
        $before = libxml_use_internal_errors(false);
        PartXml::simple('<r>');
        PartXml::dom('<r>');
        $after = libxml_use_internal_errors($before);

        expect($after)->toBeFalse();
    });
});
