<?php

/**
 * Czy karta ma wygenerowac swoja ciezka, rozwijana czesc (wykresy, duze zapytania).
 *
 * Po co: karty odswiezaja sie same co DEFAULT_REFRESH (30 s). Bez tego przelacznika
 * PHP renderowal wykresy schowane w display:none przy KAZDYM cyklu — Pi palilo CPU na
 * obrazki, ktorych nikt nie widzi. Zwiniete = brak parametru = zero generowania.
 *
 * Uzycie w karcie:
 *   <?php if (card_expanded()): ?> ...ciezkie wykresy... <?php endif; ?>
 * plus przelacznik w JS: cardToggle('<divId>')
 */
function card_expanded() {
    return !empty($_GET['expand']);
}

function scanPanels() {
    $panels = [];
    foreach (glob(__DIR__ . '/../panel_*/config.php') as $f) {
        $name = substr(basename(dirname($f)), 6);
        $cfg  = include $f;
        $cfg['_name'] = $name;
        $panels[$name] = $cfg;
    }
    uasort($panels, fn($a,$b) => ($a['order']??99) - ($b['order']??99));
    return $panels;
}

function scanTabs($panel) {
    $tabs = [];
    $base = __DIR__ . "/../panel_{$panel}";
    foreach (glob("{$base}/tab_*/") as $d) {
        $name = substr(basename($d), 4);
        $cfg  = [];
        $cfgFile = "{$d}config.php";
        if (file_exists($cfgFile)) $cfg = include $cfgFile;
        // `title` = pelna nazwa. Etykiety zakladek to od 2026-08-30 same ikony, wiec nazwa musi
        // gdzies zostac — trafia w atrybut title (kursor) i aria-label (czytnik).
        $tabs[] = ['name'=>$name, 'label'=>$cfg['label']??$name, 'title'=>$cfg['title']??($cfg['label']??$name), 'order'=>$cfg['order']??99];
    }
    usort($tabs, fn($a,$b) => $a['order'] - $b['order']);
    return $tabs;
}

function scanCards($panel, $tab) {
    $cards = [];
    $base  = __DIR__ . "/../panel_{$panel}/tab_{$tab}";
    foreach (glob("{$base}/card_*.inc.php") as $f) {
        $cards[] = substr(basename($f, '.inc.php'), 5);
    }
    return $cards;
}
