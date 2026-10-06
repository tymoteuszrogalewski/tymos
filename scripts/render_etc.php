#!/usr/bin/env php
<?php
// Renderuje szablony etc/**/*.tpl -> ten sam plik bez .tpl, wstawiajac {{STALA}} z tymos/config.inc.php.
// Wygenerowane pliki sa w .gitignore (zawieraja hasla / IP). Uruchamiac po zmianie configu lub szablonu.
//   php scripts/render_etc.php            - renderuje wszystkie
//   php scripts/render_etc.php --check    - tylko pokazuje, ktore pliki by sie zmienily
require __DIR__ . '/../tymos/config.inc.php';

$check = in_array('--check', $argv);
$root = realpath(__DIR__ . '/../etc');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $tpl = $f->getPathname();
    if (substr($tpl, -4) !== '.tpl') continue;
    $out = substr($tpl, 0, -4);
    $txt = preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($tpl) {
        if (!defined($m[1])) { fwrite(STDERR, "BLAD: brak stalej {$m[1]} w config.inc.php ($tpl)\n"); exit(1); }
        return constant($m[1]);
    }, file_get_contents($tpl));
    $same = is_file($out) && file_get_contents($out) === $txt;
    echo ($same ? 'bez zmian ' : ($check ? 'ZMIENI    ' : 'zapisano  ')) . substr($out, strlen($root) + 1) . "\n";
    if (!$same && !$check) file_put_contents($out, $txt);
}
