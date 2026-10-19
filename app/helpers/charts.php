<?php
/**
 * Charts, drawn as inline SVG.
 *
 * No build step and no chart library: the whole panel ships as PHP, CSS and
 * one script, and a dashboard is not worth breaking that for. Each function
 * returns a complete <figure> - plot, axis, and a table of the same numbers
 * folded underneath, because a value a reader can only reach by hovering is
 * a value some readers cannot reach at all.
 *
 * Every chart here plots one measure, so every chart is one colour. Two
 * measures on one plot would need two scales, and the alignment between two
 * scales is arbitrary - it invents a relationship the data does not have.
 * Orders and revenue are therefore two charts, not one with two axes.
 */

/** Round a maximum up to something an axis can label honestly. */
function chart_ceiling(float $max): float
{
    if ($max <= 0) {
        return 1.0;
    }
    $magnitude = 10 ** floor(log10($max));
    foreach ([1, 1.5, 2, 2.5, 5, 10] as $step) {
        if ($max <= $magnitude * $step) {
            return $magnitude * $step;
        }
    }
    return $magnitude * 10;
}

/**
 * A single measure over time, as an area with a 2px line on top.
 *
 * @param array<int, array{label:string, value:float}> $points
 */
function chart_area(array $points, string $title, callable $format, string $id): string
{
    if (!$points) {
        return chart_empty($title);
    }

    $width  = 720;
    $height = 200;
    $padL   = 8;
    $padR   = 8;
    $padT   = 14;
    $padB   = 8;

    $values = array_column($points, 'value');
    $peak   = (float) max($values);
    $top    = chart_ceiling($peak);
    $count  = count($points);
    $plotW  = $width - $padL - $padR;
    $plotH  = $height - $padT - $padB;

    $x = static fn(int $i): float => $count < 2
        ? $padL + $plotW / 2
        : $padL + ($i / ($count - 1)) * $plotW;
    $y = static fn(float $v): float => $padT + $plotH - ($v / $top) * $plotH;

    $line = '';
    $dots = '';
    foreach ($points as $i => $point) {
        $px = round($x($i), 2);
        $py = round($y((float) $point['value']), 2);
        $line .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py . ' ';

        // A wide invisible column per point: the hit target is the whole
        // slice of the chart, not the pixel the line passes through.
        $dots .= '<rect class="ch-hit" x="' . round($px - ($plotW / max(1, $count - 1)) / 2, 2)
               . '" y="' . $padT . '" width="' . round($plotW / max(1, $count - 1), 2)
               . '" height="' . $plotH . '"'
               . ' data-label="' . e($point['label']) . '"'
               . ' data-value="' . e($format((float) $point['value'])) . '"'
               . ' data-cx="' . $px . '" data-cy="' . $py . '"></rect>';
    }

    $area = $line . 'L' . round($x($count - 1), 2) . ' ' . ($padT + $plotH)
          . ' L' . round($x(0), 2) . ' ' . ($padT + $plotH) . ' Z';

    $last   = $points[$count - 1];
    $lastX  = round($x($count - 1), 2);
    $lastY  = round($y((float) $last['value']), 2);

    $gridlines = '';
    foreach ([0.0, 0.5, 1.0] as $fraction) {
        $gy = round($padT + $plotH - $fraction * $plotH, 2);
        $gridlines .= '<line class="ch-grid" x1="' . $padL . '" y1="' . $gy
                    . '" x2="' . ($width - $padR) . '" y2="' . $gy . '"></line>';
    }

    return '<figure class="chart" data-chart>'
         . '<figcaption class="chart-head"><b>' . e($title) . '</b>'
         . '<span class="chart-top">peak ' . e($format($peak)) . '</span></figcaption>'
         . '<div class="chart-plot">'
         . '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none"'
         . ' role="img" aria-labelledby="' . e($id) . '-t">'
         . '<title id="' . e($id) . '-t">' . e($title) . '</title>'
         . $gridlines
         . '<path class="ch-area" d="' . $area . '"></path>'
         . '<path class="ch-line" d="' . trim($line) . '"></path>'
         . '<circle class="ch-end" cx="' . $lastX . '" cy="' . $lastY . '" r="4.5"></circle>'
         . $dots
         . '</svg>'
         . '<div class="ch-tip" data-tip hidden></div>'
         . '</div>'
         . '<div class="chart-axis"><span>' . e($points[0]['label']) . '</span>'
         . '<span>' . e($last['label']) . '</span></div>'
         . chart_table($points, $title, $format)
         . '</figure>';
}

/**
 * A single measure over time, as columns.
 *
 * @param array<int, array{label:string, value:float}> $points
 */
function chart_columns(array $points, string $title, callable $format, string $id): string
{
    if (!$points) {
        return chart_empty($title);
    }

    $values = array_column($points, 'value');
    $peak   = (float) max($values);
    $top    = chart_ceiling($peak);
    $bars   = '';

    foreach ($points as $point) {
        $share = $top > 0 ? ((float) $point['value'] / $top) * 100 : 0;
        $bars .= '<div class="ch-col" data-label="' . e($point['label']) . '"'
               . ' data-value="' . e($format((float) $point['value'])) . '">'
               . '<i style="height:' . round(max($share, (float) $point['value'] > 0 ? 3 : 0), 2) . '%"></i>'
               . '</div>';
    }

    return '<figure class="chart" data-chart>'
         . '<figcaption class="chart-head"><b>' . e($title) . '</b>'
         . '<span class="chart-top">peak ' . e($format($peak)) . '</span></figcaption>'
         . '<div class="chart-plot chart-cols" role="img" aria-label="' . e($title) . '">'
         . $bars
         . '<div class="ch-tip" data-tip hidden></div>'
         . '</div>'
         . '<div class="chart-axis"><span>' . e($points[0]['label']) . '</span>'
         . '<span>' . e($points[count($points) - 1]['label']) . '</span></div>'
         . chart_table($points, $title, $format)
         . '</figure>';
}

/**
 * Magnitude across named things, as horizontal bars.
 *
 * One hue for every bar. Shading each bar by its own length would spend the
 * only free channel restating the length, and these categories have no
 * order of their own anyway.
 *
 * @param array<int, array{label:string, value:float, note?:string, badge?:string}> $rows
 */
function chart_bars(array $rows, string $title, callable $format): string
{
    if (!$rows) {
        return chart_empty($title);
    }

    $top  = max(array_map(static fn(array $r): float => (float) $r['value'], $rows));
    $top  = $top > 0 ? $top : 1.0;
    $body = '';

    foreach ($rows as $row) {
        $share = ((float) $row['value'] / $top) * 100;
        $body .= '<li class="ch-row">'
               . '<span class="ch-name">'
               . ($row['badge'] ?? '') . '<span>' . e($row['label']) . '</span></span>'
               . '<b class="ch-val">' . e($format((float) $row['value'])) . '</b>'
               . '<span class="ch-track"><i style="width:'
               . round(max($share, (float) $row['value'] > 0 ? 2 : 0), 2) . '%"></i></span>'
               . '<small class="ch-note">' . e($row['note'] ?? '') . '</small>'
               . '</li>';
    }

    return '<figure class="chart">'
         . '<figcaption class="chart-head"><b>' . e($title) . '</b></figcaption>'
         . '<ul class="ch-bars">' . $body . '</ul>'
         . '</figure>';
}

/** The same numbers as text, for anyone the plot does not reach. */
function chart_table(array $points, string $title, callable $format): string
{
    $rows = '';
    foreach (array_reverse($points) as $point) {
        if ((float) $point['value'] <= 0) {
            continue;   // a month of zeroes is noise, not a table
        }
        $rows .= '<tr><td>' . e($point['label']) . '</td><td class="ta-r">'
               . e($format((float) $point['value'])) . '</td></tr>';
    }

    if ($rows === '') {
        return '';
    }

    return '<details class="chart-data"><summary>Show the numbers</summary>'
         . '<table class="tbl tbl-plain"><caption class="muted">' . e($title) . '</caption>'
         . '<tbody>' . $rows . '</tbody></table></details>';
}

function chart_empty(string $title): string
{
    return '<figure class="chart"><figcaption class="chart-head"><b>' . e($title) . '</b></figcaption>'
         . '<p class="chart-none">Nothing to show yet.</p></figure>';
}
