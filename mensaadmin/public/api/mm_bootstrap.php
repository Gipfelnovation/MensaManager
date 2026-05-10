<?php
/*
 * MensaManager - Digitale Schulverpflegung
 * Copyright (C) 2026 Lukas Trausch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see [https://www.gnu.org/licenses/](https://www.gnu.org/licenses/).
 */

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
