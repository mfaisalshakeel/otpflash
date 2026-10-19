<?php
/**
 * A QR code, drawn as inline SVG.
 *
 * Only what an otpauth:// URI needs: byte mode, error correction level L,
 * versions 1 to 10. That covers a URI of up to 271 characters, and the
 * longest one this panel builds is nowhere near it.
 *
 * It is written out rather than installed because there is no Composer here,
 * and the usual shortcut - handing the URI to a QR image service - would post
 * the two-factor secret to a third party.
 */

/** Bytes each version holds at level L, index 1..10. */
// Up to version 15. Ten was enough while the only codes we drew were our own
// UPI intents; a bank's Raast QR is 347 bytes, which needs twelve. The rows
// are the spec's own error-correction table at level L and nothing is worked
// out from them here - a wrong number draws a code that looks right and
// scans as nothing.
const QR_CAPACITY_L = [1 => 17, 32, 53, 78, 106, 134, 154, 192, 230, 271,
                       321, 367, 425, 458, 520];

/** Total codewords per version, and how many of them are error correction. */
const QR_TOTAL_CODEWORDS = [1 => 26, 44, 70, 100, 134, 172, 196, 242, 292, 346,
                            404, 466, 532, 581, 655];
const QR_EC_CODEWORDS_L  = [1 => 7, 10, 15, 20, 26, 18, 20, 24, 30, 18,
                            20, 24, 26, 30, 22];
/** How the data is split into blocks at level L. */
const QR_BLOCKS_L        = [1 => 1, 1, 1, 1, 1, 2, 2, 2, 2, 4,
                            4, 4, 4, 4, 6];

/** Where the alignment pattern centres sit, per version. */
const QR_ALIGNMENT = [
    1 => [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
    [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
    [6, 30, 54], [6, 32, 58], [6, 34, 62], [6, 26, 46, 66], [6, 26, 48, 70],
];

/**
 * Render $text as an SVG QR code.
 *
 * @param int    $size   the drawn square, in CSS pixels
 * @param string $label  what it is, for a screen reader - a QR code is a
 *                       picture, and "QR code" alone says nothing about which
 */
function qr_svg(string $text, int $size = 190, string $label = 'QR code'): string
{
    $matrix = qr_matrix($text);
    $count  = count($matrix);
    $quiet  = 4;                       // the margin the spec requires
    $span   = $count + ($quiet * 2);

    // One path for every dark module beats one <rect> each: the same picture
    // in a fraction of the markup, which matters when it is inlined in a page.
    $path = '';
    for ($y = 0; $y < $count; $y++) {
        for ($x = 0; $x < $count; $x++) {
            if ($matrix[$y][$x]) {
                $path .= 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h1v1h-1z';
            }
        }
    }

    return '<svg class="qr" width="' . $size . '" height="' . $size . '" '
        . 'viewBox="0 0 ' . $span . ' ' . $span . '" '
        . 'shape-rendering="crispEdges" role="img" '
        . 'aria-label="' . e($label) . '">'
        . '<rect width="' . $span . '" height="' . $span . '" fill="#fff"/>'
        . '<path d="' . $path . '" fill="#15152a"/></svg>';
}

/** The module grid: true is dark. */
function qr_matrix(string $text): array
{
    $version = qr_pick_version(strlen($text));
    $bits    = qr_encode_data($text, $version);
    $size    = 17 + ($version * 4);

    [$matrix, $reserved] = qr_template($size, $version);
    qr_place_bits($matrix, $reserved, $bits, $size);

    // Eight masks exist; the spec picks the one that scores lowest on four
    // penalty rules, because a mask that leaves large blank runs or
    // finder-lookalikes is harder for a scanner to read.
    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $candidate = qr_apply_mask($matrix, $reserved, $size, $mask);
        qr_place_format($candidate, $size, $mask);
        qr_place_version($candidate, $size, $version);
        $score = qr_penalty($candidate, $size);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }
    return $best;
}

function qr_pick_version(int $length): int
{
    foreach (QR_CAPACITY_L as $version => $capacity) {
        if ($length <= $capacity) {
            return $version;
        }
    }
    // max(), not end(): end() takes its argument by reference and a constant
    // has nowhere to be referenced from, so the refusal used to end the
    // request with a TypeError instead of the message it was written to give.
    throw new RuntimeException('That text is too long for this QR encoder ('
        . $length . ' bytes; the limit is ' . max(QR_CAPACITY_L) . ').');
}

/** Mode indicator, length, data, terminator, padding, then error correction. */
function qr_encode_data(string $text, int $version): string
{
    $lengthBits = $version <= 9 ? 8 : 16;

    $bits = '0100';                                              // byte mode
    $bits .= str_pad(decbin(strlen($text)), $lengthBits, '0', STR_PAD_LEFT);
    foreach (str_split($text) as $character) {
        $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
    }

    $totalCodewords = QR_TOTAL_CODEWORDS[$version];
    $ecPerBlock     = QR_EC_CODEWORDS_L[$version];
    $blocks         = QR_BLOCKS_L[$version];
    $dataCodewords  = $totalCodewords - ($ecPerBlock * $blocks);
    $capacityBits   = $dataCodewords * 8;

    $bits .= str_repeat('0', min(4, max(0, $capacityBits - strlen($bits))));
    $bits .= str_repeat('0', (8 - (strlen($bits) % 8)) % 8);

    // 236, 17 repeating is what the spec names as the pad pattern.
    $pad = ['11101100', '00010001'];
    for ($i = 0; strlen($bits) < $capacityBits; $i++) {
        $bits .= $pad[$i % 2];
    }

    $data = [];
    foreach (str_split($bits, 8) as $byte) {
        $data[] = bindec($byte);
    }

    // Split into blocks, each with its own error-correction codewords, then
    // interleave - a scratch across the printed code then damages a few
    // codewords in every block instead of destroying one block outright.
    $shortBlock = intdiv($dataCodewords, $blocks);
    $longBlocks = $dataCodewords % $blocks;

    $dataBlocks = [];
    $ecBlocks   = [];
    $offset     = 0;
    for ($b = 0; $b < $blocks; $b++) {
        $length = $shortBlock + ($b >= $blocks - $longBlocks ? 1 : 0);
        $block  = array_slice($data, $offset, $length);
        $offset += $length;
        $dataBlocks[] = $block;
        $ecBlocks[]   = qr_error_correction($block, $ecPerBlock);
    }

    $out = '';
    $longest = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $longest; $i++) {
        foreach ($dataBlocks as $block) {
            if (isset($block[$i])) {
                $out .= str_pad(decbin($block[$i]), 8, '0', STR_PAD_LEFT);
            }
        }
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($ecBlocks as $block) {
            $out .= str_pad(decbin($block[$i]), 8, '0', STR_PAD_LEFT);
        }
    }
    return $out;
}

/** Reed-Solomon over GF(256), the polynomial division the spec sets out. */
function qr_error_correction(array $data, int $count): array
{
    [$exp, $log] = qr_galois_tables();

    // The generator polynomial for `count` codewords.
    $generator = [1];
    for ($i = 0; $i < $count; $i++) {
        $next = array_fill(0, count($generator) + 1, 0);
        foreach ($generator as $index => $coefficient) {
            $next[$index] ^= $coefficient;
            if ($coefficient !== 0) {
                $next[$index + 1] ^= $exp[($log[$coefficient] + $i) % 255];
            }
        }
        $generator = $next;
    }

    $remainder = array_merge($data, array_fill(0, $count, 0));
    for ($i = 0; $i < count($data); $i++) {
        $lead = $remainder[$i];
        if ($lead === 0) {
            continue;
        }
        foreach ($generator as $index => $coefficient) {
            if ($coefficient !== 0) {
                $remainder[$i + $index] ^= $exp[($log[$coefficient] + $log[$lead]) % 255];
            }
        }
    }
    return array_slice($remainder, count($data), $count);
}

/** Antilog and log tables for GF(256) with the QR primitive 0x11d. */
function qr_galois_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }

    $exp = array_fill(0, 256, 0);
    $log = array_fill(0, 256, 0);
    $value = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $value;
        $log[$value] = $i;
        $value <<= 1;
        if ($value & 0x100) {
            $value ^= 0x11d;
        }
    }
    $exp[255] = $exp[0];

    return $tables = [$exp, $log];
}

/**
 * The fixed patterns, and a map of where data may not go.
 *
 * @return array{0: array, 1: array}  [matrix, reserved]
 */
function qr_template(int $size, int $version): array
{
    $matrix   = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));

    $finder = static function (int $top, int $left) use (&$matrix, &$reserved, $size): void {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $py = $top + $y;
                $px = $left + $x;
                if ($py < 0 || $px < 0 || $py >= $size || $px >= $size) {
                    continue;
                }
                $onRing   = ($y === 0 || $y === 6) && $x >= 0 && $x <= 6;
                $onColumn = ($x === 0 || $x === 6) && $y >= 0 && $y <= 6;
                $inCore   = $y >= 2 && $y <= 4 && $x >= 2 && $x <= 4;
                $matrix[$py][$px]   = $onRing || $onColumn || $inCore;
                $reserved[$py][$px] = true;
            }
        }
    };
    $finder(0, 0);
    $finder(0, $size - 7);
    $finder($size - 7, 0);

    // Timing: alternating modules that tell a scanner the module pitch.
    for ($i = 8; $i < $size - 8; $i++) {
        $dark = $i % 2 === 0;
        $matrix[6][$i] = $dark;
        $matrix[$i][6] = $dark;
        $reserved[6][$i] = true;
        $reserved[$i][6] = true;
    }

    foreach (QR_ALIGNMENT[$version] as $cy) {
        foreach (QR_ALIGNMENT[$version] as $cx) {
            // Never on top of a finder.
            if (($cy <= 8 && $cx <= 8)
                || ($cy <= 8 && $cx >= $size - 9)
                || ($cy >= $size - 9 && $cx <= 8)) {
                continue;
            }
            for ($y = -2; $y <= 2; $y++) {
                for ($x = -2; $x <= 2; $x++) {
                    $matrix[$cy + $y][$cx + $x] =
                        max(abs($y), abs($x)) !== 1;
                    $reserved[$cy + $y][$cx + $x] = true;
                }
            }
        }
    }

    // Versions 7 and up carry 18 version bits in two 3x6 blocks.
    if ($version >= 7) {
        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $reserved[$b][$a] = true;
            $reserved[$a][$b] = true;
        }
    }

    // The dark module, always set, and the strips the format bits will use.
    $matrix[$size - 8][8]   = true;
    $reserved[$size - 8][8] = true;
    for ($i = 0; $i < 9; $i++) {
        $reserved[8][$i] = true;
        $reserved[$i][8] = true;
    }
    for ($i = 0; $i < 8; $i++) {
        $reserved[8][$size - 1 - $i] = true;
        $reserved[$size - 1 - $i][8] = true;
    }

    return [$matrix, $reserved];
}

/** Lay the bit stream in, two columns at a time, bottom-right upwards. */
function qr_place_bits(array &$matrix, array $reserved, string $bits, int $size): void
{
    $index = 0;
    $upward = true;

    for ($right = $size - 1; $right > 0; $right -= 2) {
        if ($right === 6) {
            $right--;                      // the vertical timing column
        }
        for ($step = 0; $step < $size; $step++) {
            $y = $upward ? $size - 1 - $step : $step;
            foreach ([$right, $right - 1] as $x) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                $matrix[$y][$x] = ($bits[$index] ?? '0') === '1';
                $index++;
            }
        }
        $upward = !$upward;
    }
}

function qr_apply_mask(array $matrix, array $reserved, int $size, int $mask): array
{
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($reserved[$y][$x]) {
                continue;
            }
            $flip = match ($mask) {
                0 => ($y + $x) % 2 === 0,
                1 => $y % 2 === 0,
                2 => $x % 3 === 0,
                3 => ($y + $x) % 3 === 0,
                4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
                6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
                7 => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            };
            if ($flip) {
                $matrix[$y][$x] = !$matrix[$y][$x];
            }
        }
    }
    return $matrix;
}

/** The 15 format bits, BCH-protected, written into both of their homes. */
function qr_place_format(array &$matrix, int $size, int $mask): void
{
    $value = (0b01 << 3) | $mask;            // 01 is error-correction level L
    $bch   = $value << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($bch & (1 << ($i + 10))) {
            $bch ^= 0b10100110111 << $i;
        }
    }
    $bits = (($value << 10) | $bch) ^ 0b101010000010010;

    for ($i = 0; $i < 15; $i++) {
        $bit = (bool) (($bits >> $i) & 1);

        // Copy one: down the left of the top-left finder, then along the top.
        // The first six run down column 8, not across row 8 - getting that
        // the wrong way round produces a code no scanner will look at twice.
        if ($i < 6) {
            $matrix[$i][8] = $bit;
        } elseif ($i === 6) {
            $matrix[7][8] = $bit;
        } elseif ($i === 7) {
            $matrix[8][8] = $bit;
        } elseif ($i === 8) {
            $matrix[8][7] = $bit;
        } else {
            $matrix[8][14 - $i] = $bit;
        }

        // Copy two: split between the other two finders, so damage to one
        // corner does not take the format information with it.
        if ($i < 8) {
            $matrix[$size - 1 - $i][8] = $bit;
        } else {
            $matrix[8][$size - 15 + $i] = $bit;
        }
    }
}

/**
 * The 18 version bits, which versions 7 and up must carry.
 *
 * Below version 7 a scanner works the version out from the module count, so
 * there is nothing to write; from 7 on it reads these instead, and a code
 * without them is simply not a QR code.
 */
function qr_place_version(array &$matrix, int $size, int $version): void
{
    if ($version < 7) {
        return;
    }

    $remainder = $version;
    for ($i = 0; $i < 12; $i++) {
        $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1f25);
    }
    $bits = ($version << 12) | $remainder;

    for ($i = 0; $i < 18; $i++) {
        $bit = (bool) (($bits >> $i) & 1);
        $a = $size - 11 + ($i % 3);
        $b = intdiv($i, 3);
        $matrix[$b][$a] = $bit;      // above the bottom-left finder
        $matrix[$a][$b] = $bit;      // left of the top-right finder
    }
}

/** The four penalty rules; lower is easier to scan. */
function qr_penalty(array $matrix, int $size): int
{
    $score = 0;

    // 1: runs of five or more of the same colour, each way.
    foreach ([false, true] as $transposed) {
        for ($a = 0; $a < $size; $a++) {
            $run = 1;
            for ($b = 1; $b < $size; $b++) {
                $here = $transposed ? $matrix[$b][$a] : $matrix[$a][$b];
                $prev = $transposed ? $matrix[$b - 1][$a] : $matrix[$a][$b - 1];
                if ($here === $prev) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += $run - 2;
                }
                $run = 1;
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
        }
    }

    // 2: every 2x2 block of one colour.
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $v = $matrix[$y][$x];
            if ($v === $matrix[$y][$x + 1] && $v === $matrix[$y + 1][$x]
                && $v === $matrix[$y + 1][$x + 1]) {
                $score += 3;
            }
        }
    }

    // 3: anything that looks like a finder pattern in the data.
    $patterns = ['10111010000', '00001011101'];
    foreach ([false, true] as $transposed) {
        for ($a = 0; $a < $size; $a++) {
            $line = '';
            for ($b = 0; $b < $size; $b++) {
                $line .= ($transposed ? $matrix[$b][$a] : $matrix[$a][$b]) ? '1' : '0';
            }
            foreach ($patterns as $pattern) {
                $score += 40 * substr_count($line, $pattern);
            }
        }
    }

    // 4: how far the dark/light balance is from even.
    $dark = 0;
    foreach ($matrix as $row) {
        $dark += count(array_filter($row));
    }
    $percent = ($dark * 100) / ($size * $size);
    $score += 10 * (int) (abs($percent - 50) / 5);

    return $score;
}
