<?php
// TymOS wspolne helpery PHP:
// - fuzzy search z polskim transliteration (_depolish, _sqlDepolish, _fuzzyLike)
// - tymos_notify_reload — sygnal do UI przez settings['tymos_reload']

function _depolish($s) {
    return strtr($s, [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z',
    ]);
}

function _sqlDepolish($col) {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
    $expr = "LOWER({$col})";
    foreach ($map as $from => $to) {
        $expr = "REPLACE({$expr}, '{$from}', '{$to}')";
    }
    return $expr;
}

function _fuzzyLike($db, $word, $columns) {
    $w = $db->real_escape_string($word);
    $wd = $db->real_escape_string(strtolower(_depolish($word)));
    $parts = [];
    foreach ($columns as $col) {
        $parts[] = "{$col} LIKE '%{$w}%'";
        $parts[] = _sqlDepolish($col) . " LIKE '%{$wd}%'";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function tymos_notify_reload($db) {
    $db->query("UPDATE settings SET v='" . time() . "' WHERE k='tymos_reload'");
}
