<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

/* ============================================================
   Classe principale du plugin Risco
   ============================================================ */
class risco extends eqLogic {

    /* ----------------------------------------------------------
       Chemins communs
    ---------------------------------------------------------- */
    private static function _resourcesPath() {
        return realpath(dirname(__FILE__) . '/../../resources');
    }

    private static function _venvPython() {
        return self::_resourcesPath() . '/venv/bin/python3';
    }

    private static function _pidFile() {
        return jeedom::getTmpFolder('risco') . '/deamon.pid';
    }

    private static function _socketPort() {
        return config::byKey('socketport', 'risco', '55010');
    }

    /* ============================================================
       DÉPENDANCES
    ============================================================ */
    public static function dependancy_info() {
        $return = array();
        $return['log']           = log::getPathToLog('risco_dep');
        $return['progress_file'] = jeedom::getTmpFolder('risco') . '/dependance';
        $return['state']         = 'nok';

        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
            return $return;
        }

        $venv = self::_venvPython();
        if (file_exists($venv)) {
            $check = shell_exec($venv . ' -c "import pyrisco" 2>&1');
            if (trim($check) === '') {
                $return['state'] = 'ok';
            }
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove('risco_dep');
        return array(
            'script' => self::_resourcesPath() . '/install_dep.sh ' . jeedom::getTmpFolder('risco') . '/dependance',
            'log'    => log::getPathToLog('risco_dep'),
        );
    }

    /* ============================================================
       DÉMON
    ============================================================ */
    public static function deamon_info() {
        $return = array();
        $return['log']        = 'risco';
        $return['state']      = 'nok';
        $return['launchable'] = 'ok';

        // Vérification PID (rapide)
        $pid_file = self::_pidFile();
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0 && @posix_getsid($pid) !== false) {
                $return['state'] = 'ok';
            } else {
                @unlink($pid_file);
            }
        }

        // Vérification venv (stat fichier = rapide)
        if (!file_exists(self::_venvPython())) {
            $return['launchable']         = 'nok';
            $return['launchable_message'] = __('Dépendances non installées', __FILE__);
            return $return;
        }

        // Vérification config — uniquement si état nok pour éviter byType inutile
        if ($return['state'] === 'nok') {
            $hasConfig = false;
            foreach (eqLogic::byType('risco', true) as $eq) {
                if (trim($eq->getConfiguration('host', '')) !== '') {
                    $hasConfig = true;
                    break;
                }
            }
            if (!$hasConfig) {
                $return['launchable']         = 'nok';
                $return['launchable_message'] = __('Aucun équipement avec une IP configurée', __FILE__);
            }
        }

        return $return;
    }

    public static function deamon_start($_automatic = false) {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas démarrer : ', __FILE__) . $deamon_info['launchable_message']);
        }

        $path   = self::_resourcesPath() . '/riscod';
        $venv   = self::_venvPython();
        $port   = self::_socketPort();
        $apikey = jeedom::getApiKey('risco');

        $panels = array();
        foreach (eqLogic::byType('risco', true) as $eq) {
            $host = trim($eq->getConfiguration('host', ''));
            if ($host === '') continue;
            $panels[] = array(
                'id'                  => (int)$eq->getId(),
                'host'                => $host,
                'port'                => (int)$eq->getConfiguration('port', 1000),
                'pin'                 => $eq->getConfiguration('pin', ''),
                'nb_partitions'       => (int)$eq->getConfiguration('nb_partitions', 1),
                'nb_zones'            => (int)$eq->getConfiguration('nb_zones', 32),
                'concurrency'         => (int)$eq->getConfiguration('concurrency', 4),
                'communication_delay' => (float)$eq->getConfiguration('communication_delay', 1),
                'encoding'            => $eq->getConfiguration('encoding', 'latin-1'),
            );
        }

        $cmd  = $venv . ' ' . $path . '/riscod.py';
        $cmd .= ' --loglevel '   . log::convertLogLevel(log::getLogLevel('risco'));
        $cmd .= ' --socketport ' . $port;
        $cmd .= ' --callback '   . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/risco/core/php/risco.inc.php';
        $cmd .= ' --apikey '     . $apikey;
        $cmd .= ' --pid '        . self::_pidFile();
        $cmd .= ' --panels '     . escapeshellarg(json_encode($panels));

        log::add('risco', 'info', 'Démarrage démon : ' . $cmd);
        exec($cmd . ' >> ' . log::getPathToLog('risco') . ' 2>&1 &');

        // On attend juste que le process Python soit lancé (PID écrit)
        // La connexion à la centrale peut prendre jusqu'à 60s selon son état
        $i = 0;
        while ($i < 5) {
            sleep(1);
            $deamon_info = self::deamon_info();
            if ($deamon_info['state'] === 'ok') {
                log::add('risco', 'info', 'Démon démarré. Connexion à la centrale en cours...');
                return true;
            }
            $i++;
        }
        throw new Exception(__('Le process démon ne démarre pas. Vérifiez le log risco.', __FILE__));
    }

    public static function deamon_stop() {
        $pid_file = self::_pidFile();
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            if ($pid > 0) {
                // 15 = SIGTERM, 9 = SIGKILL
                posix_kill($pid, 15);
                sleep(1);
                if (@posix_getsid($pid) !== false) {
                    posix_kill($pid, 9);
                }
            }
            @unlink($pid_file);
        }
        shell_exec('pkill -f riscod.py 2>/dev/null &');
    }

    /* ============================================================
       CALLBACK démon
    ============================================================ */
    public static function callbackDaemon($_data) {
        if (!isset($_data['type'])) return;

        switch ($_data['type']) {

            case 'zone':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                $z      = (int)$_data['zone_id'];
                $prefix = 'zone_' . $z . '_';
                self::_updateCmd($eq, $prefix . 'triggered',  (int)$_data['triggered']);
                self::_updateCmd($eq, $prefix . 'bypassed',   (int)$_data['bypassed']);
                self::_updateCmd($eq, $prefix . 'alarmed',    (int)$_data['alarmed']);
                self::_updateCmd($eq, $prefix . 'armed',      (int)$_data['armed']);
                if (isset($_data['name']) && $_data['name'] !== '') {
                    self::_updateCmd($eq, $prefix . 'name', $_data['name']);
                }
                if (isset($_data['type_id']))    self::_updateCmd($eq, $prefix . 'type_id',   (int)$_data['type_id']);
                if (isset($_data['partitions']))  self::_updateCmd($eq, $prefix . 'partitions', $_data['partitions']);
                if (isset($_data['groups']))      self::_updateCmd($eq, $prefix . 'groups',     $_data['groups']);
                break;

            case 'partition':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                $prefix = 'partition_' . $_data['partition_id'] . '_';
                self::_updateCmd($eq, $prefix . 'armed',           (int)$_data['armed']);
                self::_updateCmd($eq, $prefix . 'partially_armed', (int)$_data['partially_armed']);
                self::_updateCmd($eq, $prefix . 'alarmed',         (int)$_data['alarmed']);
                self::_updateCmd($eq, $prefix . 'ready',           (int)$_data['ready']);
                self::_updateCmd($eq, $prefix . 'triggered',       (int)$_data['triggered']);
                self::_updateCmd($eq, $prefix . 'state',           $_data['state'] ?? 'disarmed');
                if (isset($_data['name']) && $_data['name'] !== '') {
                    self::_updateCmd($eq, $prefix . 'name', $_data['name']);
                }
                self::_updateCmd($eq, $prefix . 'group_A', (int)($_data['group_A'] ?? 0));
                self::_updateCmd($eq, $prefix . 'group_B', (int)($_data['group_B'] ?? 0));
                self::_updateCmd($eq, $prefix . 'group_C', (int)($_data['group_C'] ?? 0));
                self::_updateCmd($eq, $prefix . 'group_D', (int)($_data['group_D'] ?? 0));
                break;

            case 'event':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                self::_updateCmd($eq, 'last_event_type',    $_data['event_type'] ?? '');
                self::_updateCmd($eq, 'last_event_name',    $_data['event_name'] ?? '');
                self::_updateCmd($eq, 'last_event_time',    $_data['event_time'] ?? '');
                self::_updateCmd($eq, 'last_event_zone_id', $_data['zone_id']    ?? '');
                break;

            case 'events':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                self::_updateCmd($eq, 'last_events', $_data['last_events'] ?? '');
                break;

            case 'discovered':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                $zones       = isset($_data['zones'])       ? $_data['zones']       : [];
                $all_zones   = isset($_data['all_zones'])   ? $_data['all_zones']   : $zones;
                $named_zones = isset($_data['named_zones']) ? $_data['named_zones'] : [];
                $partitions  = isset($_data['partitions'])  ? $_data['partitions']  : [];
                // Stocker les infos découvertes
                $eq->setConfiguration('zones_discovered',      implode(',', $zones));
                $eq->setConfiguration('zones_all',             implode(',', $all_zones));
                $eq->setConfiguration('zones_named',           implode(',', $named_zones));
                $eq->setConfiguration('partitions_discovered', implode(',', $partitions));
                $eq->setConfiguration('nb_partitions',         count($partitions));
                // zones_list : toujours mettre à jour avec les zones nommées détectées
                // L'utilisateur peut surcharger manuellement ensuite
                $zone_ids = !empty($named_zones) ? $named_zones : $zones;
                $eq->setConfiguration('zones_list', implode(',', $zone_ids));
                $eq->save();
                $eq->_createBaseCommands();
                $eq->createZoneCommands($zone_ids);
                log::add('risco', 'info', 'Zones nommees : ' . implode(',', $named_zones) . ' | Toutes zones : ' . implode(',', $all_zones) . ' | Partitions : ' . implode(',', $partitions));
                break;

            case 'system':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                self::_updateCmd($eq, 'system_name',                    $_data['name']                         ?? '');
                self::_updateCmd($eq, 'low_battery_trouble',            (int)($_data['low_battery_trouble']          ?? 0));
                self::_updateCmd($eq, 'ac_trouble',                     (int)($_data['ac_trouble']                   ?? 0));
                self::_updateCmd($eq, 'phone_line_trouble',             (int)($_data['phone_line_trouble']            ?? 0));
                self::_updateCmd($eq, 'clock_trouble',                  (int)($_data['clock_trouble']                 ?? 0));
                self::_updateCmd($eq, 'box_tamper',                     (int)($_data['box_tamper']                    ?? 0));
                self::_updateCmd($eq, 'programming_mode',               (int)($_data['programming_mode']              ?? 0));
                self::_updateCmd($eq, 'monitoring_station_1_trouble',   (int)($_data['monitoring_station_1_trouble']  ?? 0));
                self::_updateCmd($eq, 'monitoring_station_2_trouble',   (int)($_data['monitoring_station_2_trouble']  ?? 0));
                self::_updateCmd($eq, 'monitoring_station_3_trouble',   (int)($_data['monitoring_station_3_trouble']  ?? 0));
                break;

            case 'connexion':
                $eq = eqLogic::byId((int)$_data['eqLogic_id']);
                if (!is_object($eq)) return;
                self::_updateCmd($eq, 'connected', (int)$_data['connected']);
                log::add('risco', 'info', 'Centrale id=' . $_data['eqLogic_id'] . ' connexion=' . $_data['connected']);
                break;
        }
    }

    private static function _updateCmd($_eq, $_logicalId, $_value) {
        $cmd = $_eq->getCmd('info', $_logicalId);
        if (is_object($cmd)) {
            $cmd->event($_value);
        }
    }

    /* ============================================================
       CRÉATION DES COMMANDES
    ============================================================ */
    public function postSave() {
        $this->_createBaseCommands();
        $this->refreshWidget();
    }

    /* Commandes de base : connexion, système, partitions */
    public function _createBaseCommands() {
        $nb_partitions = max(1, (int)$this->getConfiguration('nb_partitions', 1));

        // Globales
        $this->_checkInfoCmd('connected',          __('Connexion', __FILE__),              'binary', 0);
        $this->_checkInfoCmd('last_event_type',    __('Dernier événement type', __FILE__), 'string', 0);
        $this->_checkInfoCmd('last_event_name',    __('Dernier événement nom', __FILE__),  'string', 0);
        $this->_checkInfoCmd('last_event_time',    __('Dernier événement heure', __FILE__),'string', 0);
        $this->_checkInfoCmd('last_event_zone_id', __('Dernier événement zone', __FILE__), 'string', 0);
        $this->_checkInfoCmd('last_events',        __('Derniers événements', __FILE__),    'string', 0);

        // Système
        $this->_checkInfoCmd('system_name',                   __('Nom centrale', __FILE__),              'string', 0);
        $this->_checkInfoCmd('low_battery_trouble',           __('Trouble batterie faible', __FILE__),   'binary', 0);
        $this->_checkInfoCmd('ac_trouble',                    __('Trouble alimentation', __FILE__),       'binary', 0);
        $this->_checkInfoCmd('phone_line_trouble',            __('Trouble ligne téléphonique', __FILE__), 'binary', 0);
        $this->_checkInfoCmd('clock_trouble',                 __('Trouble horloge', __FILE__),            'binary', 0);
        $this->_checkInfoCmd('box_tamper',                    __('Tamper boîtier', __FILE__),             'binary', 0);
        $this->_checkInfoCmd('programming_mode',              __('Mode programmation', __FILE__),         'binary', 0);
        $this->_checkInfoCmd('monitoring_station_1_trouble',  __('Trouble télésurveillance 1', __FILE__), 'binary', 0);
        $this->_checkInfoCmd('monitoring_station_2_trouble',  __('Trouble télésurveillance 2', __FILE__), 'binary', 0);
        $this->_checkInfoCmd('monitoring_station_3_trouble',  __('Trouble télésurveillance 3', __FILE__), 'binary', 0);

        // Partitions — créées dès la sauvegarde car nb_partitions est connu
        for ($p = 1; $p <= $nb_partitions; $p++) {
            $pfx = "partition_{$p}_";
            $lbl = "P{$p} ";
            $this->_checkInfoCmd($pfx . 'name',           $lbl . __('Nom', __FILE__),           'string', 0);
            $this->_checkInfoCmd($pfx . 'state',           $lbl . __('État', __FILE__),           'string', 0);
            $this->_checkInfoCmd($pfx . 'armed',           $lbl . __('Armé', __FILE__),           'binary', 0);
            $this->_checkInfoCmd($pfx . 'partially_armed', $lbl . __('Armé partiel', __FILE__),   'binary', 0);
            $this->_checkInfoCmd($pfx . 'alarmed',         $lbl . __('En alarme', __FILE__),      'binary', 0);
            $this->_checkInfoCmd($pfx . 'ready',           $lbl . __('Prêt', __FILE__),           'binary', 0);
            $this->_checkInfoCmd($pfx . 'triggered',       $lbl . __('Déclenché', __FILE__),      'binary', 0);
            $this->_checkInfoCmd($pfx . 'group_A',         $lbl . __('Groupe A armé', __FILE__),  'binary', 0);
            $this->_checkInfoCmd($pfx . 'group_B',         $lbl . __('Groupe B armé', __FILE__),  'binary', 0);
            $this->_checkInfoCmd($pfx . 'group_C',         $lbl . __('Groupe C armé', __FILE__),  'binary', 0);
            $this->_checkInfoCmd($pfx . 'group_D',         $lbl . __('Groupe D armé', __FILE__),  'binary', 0);
            $this->_checkActionCmd($pfx . 'arm',           $lbl . __('Armer total', __FILE__),   'other');
            $this->_checkActionCmd($pfx . 'disarm',        $lbl . __('Désarmer', __FILE__),      'other');
            $this->_checkActionCmd($pfx . 'partial_arm',   $lbl . __('Armer partiel', __FILE__), 'other');
            $this->_checkActionCmd($pfx . 'group_arm_A',   $lbl . __('Groupe A', __FILE__),      'other');
            $this->_checkActionCmd($pfx . 'group_arm_B',   $lbl . __('Groupe B', __FILE__),      'other');
            $this->_checkActionCmd($pfx . 'group_arm_C',   $lbl . __('Groupe C', __FILE__),      'other');
            $this->_checkActionCmd($pfx . 'group_arm_D',   $lbl . __('Groupe D', __FILE__),      'other');
        }
    }

    /* Commandes zones — appelé uniquement sur callback 'discovered' ou 'recreate_cmds' */
    public function createZoneCommands($_zone_ids = []) {
        $allow_bypass = (bool)$this->getConfiguration('allow_bypass', false);

        foreach ($_zone_ids as $z) {
            $z   = (int)$z;
            $pfx = "zone_{$z}_";
            $lbl = "Z{$z} ";
            $this->_checkInfoCmd($pfx . 'name',       $lbl . __('Nom', __FILE__),        'string', 0);
            $this->_checkInfoCmd($pfx . 'triggered',  $lbl . __('Ouvert', __FILE__),    'binary', 0);
            $this->_checkInfoCmd($pfx . 'alarmed',    $lbl . __('Alarme', __FILE__),    'binary', 0);
            $this->_checkInfoCmd($pfx . 'bypassed',   $lbl . __('Bypassé', __FILE__),   'binary', 0);
            $this->_checkInfoCmd($pfx . 'armed',      $lbl . __('Armé', __FILE__),      'binary', 0);
            $this->_checkInfoCmd($pfx . 'type_id',    $lbl . __('Type', __FILE__),      'string', 0);
            $this->_checkInfoCmd($pfx . 'partitions', $lbl . __('Partitions', __FILE__), 'string', 0);
            $this->_checkInfoCmd($pfx . 'groups',     $lbl . __('Groupes', __FILE__),   'string', 0);
            if ($allow_bypass) {
                $this->_checkActionCmd($pfx . 'bypass',   $lbl . __('Bypasser', __FILE__),   'other');
                $this->_checkActionCmd($pfx . 'unbypass', $lbl . __('Débypasser', __FILE__), 'other');
            }
        }
        $this->refreshWidget();
    }

    private function _checkInfoCmd($_logicalId, $_name, $_subType, $_historized = 0) {
        $cmd = $this->getCmd('info', $_logicalId);
        if (!is_object($cmd)) {
            $cmd = new riscoCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($_logicalId);
            $cmd->setType('info');
            $cmd->setSubType($_subType);
            $cmd->setName($_name);
            $cmd->setIsHistorized($_historized);
            $cmd->save();
        }
        return $cmd;
    }

    private function _checkActionCmd($_logicalId, $_name, $_subType) {
        $cmd = $this->getCmd('action', $_logicalId);
        if (!is_object($cmd)) {
            $cmd = new riscoCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($_logicalId);
            $cmd->setType('action');
            $cmd->setSubType($_subType);
            $cmd->setName($_name);
            $cmd->save();
        }
        return $cmd;
    }

    public function getImage() {
        return 'plugins/risco/plugin_info/risco_icon.png';
    }
}

/* ============================================================
   Classe commandes
   ============================================================ */
class riscoCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') return;

        $eq     = $this->getEqLogic();
        $port   = config::byKey('socketport', 'risco', '55010');
        $message = json_encode(array(
            'apikey'     => jeedom::getApiKey('risco'),
            'eqLogic_id' => (int)$eq->getId(),
            'action'     => $this->getLogicalId(),
        )) . "\n";

        try {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_connect($socket, '127.0.0.1', (int)$port);
            socket_write($socket, $message, strlen($message));
            socket_close($socket);
        } catch (Exception $e) {
            log::add('risco', 'error', 'Erreur envoi commande au démon : ' . $e->getMessage());
            throw $e;
        }
    }
}
