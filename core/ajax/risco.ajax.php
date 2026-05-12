<?php
/* This file is part of Jeedom.
 *
 * Point d'entrée AJAX du plugin Risco.
 * Appelé depuis desktop/php/risco.php via $.ajax POST.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception('{{401 - Accès non autorisé}}');
    }

    $action = init('action');
    $id     = (int)init('id');

    /* ── recreate_cmds ─────────────────────────────────────────── */
    if ($action === 'recreate_cmds') {
        $eq = risco::byId($id);
        if (!is_object($eq)) {
            throw new Exception('Equipement introuvable (id=' . $id . ')');
        }
        $eq->_createBaseCommands();
        $zonesStr = $eq->getConfiguration('zones_list', '');
        if ($zonesStr !== '' && $zonesStr !== 'auto') {
            $zone_ids = array_filter(array_map('intval', explode(',', $zonesStr)));
            $eq->createZoneCommands($zone_ids);
        }
        ajax::success();
    }

    /* ── sync_zones : demande au démon de renvoyer discovered ──── */
    if ($action === 'sync_zones') {
        $eq = risco::byId($id);
        if (!is_object($eq)) {
            throw new Exception('Equipement introuvable (id=' . $id . ')');
        }
        $port    = config::byKey('socketport', 'risco', '55010');
        $message = json_encode([
            'apikey'     => jeedom::getApiKey('risco'),
            'eqLogic_id' => $id,
            'action'     => 'sync_zones',
        ]) . "\n";
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new Exception('Impossible de créer le socket');
        }
        if (!@socket_connect($socket, '127.0.0.1', (int)$port)) {
            throw new Exception('Démon non joignable sur le port ' . $port . '. Vérifiez qu\'il est démarré.');
        }
        socket_write($socket, $message, strlen($message));
        socket_close($socket);
        ajax::success();
    }

    throw new Exception('Action inconnue : ' . $action);

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}