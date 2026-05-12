<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function risco_install() {
    // Valeur par défaut du port socket
    config::save('socketport', '55010', 'risco');
    // S'assurer que le dossier tmp existe
    @mkdir(jeedom::getTmpFolder('risco'), 0755, true);
    log::add('risco', 'info', 'Plugin Risco installé.');
}

function risco_update() {
    @mkdir(jeedom::getTmpFolder('risco'), 0755, true);
    log::add('risco', 'info', 'Plugin Risco mis à jour.');
}

function risco_remove() {
    /* Arrêt propre du démon avant suppression */
    risco::deamon_stop();
    log::add('risco', 'info', 'Plugin Risco supprimé.');
}
