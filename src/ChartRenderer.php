<?php

declare(strict_types=1);

namespace DarkSlide;

/**
 * Render an ECharts option to a PNG at export time, so a chart shows up in the
 * .pptx exactly as it looks in the browser editor (the "what you see is what
 * you export" path).
 *
 * dark-slide stays zero-dependency, so it can't run ECharts itself — the host
 * supplies the renderer (a headless-Chrome bridge, a JS sidecar, a cached PNG
 * service). When no renderer (and no pre-rendered `chart.image`) is available,
 * the writer falls back to translating the option to a native OOXML chart.
 *
 * Chart export modes, per element: `chart.mode = 'png' | 'native'` (default
 * `'png'`). Pass a renderer via the `charts` write option:
 *
 *   Agent::toBytes($deck, ['charts' => new MyHeadlessEchartsRenderer()]);
 */
interface ChartRenderer
{
    /**
     * Render an ECharts `option` to PNG bytes at the given pixel size. Return
     * `null` to fall back to native-OOXML translation.
     *
     * @param  array<string,mixed>  $option
     */
    public function render(array $option, int $widthPx, int $heightPx): ?string;
}
