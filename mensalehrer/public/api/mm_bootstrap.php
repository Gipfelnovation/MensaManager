<?php

$mmSearchDirectory = __DIR__;

for ($mmDepth = 0; $mmDepth < 6; $mmDepth++) {
    $mmCandidate = $mmSearchDirectory . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'mm_security.php';
    if (is_file($mmCandidate)) {
        require_once $mmCandidate;
        return;
    }

    $mmParentDirectory = dirname($mmSearchDirectory);
    if ($mmParentDirectory === $mmSearchDirectory) {
        break;
    }

    $mmSearchDirectory = $mmParentDirectory;
}

throw new RuntimeException('shared/php/mm_security.php konnte nicht gefunden werden.');
