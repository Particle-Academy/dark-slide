<?php

declare(strict_types=1);

use DarkSlide\Agent;

/**
 * The acceptance test IS the artifact.
 *
 * `tests/fixtures/reference-deck.json` is a nine-slide deck rebuilt from the
 * construct classes of a real paginated business-case document: a title slide
 * with a six-field metadata grid, a KPI band, callouts with a left accent bar,
 * data tables with a dark header / zebra striping / a highlighted total row,
 * section and accent sub-headings, bullet and check-mark lists, and a
 * three-column comparison table.
 *
 * The source document is a client file. Its STRUCTURE is the bar; none of its
 * content, names or branding appears here.
 *
 * That one file is also the shared fixture for the whole trio — the Node and
 * Python parity suites load it from this repo through the same
 * `DARK_SLIDE_PHP_SRC` / sibling-checkout resolution they already use to find
 * these sources, so the three engines are compared on the same bytes rather
 * than on three transcriptions of the same intent.
 *
 * Assertions here are per CONSTRUCT CLASS. A construct that stops being
 * expressible should fail one named test, not a wall of byte diffs.
 */
function refDeck(): array
{
    $json = file_get_contents(__DIR__ . '/../fixtures/reference-deck.json');

    return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, string> every part of the rendered deck, keyed by path */
function refParts(): array
{
    static $parts = null;
    if ($parts !== null) {
        return $parts;
    }

    $path = tempnam(sys_get_temp_dir(), 'refdeck') . '.pptx';
    Agent::write(refDeck(), $path);

    $zip = new ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $parts[$name] = (string) $zip->getFromName($name);
    }
    $zip->close();
    @unlink($path);

    return $parts;
}

function refSlide(int $n): string
{
    return refParts()["ppt/slides/slide{$n}.xml"];
}

it('validates clean', function () {
    expect(Agent::validate(refDeck()))->toBe([]);
});

it('renders every slide', function () {
    $parts = refParts();
    foreach (range(1, 9) as $n) {
        expect($parts)->toHaveKey("ppt/slides/slide{$n}.xml");
    }
    expect($parts)->not->toHaveKey('ppt/slides/slide10.xml');
});

// ─── Construct 1: the metadata grid ───────────────────────────────────────

it('builds a 3-across, 2-down metadata grid with letterspaced small-caps labels', function () {
    $xml = refSlide(1);

    expect(preg_match_all('/<a:gridCol /', $xml))->toBe(3);
    expect(preg_match_all('/<a:tr /', $xml))->toBe(4);
    expect($xml)->toContain('cap="small"');
    expect($xml)->toContain('spc="120"');
    expect($xml)->toContain('<a:t>Prepared by</a:t>');
    expect($xml)->toContain('<a:t>Operations Analysis</a:t>');

    // A panel, not a grid: every rule is explicitly off.
    expect($xml)->toContain('<a:lnL><a:noFill/></a:lnL>');
    expect($xml)->not->toContain('<a:lnL w=');
});

it('gives the title slide a rule that is a filled shape with no outline', function () {
    expect(refSlide(1))->toContain('<a:ln><a:noFill/></a:ln>');
});

// ─── Construct 2: the KPI band ────────────────────────────────────────────

it('builds a four-figure KPI band with captions and no rule between the two', function () {
    $xml = refSlide(2);

    foreach (['$51K-$68K', '$31K-$48K', '155%-240%', '3-5 months'] as $figure) {
        expect($xml)->toContain('<a:t>' . htmlspecialchars($figure, ENT_XML1) . '</a:t>');
    }
    expect($xml)->toContain('<a:t>Estimated payback period</a:t>');

    preg_match('/<a:tbl>.*?<\/a:tbl>/s', $xml, $m);
    expect(preg_match_all('/<a:gridCol /', $m[0]))->toBe(4);

    preg_match_all('/<a:tr [^>]*>(.*?)<\/a:tr>/s', $m[0], $rows);
    expect($rows[1][0])->toContain('<a:lnB><a:noFill/></a:lnB>');
    expect($rows[1][1])->toContain('<a:lnT><a:noFill/></a:lnT>');

    // The vertical rules BETWEEN the figures survive.
    expect($rows[1][0])->toContain('<a:lnR w=');
});

// ─── Construct 3: the callout ─────────────────────────────────────────────

it('draws each callout as ONE shape with a hard-stop gradient accent bar', function () {
    foreach ([2, 5, 9] as $slide) {
        $xml = refSlide($slide);
        expect($xml)->toContain('<a:gradFill flip="none" rotWithShape="0">');
        expect($xml)->toContain('<a:lin ang="0" scaled="0"/>');

        // Four stops, two pairs — a bar and a flat tint, not a blend.
        preg_match('/<a:gsLst>.*?<\/a:gsLst>/s', $xml, $m);
        expect(substr_count($m[0], '<a:gs '))->toBe(4);

        // And the text is inset clear of the bar.
        expect($xml)->toContain('lIns=');
    }
});

// ─── Construct 4: the data tables ─────────────────────────────────────────

it('gives every data table a dark header row, zebra striping and per-cell rules', function () {
    $xml = refSlide(3);

    expect($xml)->toContain('<a:srgbClr val="1B3A5C"/>');   // header fill
    expect($xml)->toContain('<a:srgbClr val="F1F5F9"/>');   // stripe
    expect($xml)->toContain('<a:lnT w="6350"');             // 0.5pt inner rule
    expect($xml)->toContain('<a:lnT w="9525"');             // 0.75pt outer rule
});

it('highlights the total row in a different fill from the body', function () {
    $xml = refSlide(3);

    preg_match_all('/<a:tr [^>]*>(.*?)<\/a:tr>/s', $xml, $rows);
    $total = $rows[1][count($rows[1]) - 1];

    expect($total)->toContain('<a:srgbClr val="0E7C86"/>');
    expect($total)->toContain('<a:srgbClr val="FFFFFF"/>');
    expect($total)->toContain(' b="1"');
    expect($total)->toContain('<a:t>228-608 hrs/year</a:t>');
});

it('gives numeric columns their own alignment and unequal widths', function () {
    $xml = refSlide(3);

    preg_match_all('/<a:gridCol w="(\d+)"\/>/', $xml, $m);
    $widths = array_map('intval', $m[1]);
    expect(count($widths))->toBe(4);
    expect($widths[0])->toBeGreaterThan($widths[1]);
    expect(array_sum($widths))->toBe(7863840);   // 0.86 of the slide, exactly

    expect($xml)->toContain('algn="r"');
});

// ─── Construct 5: headings and lists ──────────────────────────────────────

it('uses an accent colour for sub-headings and the ink colour for sections', function () {
    expect(refSlide(3))->toContain('<a:srgbClr val="0E7C86"/>');
    expect(refSlide(3))->toContain('<a:srgbClr val="1B3A5C"/>');
});

it('renders a check-mark list and a plain bullet list from the same primitive', function () {
    expect(refSlide(5))->toContain('<a:buChar char="✓"/>');
    expect(refSlide(9))->toContain('<a:buChar char="•"/>');
});

it('sets line spacing on the prose so lists are not cramped', function () {
    expect(refSlide(5))->toContain('<a:lnSpc><a:spcPct val="150000"/></a:lnSpc>');
});

// ─── Construct 6: the comparison table ────────────────────────────────────

it('top-anchors the three-column comparison table so wrapped cells line up', function () {
    $xml = refSlide(7);

    preg_match('/<a:tbl>.*?<\/a:tbl>/s', $xml, $m);
    expect(preg_match_all('/<a:gridCol /', $m[0]))->toBe(3);

    // Body cells anchor top; the header row keeps its centred anchor.
    expect($m[0])->toContain('anchor="t"');
    expect($m[0])->toContain('anchor="ctr"');
    expect($m[0])->toContain('<a:t>Current pain point</a:t>');
});

// ─── Round trip ───────────────────────────────────────────────────────────

it('reads back every slide and the text on it', function () {
    $path = tempnam(sys_get_temp_dir(), 'refrt') . '.pptx';
    Agent::write(refDeck(), $path);
    $read = Agent::read($path);
    @unlink($path);

    expect($read['slides'])->toHaveCount(9);

    $text = json_encode($read, JSON_UNESCAPED_SLASHES);
    expect($text)->toContain('Field Operations Platform');
    expect($text)->toContain('Executive Summary');
    expect($text)->toContain('228-608 hrs/year');
    expect($text)->toContain('Estimated payback period');
});

it('reads a composite back as the table it expanded into, which is documented and not hidden', function () {
    $path = tempnam(sys_get_temp_dir(), 'refrt') . '.pptx';
    Agent::write(refDeck(), $path);
    $read = Agent::read($path);
    @unlink($path);

    $types = array_column($read['slides'][0]['elements'], 'type');
    expect($types)->toContain('table');
    expect($types)->not->toContain('metadataGrid');
});

it('re-writes what it read without losing a slide', function () {
    $path = tempnam(sys_get_temp_dir(), 'refrt') . '.pptx';
    Agent::write(refDeck(), $path);
    $read = Agent::read($path);
    @unlink($path);

    expect(Agent::validate($read))->toBe([]);

    $second = tempnam(sys_get_temp_dir(), 'refrt2') . '.pptx';
    $result = Agent::write($read, $second);
    @unlink($second);

    expect($result['slides'])->toBe(9);
});
