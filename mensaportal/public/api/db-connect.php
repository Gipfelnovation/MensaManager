<?php

require_once __DIR__ . '/../../../shared/php/mm_security.php';

$mmDbConfig = mm_get_db_config();
$host = $mmDbConfig['host'];
$db = $mmDbConfig['name'];
$user = $mmDbConfig['user'];
$pass = $mmDbConfig['password'];
$charset = $mmDbConfig['charset'];
