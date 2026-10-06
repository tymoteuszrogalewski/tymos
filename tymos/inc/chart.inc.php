<?php
/**
 * inc/chart.inc.php — PHP GD chart module for TymOS TymOS
 *
 * chart_render(array $p): string  →  <img src="data:image/png;base64,...">
 *
 * $p = [
 *   'width'        => 800,
 *   'height'       => 250,
 *   'bg'           => 'transparent',      // lub '#rrggbb'
 *   'grid_color'   => '#2a2a3a',
 *   'label_color'  => '#9a9a9a',
 *   'font_size'    => 9,
 *   'margin'       => ['top'=>10,'right'=>10,'bottom'=>28,'left'=>45],
 *   'categories'   => ['00','01',...,'23'],
 *   'yaxis' => [
 *     ['color'=>'#9a9a9a', 'decimals'=>2, 'min'=>null, 'max'=>null],    // lewa (0)
 *     // 'scale' => mnoznik SAMEJ ETYKIETY (dane zostaja w jednostce bazowej). 0.001 = W na osi jako kW.
 *     ['color'=>'#03a9f4', 'decimals'=>1, 'side'=>'right'],             // prawa (1)
 *   ],
 *   'series' => [
 *     [
 *       'data'         => [float|null|['v'=>float,'mark'=>true], ...],
 *       'type'         => 'bar',           // bar | line | area
 *       'color'        => '#4caf50',
 *       'color_ranges' => [                // kolorowanie słupków/linii wg wartości
 *         ['max'=>0,    'color'=>'#9c27b0'],
 *         ['max'=>0.35, 'color'=>'#e8fce8'],
 *         ...
 *         ['color'=>'#f44336'],            // ostatni = default (brak max)
 *       ],
 *       'mark' => [                        // wygląd zaznaczonych punktów (mark=>true w data)
 *         'color'       => '#007bff',      // kolor bara / kropki na linii
 *         'label'       => true,           // pokaż etykietę z wartością
 *         'label_color' => '#ffffff',
 *         'decimals'    => 2,
 *       ],
 *       'yaxis'        => 0,               // 0=lewa, 1=prawa
 *       'line_width'   => 2,
 *       'fill_alpha'   => 90,              // 0-127 (area)
 *       'null_gap'     => true,            // przerwij linię przy null
 *     ],
 *   ],
 * ]
 */

define('CHART_FONT',      '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf');
define('CHART_FONT_BOLD', '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf');

// Pozycje etykiet jednostek osi Y (label_top / label_bottom)
// Zmiana tutaj działa na wszystkie wykresy naraz
define('CHART_LABEL_OFFSET_X',   3);   // px od krawędzi obszaru wykresu (poziomo)
define('CHART_LABEL_BOTTOM_OY', 14);   // px poniżej dolnej krawędzi obszaru wykresu
define('CHART_LABEL_TOP_OY',    23);   // px powyżej górnej krawędzi obszaru wykresu

function chart_render(array $p): string
{
    $W        = (int)($p['width']       ?? 800);
    $H        = (int)($p['height']      ?? 375);
    $bg       = $p['bg']                ?? 'transparent';
    $series   = $p['series']            ?? [];
    $cats     = $p['categories']        ?? [];
    $yaxesCfg = $p['yaxis']             ?? [[]];
    $fontSize = (float)($p['font_size'] ?? 9);
    $barLabelSize = isset($p['bar_label_size']) ? (float)$p['bar_label_size'] : null;
    $gridHex   = $p['grid_color']       ?? '#ffffff';
    $gridAlpha = (int)($p['grid_alpha'] ?? 115);
    $labelHex   = $p['label_color']     ?? '#cccccc';
    $oxLabelHex = $p['ox_label_color']  ?? '#aaaaaa';

    // czy jest prawa oś?
    $hasRight = false;
    foreach ($series as $s) {
        if (($s['yaxis'] ?? 0) == 1) { $hasRight = true; break; }
    }

    // ile linii tekstu w labels nad słupkami? → dodatkowy margines górny
    $maxLabelLines = 0;
    foreach ($series as $s) {
        if (($s['type'] ?? 'line') === 'bar' && !empty($s['labels'])) {
            foreach ($s['labels'] as $lbl) {
                if ($lbl !== null && $lbl !== '') {
                    $maxLabelLines = max($maxLabelLines, substr_count((string)$lbl, "\n") + 1);
                }
            }
        }
    }
    $barLabelLineH = 22;  // wysokość linii tekstu nad słupkiem
    $labelTopExtra = $maxLabelLines > 0 ? (int)ceil($maxLabelLines / 2) * $barLabelLineH + 8 : 0;

    $mg = array_merge([
        'top'    => 10 + $labelTopExtra,
        'right'  => $hasRight ? 52 : 8,
        'bottom' => 26,
        'left'   => 61,
    ], $p['margin'] ?? []);

    $PX = $mg['left'];
    $PY = $mg['top'];
    $PW = $W - $mg['left'] - $mg['right'];
    $PH = $H - $mg['top'] - $mg['bottom'];

    // --- obraz GD ---
    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, false);
    imagesavealpha($img, true);

    if ($bg === 'transparent') {
        $bgC = imagecolorallocatealpha($img, 0, 0, 0, 127);
    } else {
        [$r,$g,$b] = _c_hex($bg);
        $bgC = imagecolorallocate($img, $r, $g, $b);
    }
    imagefill($img, 0, 0, $bgC);
    imagealphablending($img, true);

    $gridC      = _c_alloc($img, $gridHex, $gridAlpha);
    $labelC     = _c_alloc($img, $labelHex,   0);
    $oxLabelC   = _c_alloc($img, $oxLabelHex, 0);

    // --- pre-compute grouped bar positions (series z 'grouped'=>true dzielą szerokość kolumny) ---
    $groupedPos = []; // idx => ['pos'=>N, 'total'=>N]
    $groupedByAxis = [];
    foreach ($series as $idx => $s) {
        if (($s['type'] ?? 'line') === 'bar' && (!empty($s['grouped']) || isset($s['group']))) {
            $ai = (int)($s['yaxis'] ?? 0);
            $gid = $s['group'] ?? $idx; // group ID: serie z tym samym group = jedna pozycja
            $groupedByAxis[$ai][$gid][] = $idx;
        }
    }
    foreach ($groupedByAxis as $groups) {
        $total = count($groups);
        $pos = 0;
        foreach ($groups as $gid => $idxs) {
            foreach ($idxs as $idx) {
                $groupedPos[$idx] = ['pos' => $pos, 'total' => $total];
            }
            $pos++;
        }
    }

    // --- pre-compute sumy stackowanych barów (dla zakresu Y) ---
    $stackSums = [];
    foreach ($series as $s) {
        if (($s['type'] ?? 'line') === 'bar' && !empty($s['stack'])) {
            $ai  = (int)($s['yaxis'] ?? 0);
            $gid = $s['group'] ?? '_default';
            foreach (($s['data'] ?? []) as $i => $raw) {
                $dp = _c_dp($raw);
                if ($dp['v'] !== null) {
                    $stackSums[$ai][$gid][$i] = ($stackSums[$ai][$gid][$i] ?? 0.0) + $dp['v'];
                }
            }
        }
    }

    // --- zakresy Y ---
    $yR = [];
    for ($ai = 0; $ai < count($yaxesCfg); $ai++) {
        $vals = [];
        foreach ($series as $s) {
            if (($s['yaxis'] ?? 0) == $ai) {
                // stacked bary — wkład liczony przez sumy kategorii, nie pojedyncze wartości
                if (($s['type'] ?? 'line') === 'bar' && !empty($s['stack'])) continue;
                foreach (($s['data'] ?? []) as $raw) {
                    $dp = _c_dp($raw);
                    if ($dp['v'] !== null) $vals[] = $dp['v'];
                }
            }
        }
        // dodaj sumy stacków dla tej osi
        foreach (($stackSums[$ai] ?? []) as $gid => $sums) {
            foreach ($sums as $sum) {
                $vals[] = $sum;
            }
        }
        $cfg  = $yaxesCfg[$ai] ?? [];
        $dmin = count($vals) ? min($vals) : 0;
        $dmax = count($vals) ? max($vals) : 1;
        $ymin = $cfg['min'] ?? (!empty($cfg['tight']) ? $dmin : min(0.0, $dmin));
        $ymax = $cfg['max'] ?? $dmax;              // domyślnie: max z danych
        if (isset($cfg['soft_min'])) $ymin = min($ymin, $cfg['soft_min']);
        if (isset($cfg['soft_max'])) $ymax = max($ymax, $cfg['soft_max']);
        if ($ymin == $ymax) { $ymin -= 0.5; $ymax += 0.5; }
        $scaleMin = (float)$ymin;
        $scaleMax = (float)$ymax;
        if ($scaleMin == $scaleMax) { $scaleMin = 0; $scaleMax = 1; }
        $nGrids = (int)($cfg['grids'] ?? 6);
        $ticks  = _c_ticks($scaleMin, $scaleMax, $nGrids + 1);
        $yR[$ai] = [
            'min'      => $scaleMin,
            'max'      => $scaleMax,
            'ticks'    => $ticks,
            'color'    => $cfg['color']    ?? $labelHex,
            'decimals' => $cfg['decimals'] ?? 2,
            'scale'    => (float)($cfg['scale'] ?? 1),
            'side'     => $cfg['side']     ?? ($ai == 0 ? 'left' : 'right'),
            'hidden'   => !empty($cfg['hidden']),
        ];
    }

    // konwertery
    $y2px = function(float $v, int $ai) use ($PY, $PH, $yR): int {
        $r = $yR[$ai];
        $span = $r['max'] - $r['min'];
        $frac = $span > 0 ? ($v - $r['min']) / $span : 0.5;
        return (int)round($PY + $PH - $frac * $PH);
    };

    $n    = max(count($cats), 1);
    $colW = $PW / $n;
    $x2px = function(int $i, float $frac = 0.5) use ($PX, $colW): int {
        return (int)round($PX + ($i + $frac) * $colW);
    };

    $fontFile     = file_exists(CHART_FONT)      ? CHART_FONT      : null;
    $fontFileBold = file_exists(CHART_FONT_BOLD) ? CHART_FONT_BOLD : $fontFile;
    $fontSizeAxis = (float)($p['font_size_axis'] ?? round($fontSize * 1.8));

    // --- siatka ---
    if (empty($p['no_grid'])) {
        foreach ($yR[0]['ticks'] as $tv) {
            $py = $y2px((float)$tv, 0);
            if ($py < $PY || $py > $PY + $PH) continue;
            imageline($img, $PX, $py, $PX + $PW, $py, $gridC);
        }
    }

    // --- akumulator dla stackowanych barów: [ai][catIdx] => baza następnego segmentu ---
    $stackBase = [];

    // --- serie: kolejność rysowania area → bar → line ---
    $byType = ['area' => [], 'bar' => [], 'line' => []];
    foreach ($series as $i => $s) {
        $byType[$s['type'] ?? 'line'][] = $i;
    }
    $deferredBarLabels = [];

    foreach (['area', 'bar', 'line'] as $type) {
        foreach ($byType[$type] as $idx) {
            $s    = $series[$idx];
            $data = $s['data'] ?? [];
            $ai   = (int)($s['yaxis'] ?? 0);
            $lw   = (int)($s['line_width'] ?? 3);

            // ---- BAR ----
            if ($type === 'bar') {
                $isGrouped = isset($groupedPos[$idx]);
                if ($isGrouped) {
                    $gp  = $groupedPos[$idx];
                    $gap = max(1, (int)round($colW * 0.08));
                    $totalBw = (int)round($colW - $gap * 2);
                    $bw  = (int)floor($totalBw / $gp['total']);
                } else {
                    $gapPct = (float)($s['bar_gap_pct'] ?? 0.20);
                    $gap = max(0, (int)round($colW * $gapPct));
                    $bw  = max(1, (int)round($colW - $gap * 2));
                }
                $zeroY   = min($PY + $PH, $y2px(0.0, $ai));
                $markCfg = $s['mark'] ?? null;
                $isStack = !empty($s['stack']);
                $tightFit = !$isGrouped && (float)($s['bar_gap_pct'] ?? 0.20) === 0.0;
                foreach ($data as $i => $raw) {
                    $dp = _c_dp($raw);
                    if ($dp['v'] === null) continue;
                    $v  = $dp['v'];
                    if ($isGrouped) {
                        $x1 = $PX + (int)round($i * $colW) + $gap + $gp['pos'] * $bw;
                        $x2 = $x1 + $bw - 1;
                    } elseif ($tightFit) {
                        $x1 = $PX + (int)round($i * $colW);
                        $x2 = $PX + (int)round(($i + 1) * $colW) - 1;
                    } else {
                        $x1 = $PX + (int)round($i * $colW) + $gap;
                        $x2 = $x1 + $bw - 1;
                    }
                    $c  = $dp['mark'] && $markCfg && isset($markCfg['color'])
                        ? _c_alloc($img, $markCfg['color'])
                        : _c_series_color($img, $v, $s);
                    if ($isStack) {
                        $gid   = $s['group'] ?? '_default';
                        $base  = $stackBase[$ai][$gid][$i] ?? 0.0;
                        $top   = $base + $v;
                        $topY  = $y2px($top, $ai);
                        $baseY = $y2px($base, $ai);
                        $stackBase[$ai][$gid][$i] = $top;
                        imagefilledrectangle($img, $x1, $topY, $x2, $baseY, $c);
                        if ($dp['mark'] && $markCfg && !empty($markCfg['label'])) {
                            $dec   = (int)($markCfg['decimals'] ?? 2);
                            $lbl   = number_format($v, $dec, '.', ' ');
                            $lblC  = isset($markCfg['label_color']) ? _c_alloc($img, $markCfg['label_color']) : $labelC;
                            $deferredBarLabels[] = ['type' => 'mark', 'text' => $lbl, 'x' => (int)(($x1 + $x2) / 2), 'y' => $topY - 6, 'size' => $fontSize + 1, 'color' => $lblC, 'font' => $fontFileBold];
                        }
                        if (!empty($s['labels']) && isset($s['labels'][$i]) && (string)$s['labels'][$i] !== '') {
                            $lc     = _c_alloc($img, $s['label_color'] ?? '#cccccc');
                            $lines  = explode("\n", (string)$s['labels'][$i]);
                            $nL     = count($lines);
                            $fszLbl = $barLabelSize ?? ($fontSize + 1);
                            $lineH  = $barLabelLineH;
                            $cx     = (int)(($x1 + $x2) / 2);
                            $baseYl = $topY - (int)(($nL - 1) * $lineH / 2);
                            $deferredBarLabels[] = ['type' => 'label', 'lines' => $lines, 'cx' => $cx, 'baseY' => $baseYl, 'fszLbl' => $fszLbl, 'lineH' => $lineH, 'lc' => $lc];
                        }
                    } else {
                        $vy = $y2px($v, $ai);
                        if ($v >= 0) {
                            imagefilledrectangle($img, $x1, $vy, $x2, $zeroY, $c);
                        } else {
                            imagefilledrectangle($img, $x1, $zeroY, $x2, $vy, $c);
                        }
                        if ($dp['mark'] && $markCfg && !empty($markCfg['label'])) {
                            $dec   = (int)($markCfg['decimals'] ?? 2);
                            $lbl   = number_format($v, $dec, '.', ' ');
                            $lblC  = isset($markCfg['label_color']) ? _c_alloc($img, $markCfg['label_color']) : $labelC;
                            $markY = min($vy, $zeroY) - 6;
                            $deferredBarLabels[] = ['type' => 'mark', 'text' => $lbl, 'x' => (int)(($x1 + $x2) / 2), 'y' => $markY, 'size' => $fontSize + 1, 'color' => $lblC, 'font' => $fontFileBold];
                        }
                        if (!empty($s['labels']) && isset($s['labels'][$i]) && (string)$s['labels'][$i] !== '') {
                            $lc     = _c_alloc($img, $s['label_color'] ?? '#cccccc');
                            $topY   = min($vy, $zeroY);
                            $lines  = explode("\n", (string)$s['labels'][$i]);
                            $nL     = count($lines);
                            $fszLbl = $barLabelSize ?? ($fontSize + 1);
                            $lineH  = $barLabelLineH;
                            $cx     = (int)(($x1 + $x2) / 2);
                            $baseYl = $topY - (int)(($nL - 1) * $lineH / 2);
                            $deferredBarLabels[] = ['type' => 'label', 'lines' => $lines, 'cx' => $cx, 'baseY' => $baseYl, 'fszLbl' => $fszLbl, 'lineH' => $lineH, 'lc' => $lc];
                        }
                    }
                }
            }

            // ---- LINE / AREA ----
            if ($type === 'line' || $type === 'area') {
                $markCfg  = $s['mark'] ?? null;
                // x_frac: 0..1, pozycja punktu w kolumnie kategorii.
                // Default: jesli wykres ma grouped bars → wyrownanie do pierwszej grupy
                //          (np. 2 grupy → 0.25 = srodek lewej grupy). Inaczej 0.5 (srodek kolumny).
                // Override przez explicit 'x_frac' w serii.
                if (isset($s['x_frac'])) {
                    $xFrac = (float)$s['x_frac'];
                } elseif (!empty($groupedPos)) {
                    $firstGp = reset($groupedPos);
                    $xFrac = 0.5 / max(1, (int)$firstGp['total']);
                } else {
                    $xFrac = 0.5;
                }
                // zbierz punkty (pomijamy null); pt = [x, y, v, orig_index, is_mark]
                // null_gap: true (default) = przerwa przy null; false = linia laczy znane punkty pomijajac null
                $nullGap = $s['null_gap'] ?? true;
                $segments = [[]];
                foreach ($data as $i => $raw) {
                    $dp = _c_dp($raw);
                    if ($dp['v'] === null) {
                        if ($nullGap && !empty(end($segments))) $segments[] = [];
                        continue;
                    }
                    $ypx = $y2px($dp['v'], $ai);
                    // Pomijaj punkty poza obszarem wykresu (Y)
                    if ($ypx < $PY - 2 || $ypx > $PY + $PH + 2) {
                        if ($nullGap && !empty(end($segments))) $segments[] = [];
                        continue;
                    }
                    $seg = array_key_last($segments);
                    $segments[$seg][] = [$x2px($i, $xFrac), $ypx, $dp['v'], $i, $dp['mark']];
                }

                foreach ($segments as $pts) {
                    // izolowany punkt — rysuj pozioma kreske na szerokosc bara
                    if (count($pts) === 1) {
                        $pt = $pts[0];
                        $halfBar = max(2, (int)round($colW * 0.3));
                        $lineC = _c_alloc($img, $s['color'] ?? '#888888');
                        imagesetthickness($img, $lw);
                        imageline($img, $pt[0] - $halfBar, $pt[1], $pt[0] + $halfBar, $pt[1], $lineC);
                        imagesetthickness($img, 1);
                        continue;
                    }
                    if (count($pts) < 2) continue;

                    if ($type === 'area') {
                        $alpha = (int)($s['fill_alpha'] ?? 90);
                        $zeroY = min($PY + $PH, $y2px(0.0, $ai));
                        $poly  = [];
                        foreach ($pts as $pt) { $poly[] = $pt[0]; $poly[] = $pt[1]; }
                        $poly[] = $pts[count($pts)-1][0]; $poly[] = $zeroY;
                        $poly[] = $pts[0][0];              $poly[] = $zeroY;
                        [$r,$g,$b] = _c_hex($s['color'] ?? '#03a9f4');
                        $ac = imagecolorallocatealpha($img, $r, $g, $b, $alpha);
                        imagefilledpolygon($img, $poly, $ac);
                    }

                    $dashLen = !empty($s['dash']) ? max(1, (int)$s['dash']) : 0;
                    if ($lw > 0) {
                    $gapLen = !empty($s['gap']) ? max(1, (int)$s['gap']) : $dashLen;
                    if ($dashLen > 0) {
                        $lineC  = _c_alloc($img, $s['color'] ?? '#888888');
                        imagesetthickness($img, $lw);
                        // Dash phase runs along the WHOLE polyline, not per segment.
                        // With dense data one segment is shorter than a dash cycle, so
                        // resetting per segment produced a solid line (or nothing at all).
                        $cycle = $dashLen + $gapLen;
                        $phase = 0.0;
                        for ($i = 0; $i < count($pts) - 1; $i++) {
                            $x0 = $pts[$i][0]; $y0 = $pts[$i][1];
                            $x1 = $pts[$i+1][0]; $y1 = $pts[$i+1][1];
                            $dx = $x1 - $x0; $dy = $y1 - $y0;
                            $len = sqrt($dx*$dx + $dy*$dy);
                            if ($len <= 0) continue;
                            $ux = $dx / $len; $uy = $dy / $len;
                            $pos = 0.0;
                            while ($pos < $len) {
                                $inCycle = fmod($phase, $cycle);
                                if ($inCycle < $dashLen) {
                                    $step = min($dashLen - $inCycle, $len - $pos);
                                    imageline($img,
                                        (int)round($x0 + $ux * $pos), (int)round($y0 + $uy * $pos),
                                        (int)round($x0 + $ux * ($pos + $step)), (int)round($y0 + $uy * ($pos + $step)), $lineC);
                                } else {
                                    $step = min($cycle - $inCycle, $len - $pos);
                                }
                                $pos   += $step;
                                $phase += $step;
                            }
                        }
                        imagesetthickness($img, 1);
                    } else {
                        for ($i = 0; $i < count($pts) - 1; $i++) {
                            $c  = _c_series_color($img, $pts[$i][2], $s);
                            $sw = _c_series_width($pts[$i][2], $s, $lw);
                            imagesetthickness($img, $sw);
                            imageline($img, $pts[$i][0], $pts[$i][1], $pts[$i+1][0], $pts[$i+1][1], $c);
                        }
                        imagesetthickness($img, 1);
                    }
                    } // $lw > 0

                    // mark: kropka + etykieta na linii
                    if ($markCfg) {
                        foreach ($pts as $pt) {
                            if (!$pt[4]) continue;
                            $dotC = isset($markCfg['color']) ? _c_alloc($img, $markCfg['color']) : $labelC;
                            $r = 4;
                            imagefilledellipse($img, $pt[0], $pt[1], $r * 2, $r * 2, $dotC);
                            if (!empty($markCfg['label'])) {
                                $dec  = (int)($markCfg['decimals'] ?? 2);
                                $lbl  = number_format($pt[2], $dec, '.', ' ');
                                $lblC = isset($markCfg['label_color']) ? _c_alloc($img, $markCfg['label_color']) : $labelC;
                                _c_text($img, $lbl, $pt[0], $pt[1] - 10, $fontSize + 1, $lblC, $fontFileBold, 'center');
                            }
                        }
                    }

                    // labels per-punkt — przez deferred pipeline (te same tlo/border co bar labels)
                    if (!empty($s['labels'])) {
                        $lc     = _c_alloc($img, $s['label_color'] ?? '#cccccc');
                        $fszLbl = $barLabelSize ?? ($fontSize + 1);
                        $lineH  = $barLabelLineH;
                        foreach ($pts as $pt) {
                            $oi = $pt[3];
                            if (!isset($s['labels'][$oi]) || (string)$s['labels'][$oi] === '') continue;
                            $lines  = explode("\n", (string)$s['labels'][$oi]);
                            $nL     = count($lines);
                            $cx     = $pt[0];
                            // baseY = top text center; przesuwamy o ~14px wyzej zeby ramka byla
                            // nad punktem linii (nie nakladala sie na grubsza linie)
                            $baseY  = $pt[1] - 14 - (int)(($nL - 1) * $lineH / 2);
                            $deferredBarLabels[] = ['type' => 'label', 'lines' => $lines, 'cx' => $cx, 'baseY' => $baseY, 'fszLbl' => $fszLbl, 'lineH' => $lineH, 'lc' => $lc];
                        }
                    }
                }
            }
        }
    }

    // --- deferred bar labels (rysowane po wszystkich barach, żeby nie były zasłaniane) ---
    foreach ($deferredBarLabels as $dl) {
        if ($dl['type'] === 'mark') {
            _c_text($img, $dl['text'], $dl['x'], $dl['y'], $dl['size'], $dl['color'], $dl['font'], 'center');
        } elseif ($dl['type'] === 'label') {
            $nL = count($dl['lines']);
            $padX = 6; $padY = 4;
            if ($fontFileBold) {
                $maxTW = 0; $maxTH = 0;
                foreach ($dl['lines'] as $line) {
                    $bb    = imagettfbbox($dl['fszLbl'], 0, $fontFileBold, $line);
                    $maxTW = max($maxTW, abs($bb[2] - $bb[0]));
                    $maxTH = max($maxTH, abs($bb[5] - $bb[3]));
                }
                $bgC  = imagecolorallocatealpha($img, 0, 0, 0, 40);
                $brdC = imagecolorallocatealpha($img, 180, 180, 180, 90);
                $rx1  = $dl['cx']  - (int)($maxTW / 2) - $padX;
                $ry1  = $dl['baseY'] - (int)($maxTH / 2) - $padY;
                $rx2  = $dl['cx']  + (int)($maxTW / 2) + $padX;
                $ry2  = $dl['baseY'] + ($nL - 1) * $dl['lineH'] + (int)($maxTH / 2) + $padY;
                imagefilledrectangle($img, $rx1, $ry1, $rx2, $ry2, $bgC);
                imagerectangle($img, $rx1, $ry1, $rx2, $ry2, $brdC);
            }
            foreach ($dl['lines'] as $li => $line) {
                _c_text($img, $line, $dl['cx'], $dl['baseY'] + $li * $dl['lineH'], $dl['fszLbl'], $dl['lc'], $fontFileBold, 'center');
            }
        }
    }

    // --- annotacje pionowe ---
    foreach ($p['annotations'] ?? [] as $ann) {
        if (!isset($ann['x'])) continue;
        $xiList  = [];
        $prevCat = null;
        foreach ($cats as $xi => $cat) {
            if ((string)$cat === (string)$ann['x'] && $prevCat !== (string)$ann['x']) $xiList[] = $xi;
            $prevCat = (string)$cat;
        }
        $c    = _c_alloc($img, $ann['color'] ?? '#777777');
        $dash = (int)($ann['dash'] ?? 5);
        $gap  = (int)($ann['gap']  ?? 4);
        foreach ($xiList as $xi) {
            $xpx = $PX + (int)round($xi * $colW);
            for ($y = $PY; $y <= $PY + $PH; $y += $dash + $gap) {
                imageline($img, $xpx, $y, $xpx, min($y + $dash - 1, $PY + $PH), $c);
            }
        }
    }

    // --- annotacje poziome ---
    foreach ($p['h_annotations'] ?? [] as $ann) {
        if (!isset($ann['y']) || !isset($ann['yaxis'])) continue;
        $ai   = (int)$ann['yaxis'];
        $py   = $y2px((float)$ann['y'], $ai);
        if ($py < $PY || $py > $PY + $PH) continue;
        $c    = _c_alloc($img, $ann['color'] ?? '#777777');
        $dash = (int)($ann['dash'] ?? 5);
        $gap  = (int)($ann['gap']  ?? 4);
        for ($x = $PX; $x <= $PX + $PW; $x += $dash + $gap) {
            imageline($img, $x, $py, min($x + $dash - 1, $PX + $PW), $py, $c);
        }
    }

    // --- etykiety X ---
    // Grupuj kolejne identyczne wartości → label w centrum grupy, min 16px odstęp
    $lblGroups = [];
    $prevCatLbl = "\x00";
    foreach ($cats as $i => $lbl) {
        if ((string)$lbl !== $prevCatLbl) {
            $lblGroups[] = ['label' => (string)$lbl, 'start' => $i, 'end' => $i];
            $prevCatLbl = (string)$lbl;
        } else {
            $lblGroups[count($lblGroups) - 1]['end'] = $i;
        }
    }
    $minLblPx = (int)($p['label_min_px'] ?? 16);
    $prevLblPx = -999;
    foreach ($lblGroups as $g) {
        if ($g['label'] === '') continue;
        $cx = $x2px((int)(($g['start'] + $g['end']) / 2));
        if ($cx - $prevLblPx < $minLblPx) continue;
        $prevLblPx = $cx;
        _c_text($img, $g['label'], $cx, $PY + $PH + 16, $fontSizeAxis, $oxLabelC, $fontFileBold, 'center');
    }

    // --- etykiety Y ---
    for ($ai = 0; $ai < count($yR); $ai++) {
        $yr = $yR[$ai];
        if (empty($yr['ticks'])) continue;
        if (!empty($yr['hidden'])) continue;
        $yc = _c_alloc($img, $yr['color']);
        $fontSizeY = $fontSizeAxis * 0.78;
        foreach ($yr['ticks'] as $tv) {
            $py = $y2px((float)$tv, $ai) - 4;
            if ($py < $PY - 4 || $py > $PY + $PH + 4) continue;
            $lbl = number_format((float)$tv * $yr['scale'], (int)$yr['decimals'], '.', ' ');
            if ($yr['side'] === 'right') {
                _c_text($img, $lbl, $PX + $PW + 3, $py, $fontSizeY, $yc, $fontFile, 'left');
            } else {
                _c_text($img, $lbl, $PX - 3, $py, $fontSizeY, $yc, $fontFileBold, 'right');
            }
        }
    }

    // --- etykiety jednostek osi Y (oy_label_above / oy_label_below) ---
    // Użycie w yaxis: 'oy_label_above' => ['text'=>'kWh', 'color'=>'#2288ee']
    //                 'oy_label_below' => ['text'=>'PLN', 'color'=>'#81c784']
    for ($ai = 0; $ai < count($yaxesCfg); $ai++) {
        $cfg     = $yaxesCfg[$ai] ?? [];
        $isRight = (($yR[$ai]['side'] ?? 'left') === 'right');
        $fontSizeAnnot = $fontSizeAxis * 0.78;
        $fontAnnot = $isRight ? $fontFile : $fontFileBold;
        if (!empty($cfg['oy_label_below'])) {
            $lbl = $cfg['oy_label_below'];
            $lc  = _c_alloc($img, $lbl['color'] ?? $yR[$ai]['color']);
            $ox  = (int)($lbl['offset_x'] ?? 0);
            if ($isRight) {
                _c_text($img, $lbl['text'], $PX + $PW + CHART_LABEL_OFFSET_X + $ox, $PY + $PH + CHART_LABEL_BOTTOM_OY, $fontSizeAnnot, $lc, $fontAnnot, 'left');
            } else {
                _c_text($img, $lbl['text'], $PX - CHART_LABEL_OFFSET_X + $ox, $PY + $PH + CHART_LABEL_BOTTOM_OY, $fontSizeAnnot, $lc, $fontAnnot, 'right');
            }
        }
        if (!empty($cfg['oy_label_above'])) {
            $lbl = $cfg['oy_label_above'];
            $lc  = _c_alloc($img, $lbl['color'] ?? $yR[$ai]['color']);
            $ox  = (int)($lbl['offset_x'] ?? 0);
            if ($isRight) {
                _c_text($img, $lbl['text'], $PX + $PW + CHART_LABEL_OFFSET_X + $ox, $PY - CHART_LABEL_TOP_OY, $fontSizeAnnot, $lc, $fontAnnot, 'left');
            } else {
                _c_text($img, $lbl['text'], $PX - CHART_LABEL_OFFSET_X + $ox, $PY - CHART_LABEL_TOP_OY, $fontSizeAnnot, $lc, $fontAnnot, 'right');
            }
        }
    }

    // --- output ---
    ob_start();
    imagepng($img);
    imagedestroy($img);
    $b64 = base64_encode(ob_get_clean());
    $html = '<div style="aspect-ratio:' . $W . '/' . $H . ';max-width:' . $W . 'px;width:100%;overflow:hidden;">'
          . '<img src="data:image/png;base64,' . $b64 . '" style="width:100%;height:100%;display:block;">'
          . '</div>';
    if (!empty($p['legend'])) {
        $items = [];
        foreach ($p['legend'] as $item) {
            $type  = $item['type'] ?? 'area';
            if ($type === 'newline') {
                $items[] = '<span style="flex-basis:100%;height:0;"></span>';
                continue;
            }
            $color = htmlspecialchars($item['color'] ?? '#888888');
            $label = htmlspecialchars($item['label'] ?? '');
            if ($type === 'line' || $type === 'line_thick') {
                $lw = isset($item['line_width']) ? (float)$item['line_width']
                    : ($type === 'line_thick' ? 3 : 1.5);
                // Match the on-chart dash pattern in the legend swatch when series is dashed.
                $dashAttr = '';
                if (!empty($item['dash'])) {
                    $dl = max(1, (int)$item['dash']);
                    $gl = !empty($item['gap']) ? max(1, (int)$item['gap']) : $dl;
                    $dashAttr = ' stroke-dasharray="' . $dl . ',' . $gl . '"';
                }
                $swatch = '<svg width="20" height="10" style="flex-shrink:0"><line x1="0" y1="5" x2="20" y2="5" stroke="' . $color . '" stroke-width="' . $lw . '"' . $dashAttr . '/></svg>';
            } else {
                $fillOpacity = (float)($item['fill_opacity'] ?? 1.0);
                [$cr,$cg,$cb] = _c_hex($item['color'] ?? '#888888');
                $border = !isset($item['border']) || $item['border'] !== false
                    ? 'border:1.5px solid ' . $color . ';' : '';
                $swatch = '<span style="flex-shrink:0;width:14px;height:10px;'
                        . 'background:rgba(' . $cr . ',' . $cg . ',' . $cb . ',' . $fillOpacity . ');'
                        . $border
                        . 'border-radius:2px;display:inline-block;box-sizing:border-box;"></span>';
            }
            $items[] = '<span style="display:inline-flex;align-items:center;gap:4px;">'
                     . $swatch
                     . '<span style="color:#9a9a9a;font-size:10px;white-space:nowrap;">' . $label . '</span>'
                     . '</span>';
        }
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:6px 14px;padding:4px 2px 0;">'
               . implode('', $items)
               . '</div>';
    }
    return $html;
}

// ==================== helpers ====================

function _c_dp($raw): array
{
    if (is_array($raw)) {
        $v = isset($raw['v']) && is_numeric($raw['v']) ? (float)$raw['v'] : null;
        return ['v' => $v, 'mark' => !empty($raw['mark'])];
    }
    return ['v' => is_numeric($raw) ? (float)$raw : null, 'mark' => false];
}

function _c_ticks(float $min, float $max, int $n = 5): array
{
    if ($max <= $min) $max = $min + 1;
    $range = $max - $min;
    $raw   = $range / ($n - 1);
    $mag   = pow(10, floor(log10($raw)));
    $step  = $raw;
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $mag >= $raw) { $step = $m * $mag; break; }
    }
    $lo    = floor($min / $step) * $step;
    $hi    = ceil($max  / $step) * $step;
    $ticks = [];
    for ($v = $lo; $v <= $hi + $step * 0.001; $v += $step) {
        $ticks[] = round($v, 10);
    }
    return $ticks;
}

function _c_hex(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return [(int)hexdec(substr($hex,0,2)), (int)hexdec(substr($hex,2,2)), (int)hexdec(substr($hex,4,2))];
}

function _c_alloc($img, string $hex, int $alpha = 0)
{
    [$r,$g,$b] = _c_hex($hex);
    return $alpha > 0
        ? imagecolorallocatealpha($img, $r, $g, $b, $alpha)
        : imagecolorallocate($img, $r, $g, $b);
}

function _c_series_width(float $v, array $s, int $default): int
{
    if (!empty($s['width_ranges'])) {
        foreach ($s['width_ranges'] as $wr) {
            if (!array_key_exists('max', $wr) || $v <= $wr['max']) {
                return (int)$wr['width'];
            }
        }
    }
    return $default;
}

function _c_series_color($img, float $v, array $s)
{
    $alpha = (int)($s['color_alpha'] ?? 0);
    if (!empty($s['color_ranges'])) {
        foreach ($s['color_ranges'] as $cr) {
            if (!array_key_exists('max', $cr) || $v <= $cr['max']) {
                return _c_alloc($img, $cr['color'], $alpha);
            }
        }
    }
    return _c_alloc($img, $s['color'] ?? '#888888', $alpha);
}

function _c_text($img, string $txt, int $x, int $y, float $size, $color, ?string $font, string $align = 'left'): void
{
    if ($font) {
        $bbox = imagettfbbox($size, 0, $font, $txt);
        $tw   = abs($bbox[2] - $bbox[0]);
        $th   = abs($bbox[5] - $bbox[3]);
        $ox   = match($align) {
            'center' => (int)($tw / 2),
            'right'  => $tw,
            default  => 0,
        };
        imagettftext($img, $size, 0, $x - $ox, $y + (int)($th / 2), $color, $font, $txt);
    } else {
        $cw = imagefontwidth(1);
        $ox = match($align) {
            'center' => (int)(strlen($txt) * $cw / 2),
            'right'  => strlen($txt) * $cw,
            default  => 0,
        };
        imagestring($img, 1, $x - $ox, $y - 3, $txt, $color);
    }
}
