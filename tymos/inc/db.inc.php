<?php

require_once __DIR__ . '/../config.inc.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_TYM, DB_PORT);
if ($db->connect_error) {
    $db = null;
} else {
    $db->set_charset('utf8mb4');
}
