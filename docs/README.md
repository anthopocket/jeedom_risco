# Plugin Risco pour Jeedom

Intégration **100 % locale** (sans cloud) des centrales d'alarme Risco via le protocole TCP propriétaire.

Modèles supportés : WiComm, WiCommPro, LightSYS, ProsYS (et dérivés compatibles pyrisco).

---

## Architecture

```
Centrale Risco
    │  TCP (port 1000)
    ▼
Démon Python (riscod.py)   ← venv/bin/python3  [pyrisco]
    │  HTTP callback
    ▼
Jeedom core (risco.inc.php)
    │
    ▼
Commandes / Widgets
```

Le démon maintient une **connexion TCP persistante** avec la centrale.  
Les mises à jour arrivent en **push** (pas de polling).

---

## Installation

1. Installer le plugin via le Market ou en déposant le dossier dans `/var/www/html/plugins/risco/`
2. Activer le plugin
3. Aller dans **Plugins → Gestion des plugins → Risco → Dépendances → Installer**
4. Attendre la fin de l'installation (pyrisco + venv)
5. **Créer un équipement** et renseigner :
   - Adresse IP de la centrale
   - Port TCP (défaut : 1000)
   - Code PIN (code installateur ou utilisateur principal)
   - Nombre de partitions et de zones
6. Sauvegarder → les commandes sont créées automatiquement
7. Démarrer le démon

---

## Prérequis réseau

La centrale Risco doit être sur le **même réseau local** que Jeedom (ou accessible en TCP).  
Activez l'interface IP sur la centrale (menu Installateur → Communication → IP).

Le port par défaut est **1000** (TCP). Ouvrez ce port si un pare-feu est en place.

---

## Commandes créées automatiquement

### Globales
| LogicalId | Type | Description |
|---|---|---|
| connected | info/binary | Connexion démon ↔ centrale |
| last_event_type | info/string | Type du dernier événement |
| last_event_name | info/string | Nom du dernier événement |
| last_event_time | info/string | Heure du dernier événement |
| last_event_zone_id | info/string | Zone du dernier événement |

### Par partition (P1…Pn)
| LogicalId | Type | Description |
|---|---|---|
| partition_N_state | info/string | État : disarmed / armed_away / armed_home / triggered |
| partition_N_armed | info/binary | Armé total |
| partition_N_partially_armed | info/binary | Armé partiel |
| partition_N_alarmed | info/binary | En alarme |
| partition_N_ready | info/binary | Prêt à armer |
| partition_N_triggered | info/binary | Déclenché |
| partition_N_arm | action | Armer total |
| partition_N_disarm | action | Désarmer |
| partition_N_partial_arm | action | Armer partiel (stay) |
| partition_N_group_arm_A/B/C/D | action | Armer groupe |

### Par zone (Z1…Zn)
| LogicalId | Type | Description |
|---|---|---|
| zone_N_name | info/string | Nom de la zone |
| zone_N_triggered | info/binary | Ouvert / actif |
| zone_N_alarmed | info/binary | En alarme |
| zone_N_bypassed | info/binary | Bypassé |
| zone_N_armed | info/binary | Armé |
| zone_N_bypass | action | Bypasser (si option activée) |
| zone_N_unbypass | action | Débypasser (si option activée) |

---

## Configuration avancée

- **Port socket** (config plugin) : port TCP interne Jeedom ↔ démon (défaut 55010)
- **PIN requis armer/désarmer** : le PIN est envoyé avec chaque commande d'armement
- **Bypass zones** : permet de créer les commandes bypass/unbypass par zone

---

## Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| Démon ne démarre pas | Venv absent | Relancer les dépendances |
| `invalid state` au démarrage | Problème timing pyrisco | Attendre quelques secondes et relancer |
| Timeout connexion | Centrale inaccessible | Vérifier IP/port/pare-feu |
| Commandes inactives | PIN incorrect | Vérifier le code PIN dans la config équipement |

---

## Changelog

### v1.0.0
- Intégration locale TCP via pyrisco
- Support multi-centrales
- Push temps réel partitions, zones, événements
- Armer / désarmer / partiel / groupes A/B/C/D
- Bypass zones
