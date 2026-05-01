<?php

require_once __DIR__ . '/mm_bootstrap.php';

$mmDbConfig = mm_get_db_config();
$host = $mmDbConfig['host'];
$db = $mmDbConfig['name'];
$user = $mmDbConfig['user'];
$pass = $mmDbConfig['password'];
$charset = $mmDbConfig['charset'];
