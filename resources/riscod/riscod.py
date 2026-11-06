#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
riscod.py – Démon Jeedom pour le plugin Risco
Connexion locale TCP (sans cloud) via la bibliothèque pyrisco.
"""

import asyncio
import json
import logging
import os
import signal
import sys
import argparse
import socket
import threading
import traceback
import re
from datetime import datetime
from collections import deque

try:
    from pyrisco import RiscoLocal
except ImportError as e:
    print(f"ERREUR : pyrisco non trouvé – {e}")
    sys.exit(1)

# ══════════════════════════════════════════════════════════════════════════════
#  Codes erreur Risco
# ══════════════════════════════════════════════════════════════════════════════
RISCO_ERRORS = {
    'N00': 'Commande inconnue',
    'N01': 'Acces refuse / PIN incorrect',
    'N05': 'Format de commande invalide',
    'N07': 'Zone en alarme',
    'N09': 'Armement refuse : centrale en alarme',
    'N10': 'Armement refuse : tamper actif',
    'N11': 'Armement refuse : panne secteur',
    'N12': 'Armement refuse : zones ouvertes ou non pretes',
    'N13': 'Armement refuse : zone en defaut',
    'N14': 'Armement refuse : batterie faible',
    'N15': 'Armement refuse : defaut sirene',
    'N16': 'Partition dejaarmee',
    'N17': 'Partition deja desarmee',
    'N18': 'Bypass non autorise',
    'N19': 'Operation non supportee',
    'B00': 'Erreur CRC',
    'B01': 'Timeout protocole',
}

def risco_error_msg(error_str):
    match = re.search(r'[NB]\d+', str(error_str))
    if match:
        code = match.group(0)
        label = RISCO_ERRORS.get(code, 'Erreur centrale inconnue')
        return f'{label} [{code}]'
    return str(error_str)

# ══════════════════════════════════════════════════════════════════════════════
#  CLI
# ══════════════════════════════════════════════════════════════════════════════
parser = argparse.ArgumentParser(description='Démon Jeedom – plugin Risco')
parser.add_argument('--loglevel',   type=str, default='warning')
parser.add_argument('--socketport', type=int, default=55010)
parser.add_argument('--callback',   type=str, required=True)
parser.add_argument('--apikey',     type=str, required=True)
parser.add_argument('--pid',        type=str, required=True)
parser.add_argument('--panels',     type=str, default='[]')
args = parser.parse_args()

LEVELS = {
    'debug': logging.DEBUG, 'info': logging.INFO,
    'warning': logging.WARNING, 'error': logging.ERROR,
    '100': logging.DEBUG, '200': logging.INFO,
    '300': logging.WARNING, '400': logging.ERROR,
}
logging.basicConfig(
    format='[%(asctime)s][%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
    level=LEVELS.get(str(args.loglevel).lower(), logging.WARNING),
)
logger = logging.getLogger('riscod')

os.makedirs(os.path.dirname(args.pid), exist_ok=True)
with open(args.pid, 'w') as f:
    f.write(str(os.getpid()))
logger.info(f"PID {os.getpid()} écrit dans {args.pid}")

# ══════════════════════════════════════════════════════════════════════════════
#  Callback Jeedom
# ══════════════════════════════════════════════════════════════════════════════
def _send_callback_sync(payload: dict):
    import urllib.request
    try:
        req = urllib.request.Request(
            args.callback,
            data=json.dumps(payload).encode('utf-8'),
            headers={'Content-Type': 'application/json'},
            method='POST',
        )
        with urllib.request.urlopen(req, timeout=5) as resp:
            logger.debug(f"Callback OK: {resp.status} - {payload.get('type','?')}")
    except Exception as e:
        logger.error(f"Erreur callback Jeedom : {e}")

def send_callback(data: dict):
    payload = {**data, 'apikey': args.apikey}
    t = threading.Thread(target=_send_callback_sync, args=(payload,), daemon=True)
    t.start()


# ══════════════════════════════════════════════════════════════════════════════
#  Helpers envoi état
# ══════════════════════════════════════════════════════════════════════════════
def send_partition(eq_id, p_id, partition):
    armed           = int(partition.armed)
    partially_armed = int(getattr(partition, 'partially_armed', False))
    alarmed         = int(getattr(partition, 'alarmed', False))
    ready           = int(getattr(partition, 'ready', True))
    triggered       = int(getattr(partition, 'triggered', False))
    disarmed        = int(getattr(partition, 'disarmed', not (armed or partially_armed)))
    arming          = int(getattr(partition, 'arming', False))
    name            = getattr(partition, 'name', f'Partition {p_id}')
    groups          = getattr(partition, 'groups', {})

    if alarmed:             state = 'triggered'
    elif armed:              state = 'armed_away'
    elif partially_armed:    state = 'armed_home'
    else:                    state = 'disarmed'

    send_callback({
        'type':            'partition',
        'eqLogic_id':      eq_id,
        'partition_id':    p_id,
        'name':            name,
        'armed':           armed,
        'partially_armed': partially_armed,
        'alarmed':         alarmed,
        'ready':           ready,
        'triggered':       triggered,
        'state':           state,
        'group_A':         int(groups.get('A', False)),
        'group_B':         int(groups.get('B', False)),
        'group_C':         int(groups.get('C', False)),
        'group_D':         int(groups.get('D', False)),
    })

def send_system(eq_id, system):
    if system is None:
        return
    send_callback({
        'type':                         'system',
        'eqLogic_id':                   eq_id,
        'name':                         getattr(system, 'name', ''),
        'low_battery_trouble':          int(getattr(system, 'low_battery_trouble', False)),
        'ac_trouble':                   int(getattr(system, 'ac_trouble', False)),
        'phone_line_trouble':           int(getattr(system, 'phone_line_trouble', False)),
        'clock_trouble':                int(getattr(system, 'clock_trouble', False)),
        'box_tamper':                   int(getattr(system, 'box_tamper', False)),
        'programming_mode':             int(getattr(system, 'programming_mode', False)),
        'monitoring_station_1_trouble': int(getattr(system, 'monitoring_station_1_trouble', False)),
        'monitoring_station_2_trouble': int(getattr(system, 'monitoring_station_2_trouble', False)),
        'monitoring_station_3_trouble': int(getattr(system, 'monitoring_station_3_trouble', False)),
    })

def send_zone(eq_id, z_id, zone):
    send_callback({
        'type':       'zone',
        'eqLogic_id': eq_id,
        'zone_id':    z_id,
        'name':       getattr(zone, 'name', f'Zone {z_id}'),
        'triggered':  int(zone.triggered),
        'bypassed':   int(zone.bypassed),
        'alarmed':    int(getattr(zone, 'alarmed', False)),
        'armed':      int(getattr(zone, 'armed', False)),
        'type_id':    int(getattr(zone, 'type', 0)),
        'partitions': str(getattr(zone, 'partitions', [])),
        'groups':     str(getattr(zone, 'groups', [])),
    })


# ══════════════════════════════════════════════════════════════════════════════
#  Gestion d'une centrale
# ══════════════════════════════════════════════════════════════════════════════
class PanelManager:

    REFRESH_INTERVAL = 30   # secondes entre chaque rafraîchissement des partitions
    MAX_EVENTS       = 5    # nombre maximum d'événements dans l'historique

    def __init__(self, panel_conf: dict):
        self.id                  = panel_conf['id']
        self.host                = panel_conf['host']
        self.port                = int(panel_conf.get('port', 1000))
        self.pin                 = str(panel_conf.get('pin', ''))
        self.nb_partitions       = int(panel_conf.get('nb_partitions', 1))
        self.nb_zones            = int(panel_conf.get('nb_zones', 32))
        self.concurrency         = int(panel_conf.get('concurrency', 4))
        self.communication_delay = float(panel_conf.get('communication_delay', 1))
        self.encoding            = panel_conf.get('encoding', 'latin-1')
        self._risco              = None
        self._remove_handlers    = []
        self._running            = False
        self._refresh_task       = None
        self._prev_partition_state = {}   # {p_id: {armed, partial, alarmed}}
        self._history_file       = os.path.join(os.path.dirname(args.pid), f'events_{self.id}.json')
        self._events_history      = deque(self._load_history(), maxlen=self.MAX_EVENTS)

    async def connect(self):
        self._running = True
        while self._running:
            try:
                # Reproduire exactement la logique HA : incrementer comm_delay
                # et reessayer immediatement jusqu'a MAX_COMMUNICATION_DELAY
                MAX_DELAY = 5
                comm_delay = self.communication_delay
                connected = False
                while not connected:
                    logger.info(f"[Panel {self.id}] Connexion à {self.host}:{self.port} pin={self.pin} concurrency={self.concurrency} delay={comm_delay}...")
                    self._risco = RiscoLocal(
                        self.host, self.port, self.pin,
                        concurrency=self.concurrency,
                        communication_delay=comm_delay,
                        encoding=self.encoding,
                    )
                    self._register_handlers()
                    try:
                        await self._risco.connect()
                        logger.info(f"[Panel {self.id}] Connecté avec delay={comm_delay}")
                        connected = True
                    except Exception as conn_err:
                        self._remove_handlers.clear()
                        if comm_delay >= MAX_DELAY:
                            # Tous les delays ont echoue — pause longue puis on repart de delay=1
                            logger.warning(f"[Panel {self.id}] Tous les delays ont echoue, pause 90s avant retry...")
                            await asyncio.sleep(90)
                            comm_delay = self.communication_delay
                            continue
                        logger.warning(f"[Panel {self.id}] Echec delay={comm_delay}, retry delay={comm_delay+1}...")
                        comm_delay += 1
                        await asyncio.sleep(2)

                self._retry_count = 0  # Reset compteur reconnexion
                send_callback({'type': 'connexion', 'eqLogic_id': self.id, 'connected': 1})
                logger.info(f"[Panel {self.id}] Connecté. Partitions={list(self._risco.partitions.keys())} Zones={list(self._risco.zones.keys())[:5]}")

                # ── État initial partitions ────────────────────────────────
                # Initialiser l'état précédent AVANT d'enregistrer les handlers
                # pour que on_partition détecte les vraies transitions (et non le boot)
                for p_id, partition in self._risco.partitions.items():
                    self._prev_partition_state[p_id] = {
                        'armed':   bool(getattr(partition, 'armed',           False)),
                        'partial': bool(getattr(partition, 'partially_armed', False)),
                        'alarmed': bool(getattr(partition, 'alarmed',         False)),
                    }
                    send_partition(self.id, p_id, partition)

                # ── Envoyer l'historique existant à Jeedom ────────────────
                if self._events_history:
                    lines = list(reversed(self._events_history))
                    send_callback({
                        'type':        'events',
                        'eqLogic_id':  self.id,
                        'last_events': '\n'.join(lines),
                    })

                # ── Zones découvertes ─────────────────────────────────────
                all_zones = list(self._risco.zones.keys())
                real_partitions = list(self._risco.partitions.keys())

                named_zones = []
                for z_id, zone in self._risco.zones.items():
                    name = getattr(zone, 'name', f'Zone {z_id:02d}').strip()
                    default_names = [f'Zone {z_id}', f'Zone {z_id:02d}', f'ZONE{z_id:02d}', '']
                    if name not in default_names:
                        named_zones.append(z_id)

                zones_to_report = named_zones if named_zones else all_zones

                # Envoyer discovered EN PREMIER et attendre que Jeedom
                # crée les commandes avant d'envoyer les valeurs
                _send_callback_sync({
                    'type':        'discovered',
                    'eqLogic_id':  self.id,
                    'zones':       zones_to_report,
                    'all_zones':   all_zones,
                    'named_zones': named_zones,
                    'partitions':  real_partitions,
                    'apikey':      args.apikey,
                })
                logger.info(f"[Panel {self.id}] Zones totales : {all_zones}")
                logger.info(f"[Panel {self.id}] Zones nommees : {named_zones}")
                logger.info(f"[Panel {self.id}] Partitions reelles : {real_partitions}")

                # Attendre que Jeedom ait créé les commandes
                await asyncio.sleep(2)

                # ── État initial zones ─────────────────────────────────────
                for z_id, zone in self._risco.zones.items():
                    send_zone(self.id, z_id, zone)

                # ── État initial système ────────────────────────────────────
                if self._risco.system:
                    send_system(self.id, self._risco.system)

                # ── Tâche de rafraîchissement périodique des partitions ────
                self._refresh_task = asyncio.create_task(self._refresh_loop())

                # ── Boucle principale : traite les événements pyrisco ──────
                while self._running:
                    try:
                        # La queue est sur _rs (RiscoSocket), pas sur RiscoLocal
                        queue = self._risco._rs.queue if hasattr(self._risco, '_rs') else None
                        if queue is None:
                            await asyncio.sleep(5)
                            continue
                        event = await asyncio.wait_for(queue.get(), timeout=5)
                        if isinstance(event, Exception):
                            raise event
                        logger.debug(f"[Panel {self.id}] Event queue : {event}")
                        # Workaround bug pyrisco : ZSTT/PSTT push non gérés
                        if isinstance(event, str):
                            if event.startswith('ZSTT'):
                                await self._handle_zstt(event)
                            elif event.startswith('PSTT'):
                                await self._handle_pstt(event)
                    except asyncio.TimeoutError:
                        pass   # normal, on reboucle

            except asyncio.CancelledError:
                break
            except Exception as e:
                logger.error(f"[Panel {self.id}] Erreur connexion : {e}\n{traceback.format_exc()}")
                send_callback({'type': 'connexion', 'eqLogic_id': self.id, 'connected': 0})
                await self._cancel_refresh()
                if self._running:
                    # Attente progressive : 60s, 120s, 120s... pour laisser la centrale récupérer
                    retry_delay = min(120, 60 * (1 + getattr(self, '_retry_count', 0)))
                    self._retry_count = getattr(self, '_retry_count', 0) + 1
                    logger.info(f"[Panel {self.id}] Reconnexion dans {retry_delay}s (tentative {self._retry_count})...")
                    await asyncio.sleep(retry_delay)

    async def _refresh_loop(self):
        """Rafraîchit l'état des partitions toutes les REFRESH_INTERVAL secondes."""
        while self._running:
            await asyncio.sleep(self.REFRESH_INTERVAL)
            if not self._risco:
                break
            try:
                for p_id, partition in self._risco.partitions.items():
                    send_partition(self.id, p_id, partition)
                logger.debug(f"[Panel {self.id}] Rafraîchissement partitions OK")
            except Exception as e:
                logger.warning(f"[Panel {self.id}] Erreur rafraîchissement partitions : {e}")

    async def _cancel_refresh(self):
        if self._refresh_task:
            self._refresh_task.cancel()
            try:
                await self._refresh_task
            except asyncio.CancelledError:
                pass
            self._refresh_task = None

    async def disconnect(self):
        self._running = False
        await self._cancel_refresh()
        for remove in self._remove_handlers:
            try:
                remove()
            except Exception:
                pass
        self._remove_handlers.clear()
        if self._risco:
            try:
                await self._risco.disconnect()
            except Exception:
                pass

    def _register_handlers(self):
        r = self._risco

        async def on_zone(zone_id, zone):
            logger.debug(f"[Panel {self.id}] Zone {zone_id} triggered={zone.triggered} bypassed={zone.bypassed}")
            send_zone(self.id, zone_id, zone)

        async def on_partition(partition_id, partition):
            armed   = bool(getattr(partition, 'armed',           False))
            partial = bool(getattr(partition, 'partially_armed', False))
            alarmed = bool(getattr(partition, 'alarmed',         False))
            prev = self._prev_partition_state.get(partition_id)
            self._prev_partition_state[partition_id] = {'armed': armed, 'partial': partial, 'alarmed': alarmed}
            logger.debug(f"[Panel {self.id}] Partition {partition_id} armed={armed} partial={partial} alarmed={alarmed} (prev={prev})")
            send_partition(self.id, partition_id, partition)
            if prev is None:
                return   # état initial non encore connu (ne devrait plus arriver)
            was_armed   = prev['armed']
            was_partial = prev['partial']
            was_alarmed = prev['alarmed']
            pname = getattr(partition, 'name', '') or f'Partition {partition_id}'
            if alarmed and not was_alarmed:
                self._add_event(f"🚨 ALARME {pname}")
            elif armed and not was_armed:
                self._add_event(f"🔒 Armement total {pname}")
            elif partial and not was_partial:
                self._add_event(f"🔒 Armement partiel {pname}")
            elif (not armed and not partial and not alarmed
                  and (was_armed or was_partial or was_alarmed)):
                self._add_event(f"🔓 Désarmement {pname}")

        async def on_event(event):
            logger.debug(f"[Panel {self.id}] Événement : {event}")
            etype = getattr(event, 'type_id', '') or ''
            ename = getattr(event, 'name', '')    or ''
            send_callback({
                'type':       'event',
                'eqLogic_id': self.id,
                'event_type': etype,
                'event_name': ename,
                'event_time': str(getattr(event, 'time', '')),
                'zone_id':    getattr(event, 'zone_id', ''),
            })
            # N'ajouter à l'historique que les events qui ne sont PAS couverts
            # par _handle_pstt (arme/desarme) ou _handle_zstt (zone trigger/alarme/bypass)
            # pour éviter les doublons. On capture seulement les events "système" distincts.
            SKIP_TYPES = {'ARM', 'DISARM', 'PARTIAL', 'TRIGGER', 'ALARM'}
            if etype.upper() not in SKIP_TYPES and ename:
                # Filtrer aussi les events d'armement/désarmement par leur nom
                skip_keywords = ('arme', 'desarme', 'désarme', 'armement', 'arm', 'disarm',
                                  'partition', 'zone', 'alarme', 'alarm', 'trigger', 'déclenche')
                ename_low = ename.lower()
                if not any(kw in ename_low for kw in skip_keywords):
                    self._add_event(f"ℹ {ename}")

        async def on_error(error):
            err_str = str(error)
            # Ignorer les erreurs transitoires au demarrage (init parallele des zones)
            if any(x in err_str for x in ["set_result", "N04", "N03", "CLOCK"]):
                logger.debug(f"[Panel {self.id}] Erreur pyrisco ignoree (init) : {err_str}")
                return
            logger.error(f"[Panel {self.id}] Erreur pyrisco : {risco_error_msg(error)}")
            # Reconnexion si perte de connexion
            if isinstance(error, ConnectionResetError):
                logger.warning(f"[Panel {self.id}] Connexion perdue, reconnexion...")
                send_callback({'type': 'connexion', 'eqLogic_id': self.id, 'connected': 0})

        async def on_system(system):
            logger.debug(f"[Panel {self.id}] System update ac={system.ac_trouble} battery={system.low_battery_trouble}")
            send_system(self.id, system)

        self._remove_handlers.append(r.add_zone_handler(on_zone))
        self._remove_handlers.append(r.add_partition_handler(on_partition))
        self._remove_handlers.append(r.add_system_handler(on_system))
        self._remove_handlers.append(r.add_event_handler(on_event))
        self._remove_handlers.append(r.add_error_handler(on_error))

    def _load_history(self) -> list:
        """Charge l'historique des événements depuis le fichier de persistance."""
        try:
            if os.path.exists(self._history_file):
                with open(self._history_file, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                    if isinstance(data, list):
                        logger.debug(f"[Panel {self.id}] Historique chargé : {len(data)} événements")
                        return data[-self.MAX_EVENTS:]
        except Exception as e:
            logger.warning(f"[Panel {self.id}] Impossible de charger l'historique : {e}")
        return []

    def _save_history(self):
        """Sauvegarde l'historique des événements dans un fichier."""
        try:
            with open(self._history_file, 'w', encoding='utf-8') as f:
                json.dump(list(self._events_history), f, ensure_ascii=False)
        except Exception as e:
            logger.warning(f"[Panel {self.id}] Impossible de sauvegarder l'historique : {e}")

    def _add_event(self, label: str):
        """Ajoute un événement à l'historique et envoie la mise à jour."""
        ts = datetime.now().strftime('%d/%m %H:%M')
        entry = f"{ts} {label}"
        self._events_history.append(entry)
        self._save_history()
        lines = list(reversed(self._events_history))   # plus récent en tête
        text  = '\n'.join(lines)
        send_callback({
            'type':        'events',
            'eqLogic_id':  self.id,
            'last_events': text,
        })
        logger.debug(f"[Panel {self.id}] Historique événements : {entry}")

    async def _handle_pstt(self, event: str):
        try:
            import re
            m = re.match(r'PSTT(\d+)=(.+)', event)
            if not m:
                return
            p_id   = int(m.group(1))
            status = m.group(2)
            partition = self._risco.partitions.get(p_id)
            if partition:
                # Lire l'état AVANT mise à jour
                was_armed   = bool(getattr(partition, 'armed',           False))
                was_partial = bool(getattr(partition, 'partially_armed', False))
                was_alarmed = bool(getattr(partition, 'alarmed',         False))
                partition.update_status(status)
                send_partition(self.id, p_id, partition)
                # Lire l'état APRÈS mise à jour
                now_armed   = bool(getattr(partition, 'armed',           False))
                now_partial = bool(getattr(partition, 'partially_armed', False))
                now_alarmed = bool(getattr(partition, 'alarmed',         False))
                pname = getattr(partition, 'name', '') or f'Partition {p_id}'
                logger.debug(f"[Panel {self.id}] PSTT partition {p_id}: was=({was_armed},{was_partial},{was_alarmed}) now=({now_armed},{now_partial},{now_alarmed})")
                # Détecter les transitions
                if now_alarmed and not was_alarmed:
                    self._add_event(f"🚨 ALARME {pname}")
                elif now_armed and not was_armed:
                    self._add_event(f"🔒 Armement total {pname}")
                elif now_partial and not was_partial:
                    self._add_event(f"🔒 Armement partiel {pname}")
                elif (not now_armed and not now_partial and not now_alarmed
                      and (was_armed or was_partial or was_alarmed)):
                    self._add_event(f"🔓 Désarmement {pname}")
        except Exception as e:
            logger.warning(f"[Panel {self.id}] Erreur _handle_pstt : {e}")

    async def _handle_zstt(self, event: str):
        """Traite manuellement un ZSTT push quand pyrisco ne trouve pas la zone."""
        try:
            import re
            m = re.match(r'ZSTT(\d+)=(.+)', event)
            if not m:
                return
            z_id   = int(m.group(1))
            status = m.group(2)
            zone   = self._risco.zones.get(z_id)
            if zone:
                was_alarmed   = getattr(zone, 'alarmed',    False)
                was_triggered = getattr(zone, 'triggered',  False)
                was_bypassed  = getattr(zone, 'bypassed',   False)
                zone.update_status(status)
                send_zone(self.id, z_id, zone)
                zname = getattr(zone, 'name', f'Z{z_id}') or f'Z{z_id}'
                now_alarmed   = getattr(zone, 'alarmed',    False)
                now_triggered = getattr(zone, 'triggered',  False)
                now_bypassed  = getattr(zone, 'bypassed',   False)
                # Vérifier si au moins une partition de la zone est armée
                zone_armed = bool(getattr(zone, 'armed', False))
                if now_alarmed and not was_alarmed:
                    self._add_event(f"🚨 Alarme {zname}")
                elif now_triggered and not was_triggered and zone_armed:
                    # Ne loguer la détection que si la zone est armée (évite le bruit)
                    self._add_event(f"📡 Détection {zname}")
                elif not now_triggered and was_triggered and zone_armed:
                    self._add_event(f"✅ Retour calme {zname}")
                elif now_bypassed and not was_bypassed:
                    self._add_event(f"⏭ Bypass {zname}")
                elif not now_bypassed and was_bypassed:
                    self._add_event(f"↩ Débypass {zname}")
                logger.debug(f"[Panel {self.id}] ZSTT manuel zone {z_id} status={status}")
            else:
                logger.debug(f"[Panel {self.id}] ZSTT zone {z_id} inconnue de pyrisco, ignoré")
        except Exception as e:
            logger.warning(f"[Panel {self.id}] Erreur _handle_zstt : {e}")

    async def sync_zones(self):
        if not self._risco:
            logger.warning(f"[Panel {self.id}] sync_zones ignoré : non connecté.")
            return
        logger.info(f"[Panel {self.id}] Synchronisation des zones...")
        all_zones = list(self._risco.zones.keys())
        real_partitions = list(self._risco.partitions.keys())
        named_zones = []
        for z_id, zone in self._risco.zones.items():
            name = getattr(zone, 'name', f'Zone {z_id:02d}').strip()
            default_names = [f'Zone {z_id}', f'Zone {z_id:02d}', f'ZONE{z_id:02d}', '']
            if name not in default_names:
                named_zones.append(z_id)
        zones_to_report = named_zones if named_zones else all_zones

        # Envoyer discovered synchrone — Jeedom crée les commandes
        _send_callback_sync({
            'type':        'discovered',
            'eqLogic_id':  self.id,
            'zones':       zones_to_report,
            'all_zones':   all_zones,
            'named_zones': named_zones,
            'partitions':  real_partitions,
            'apikey':      args.apikey,
        })
        logger.info(f"[Panel {self.id}] sync_zones envoyé : nommées={named_zones}")

        # Attendre que Jeedom crée les commandes puis envoyer les valeurs
        await asyncio.sleep(2)
        for z_id, zone in self._risco.zones.items():
            if z_id in zones_to_report:
                send_zone(self.id, z_id, zone)
        for p_id, partition in self._risco.partitions.items():
            send_partition(self.id, p_id, partition)

    async def execute_action(self, action: str):
        if not self._risco:
            logger.warning(f"[Panel {self.id}] Action '{action}' ignorée : non connecté.")
            return
        try:
            parts = action.split('_')
            if parts[0] == 'partition' and len(parts) >= 3:
                p_id      = int(parts[1])
                cmd       = '_'.join(parts[2:])
                partition = self._risco.partitions.get(p_id)
                if not partition:
                    logger.warning(f"[Panel {self.id}] Partition {p_id} introuvable.")
                    return
                if cmd == 'arm':
                    await partition.arm()
                elif cmd == 'disarm':
                    await partition.disarm()
                elif cmd == 'partial_arm':
                    await partition.partial_arm()
                elif cmd.startswith('group_arm_'):
                    await partition.group_arm(cmd.split('_')[-1])
                else:
                    logger.warning(f"[Panel {self.id}] Commande partition inconnue : {cmd}")
                # Renvoyer l'état immédiatement après action
                await asyncio.sleep(1)
                send_partition(self.id, p_id, partition)

            elif parts[0] == 'zone' and len(parts) >= 3:
                z_id = int(parts[1])
                cmd  = '_'.join(parts[2:])
                zone = self._risco.zones.get(z_id)
                if not zone:
                    logger.warning(f"[Panel {self.id}] Zone {z_id} introuvable.")
                    return
                if cmd == 'bypass':
                    await zone.bypass(True)
                elif cmd == 'unbypass':
                    await zone.bypass(False)
                else:
                    logger.warning(f"[Panel {self.id}] Commande zone inconnue : {cmd}")
                await asyncio.sleep(1)
                send_zone(self.id, z_id, zone)
            else:
                logger.warning(f"[Panel {self.id}] Action inconnue : {action}")
        except Exception as e:
            logger.error(f"[Panel {self.id}] Erreur execution '{action}' : {risco_error_msg(e)}")


# ══════════════════════════════════════════════════════════════════════════════
#  Socket local Jeedom → démon
# ══════════════════════════════════════════════════════════════════════════════
class JeedomSocketServer:

    def __init__(self, port, panels, loop):
        self.port    = port
        self.panels  = panels
        self.loop    = loop
        self._srv    = None
        self._thread = None

    def start(self):
        self._srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            self._srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
        except AttributeError:
            pass
        self._srv.bind(('127.0.0.1', self.port))
        self._srv.listen(5)
        self._srv.settimeout(1)
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()
        logger.info(f"Socket Jeedom en écoute sur 127.0.0.1:{self.port}")

    def stop(self):
        if self._srv:
            try:
                self._srv.close()
            except Exception:
                pass

    def _run(self):
        while True:
            try:
                conn, _ = self._srv.accept()
                data = b''
                while True:
                    chunk = conn.recv(4096)
                    if not chunk:
                        break
                    data += chunk
                    if b'\n' in data:
                        break
                conn.close()
                self._handle(data.strip())
            except socket.timeout:
                continue
            except OSError:
                break
            except Exception as e:
                logger.error(f"Socket Jeedom erreur : {e}")

    def _handle(self, raw: bytes):
        try:
            msg = json.loads(raw.decode('utf-8'))
        except Exception as e:
            logger.warning(f"Socket Jeedom JSON invalide : {e}")
            return
        if msg.get('apikey') != args.apikey:
            logger.warning("Socket Jeedom : clé API invalide.")
            return
        eq_id  = int(msg.get('eqLogic_id', 0))
        action = msg.get('action', '')
        panel  = self.panels.get(eq_id)
        if not panel:
            logger.warning(f"Socket Jeedom : panel id={eq_id} inconnu.")
            return
        logger.info(f"Commande reçue : panel={eq_id} action={action}")
        if action == 'sync_zones':
            asyncio.run_coroutine_threadsafe(panel.sync_zones(), self.loop)
        else:
            asyncio.run_coroutine_threadsafe(panel.execute_action(action), self.loop)


# ══════════════════════════════════════════════════════════════════════════════
#  Main
# ══════════════════════════════════════════════════════════════════════════════
async def main():
    panels_conf = json.loads(args.panels)
    if not panels_conf:
        logger.warning("Aucune centrale configurée.")

    managers = {}
    tasks    = []
    for conf in panels_conf:
        pm = PanelManager(conf)
        managers[pm.id] = pm
        tasks.append(asyncio.create_task(pm.connect()))

    loop = asyncio.get_running_loop()

    socket_server = JeedomSocketServer(args.socketport, managers, loop)
    try:
        socket_server.start()
    except OSError as e:
        logger.error(f"Impossible de démarrer le socket sur le port {args.socketport} : {e}")
        for t in tasks:
            t.cancel()
        sys.exit(1)

    stop_event = asyncio.Event()

    def _on_signal():
        logger.info("Signal reçu, arrêt...")
        stop_event.set()

    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, _on_signal)

    logger.info("Démon Risco démarré.")
    await stop_event.wait()

    for pm in managers.values():
        await pm.disconnect()
    for t in tasks:
        t.cancel()
    socket_server.stop()

    try:
        os.remove(args.pid)
    except Exception:
        pass
    logger.info("Démon Risco arrêté.")


if __name__ == '__main__':
    asyncio.run(main())
