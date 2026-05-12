<?php
/* This file is part of Jeedom.
 *
 * Callback HTTP appelé par le démon Python pour transmettre
 * les mises à jour de la centrale Risco vers le core Jeedom.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

/* Lecture du body JSON */
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo 'Données invalides';
    die();
}

/* Vérification de la clé API — elle est dans le body JSON envoyé par le démon */
$apikey = isset($data['apikey']) ? $data['apikey'] : init('apikey');
if (!jeedom::apiAccess($apikey, 'risco')) {
    http_response_code(403);
    echo 'Accès refusé';
    die();
}

if (!isset($data['type'])) {
    http_response_code(400);
    echo 'Type manquant';
    die();
}

log::add('risco', 'debug', 'Callback reçu : ' . json_encode($data));

try {
    risco::callbackDaemon($data);
    echo 'OK';
} catch (Exception $e) {
    log::add('risco', 'error', 'Erreur callback : ' . $e->getMessage());
    http_response_code(500);
    echo 'Erreur : ' . $e->getMessage();
}