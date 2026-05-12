/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

"use strict";

/* ──────────────────────────────────────────────────────────────────────────
   Chargement du panneau équipement
────────────────────────────────────────────────────────────────────────── */
$(function () {

  /* Ajout d'une commande manuelle */
  $('#bt_addRiscoCmd').on('click', function () {
    addCmd();
  });

  /* Sauvegarde équipement → recréer les commandes côté PHP */
  $('.eqLogicAction[data-action="save"]').off('click.risco').on('click.risco', function () {
    // laissé au core Jeedom
  });
});

/* ──────────────────────────────────────────────────────────────────────────
   Callback du core : appelé à chaque chargement d'un équipement
────────────────────────────────────────────────────────────────────────── */
function printEqLogic(_eqLogic) {
  if (!isset(_eqLogic)) return;
  printEqLogicCmd(_eqLogic.cmds);
}

/* ──────────────────────────────────────────────────────────────────────────
   Rendu de la table des commandes
────────────────────────────────────────────────────────────────────────── */
function printEqLogicCmd(_cmds) {
  if (!isset(_cmds)) return;
  var $tbody = $('#table_cmd tbody');
  $tbody.empty();
  for (var i in _cmds) {
    addCmd(_cmds[i]);
  }
  $tbody.find('.itype select').trigger('change');
}

/* ──────────────────────────────────────────────────────────────────────────
   Ajout d'une ligne commande dans le tableau
────────────────────────────────────────────────────────────────────────── */
function addCmd(_cmd) {
  if (!isset(_cmd)) _cmd = {};
  if (!isset(_cmd.configuration)) _cmd.configuration = {};

  var selType = '<select class="cmdAttr form-control input-sm" data-l1key="type">'
    + '<option value="info">{{Info}}</option>'
    + '<option value="action">{{Action}}</option>'
    + '</select>';

  var selSType = '<span class="itype"><select class="cmdAttr form-control input-sm" data-l1key="subType"></select></span>';

  var tr = '<tr class="cmd cmd-widget" data-version="2">'
    + '<td><input class="cmdAttr form-control input-sm" data-l1key="id" style="display:none;" />'
    +    '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}" /></td>'
    + '<td class="itype">' + selType + '</td>'
    + '<td>' + selSType + '</td>'
    + '<td><input class="cmdAttr form-control input-sm" data-l1key="logicalId" placeholder="logicalId" /></td>'
    + '<td><input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" style="width:60px;" /></td>'
    + '<td><input type="checkbox" class="cmdAttr bootstrapSwitch" data-l1key="isHistorized" /></td>'
    + '<td><input type="checkbox" class="cmdAttr bootstrapSwitch" data-l1key="isVisible" /></td>'
    + '<td><a class="btn btn-default btn-sm cmdAction" data-action="configure"><i class="fas fa-cog"></i></a> '
    +    '<a class="btn btn-sm btn-danger cmdAction" data-action="remove"><i class="fas fa-minus-circle"></i></a></td>'
    + '</tr>';

  var $tr = $(tr);
  $tr.setValues(_cmd, '.cmdAttr');
  $('#table_cmd tbody').append($tr);
  $tr.find('.itype select').trigger('change');
}
