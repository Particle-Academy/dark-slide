<?php

declare(strict_types=1);

use DarkSlide\Helpers\Emu;
use DarkSlide\Table\TableResolver;
use ParticleAcademy\Conformance\Conformance;

/**
 * Run the shared `dark-slide/table-cell-model` fixtures against THIS engine.
 *
 * The suite's manifest has named three implementations since 0.7.0 — php, node,
 * python — and until now **only python actually ran it**. A shared suite one
 * engine executes is a claim about three, checked on one: the declaration reads
 * as coverage and the other two are untested. It is the same shape as the
 * defects this repository keeps finding, applied to the thing that is supposed
 * to find them.
 *
 * What it adds over byte parity, which already compares every OOXML part of a
 * nine-slide reference deck against this engine: that deck walks exactly ONE
 * path through the resolution chain per cell. A port that collapsed two
 * precedence layers would emit identical bytes for it and be wrong for every
 * deck that takes another order. These rows walk the chain one layer at a time
 * and each fails BY NAME.
 *
 * This engine is the suite's reference — every golden is the output of running
 * it — so a failure here means the resolver has moved since the goldens were
 * generated, which is exactly what should go red. Regenerate deliberately with
 * `python scripts/build-table-cell-model-goldens.py --write` in fancy-conformance.
 */
const TCM_SUITE = 'dark-slide/table-cell-model';

/** Project a resolved cell onto the suite's run shape: integers-as-strings, no floats. */
function tcmShape(array $cell): array
{
    $borders = [];
    foreach (['left', 'right', 'top', 'bottom'] as $side) {
        $spec = $cell['borders'][$side];
        $borders[$side] = $spec === null ? null : [
            'widthEmu' => (string) Emu::fromPt((float) $spec['width']),
            'color' => $spec['color'],
            'style' => $spec['style'],
        ];
    }

    $padding = [];
    foreach (['left', 'right', 'top', 'bottom'] as $side) {
        $padding[$side] = (string) Emu::fromPt((float) $cell['padding'][$side]);
    }

    return [
        'text' => $cell['text'],
        'bold' => $cell['bold'] ? '1' : '0',
        'italic' => $cell['italic'] ? '1' : '0',
        'underline' => $cell['underline'] ? '1' : '0',
        'color' => $cell['color'],
        'fill' => $cell['fill'],
        'align' => $cell['align'],
        'anchor' => $cell['anchor'],
        'fontSizeHundredths' => (string) Emu::hundredthsOfPoint((float) $cell['fontSize']),
        'letterSpacingHundredths' => (string) Emu::hundredthsOfPoint((float) $cell['letterSpacing']),
        'caps' => $cell['caps'],
        'padding' => $padding,
        'borders' => $borders,
        'colSpan' => (string) $cell['colSpan'],
        'rowSpan' => (string) $cell['rowSpan'],
        'merged' => $cell['merged'],
    ];
}

function tcmRun(array $case): mixed
{
    $input = $case['input'];
    $table = TableResolver::resolve($input['element'], $input['theme'] ?? []);

    if ($case['fn'] === 'gridWidthsEmu') {
        return array_map(
            static fn (int $w): string => (string) $w,
            TableResolver::columnWidthsEmu($table['columns'], (int) $input['totalEmu']),
        );
    }

    return tcmShape($table['rows'][$input['row']]['cells'][$input['col']]);
}

it('has rows to run', function () {
    // A renamed or moved suite would otherwise make this file silently vacuous.
    $cases = Conformance::cases(TCM_SUITE);

    expect(count($cases))->toBeGreaterThanOrEqual(20);
    expect(array_values(array_unique(array_column($cases, 'fn'))))->toEqualCanonicalizing(['resolvedCell', 'gridWidthsEmu']);
});

it('resolves every cell the shared table pins', function () {
    $summary = Conformance::runTable(TCM_SUITE, tcmRun(...));

    // Printed unconditionally: a bare "3 skipped" reads identically to full
    // coverage at a glance, which is how a suite stops meaning anything without
    // anyone deciding that it should.
    echo "\n".Conformance::formatSummary($summary)."\n";

    expect($summary['ok'])->toBeTrue(Conformance::formatSummary($summary));
    expect($summary['passed'])->toBeGreaterThanOrEqual(20);
});

it('runs the fixture version this engine pins', function () {
    // The pin moves deliberately, with the suites re-run. A floating fixture set
    // turns a red here into "someone else changed something", which is the
    // reading that gets a failure ignored.
    expect(Conformance::version())->toBe('0.34.0');
});
