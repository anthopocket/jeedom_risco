<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin   = plugin::byId('risco');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">

    <!-- ══ LISTE ══ -->
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-shield-alt"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-shield-alt"></i> {{Mes centrales Risco}}</legend>
        <div class="eqLogicThumbnailContainer">
            <?php foreach ($eqLogics as $eq):
                $host     = trim($eq->getConfiguration('host', ''));
                $hasIp    = $host !== '';
                $connCmd  = $eq->getCmd('info', 'connected');
                $isOnline = is_object($connCmd) && ((int)$connCmd->execCmd() === 1);
            ?>
                <div class="eqLogicDisplayCard cursor <?php echo $eq->getIsEnable() ? '' : 'opacity05'; ?>"
                     data-eqLogic_id="<?php echo $eq->getId(); ?>">
                    <img src="<?php echo $eq->getImage(); ?>" />
                    <br>
                    <span class="name"><?php echo $eq->getHumanName(true, true); ?></span>
                    <span class="hiddenAsCard displayTableRight">
                        <?php if ($host): ?>
                            <span class="label label-default"><?php echo htmlspecialchars($host); ?></span>
                        <?php endif; ?>
                        <?php if (!$hasIp): ?>
                            <span class="label label-warning">{{IP manquante}}</span>
                        <?php elseif ($isOnline): ?>
                            <span class="label label-success">{{Connecté}}</span>
                        <?php else: ?>
                            <span class="label label-danger">{{Hors ligne}}</span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ FORMULAIRE ÉQUIPEMENT ══ -->
    <div class="col-xs-12 eqLogic" style="display:none;">

        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure">
                    <i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save">
                    <i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove">
                    <i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a href="#" class="eqLogicAction" role="tab" data-toggle="tab"
                   data-action="returnToThumbnailDisplay">
                    <i class="fas fa-arrow-circle-left"></i>
                </a>
            </li>
            <li role="presentation" class="active">
                <a href="#eqlogictab" role="tab" data-toggle="tab">
                    <i class="fas fa-tachometer-alt"></i> {{Équipement}}
                </a>
            </li>
            <li role="presentation">
                <a href="#commandtab" role="tab" data-toggle="tab">
                    <i class="fas fa-list"></i> {{Commandes}}
                </a>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ─ Onglet Équipement ─ -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br>
                <form class="form-horizontal">
                    <input type="hidden" class="eqLogicAttr" data-l1key="id" />
                    <input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="zones_discovered" />
                    <input type="hidden" class="eqLogicAttr" data-l1key="configuration" data-l2key="partitions_discovered" />

                    <div class="col-lg-6">
                        <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Nom}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="name" placeholder="{{Nom de la centrale}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Objet parent}}</label>
                            <div class="col-sm-8">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php foreach (jeeObject::buildTree(null, false) as $obj): ?>
                                        <option value="<?php echo $obj->getId(); ?>">
                                            <?php echo str_repeat('&nbsp;&nbsp;', $obj->getConfiguration('parentNumber')); ?>
                                            <?php echo $obj->getName(); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Catégorie}}</label>
                            <div class="col-sm-8">
                                <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value): ?>
                                    <label class="checkbox-inline">
                                        <input type="checkbox" class="eqLogicAttr"
                                               data-l1key="category" data-l2key="<?php echo $key; ?>" />
                                        <?php echo $value['name']; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label"></label>
                            <div class="col-sm-8">
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" /> {{Activer}}
                                </label>
                                <label class="checkbox-inline">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" /> {{Visible}}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <legend><i class="fas fa-network-wired"></i> {{Connexion locale Risco}}</legend>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Adresse IP}}</label>
                            <div class="col-sm-8">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="host"
                                       placeholder="ex : 192.168.1.100" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Port TCP}}</label>
                            <div class="col-sm-4">
                                <input type="number" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="port"
                                       placeholder="1000" />
                            </div>
                            <span class="col-sm-4 help-block">{{défaut : 1000}}</span>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Code PIN}}</label>
                            <div class="col-sm-6">
                                <input type="password" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="pin"
                                       placeholder="4-6 chiffres" />
                            </div>
                        </div>

                        <legend><i class="fas fa-sliders-h"></i> {{Options}}</legend>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Zones actives}}
                                <sup><i class="fas fa-question-circle tooltips"
                                    title="{{Numéros de zones séparés par virgule (ex: 1,2,3,5). Laissez vide ou 'auto' pour détection automatique au démarrage du démon.}}"></i></sup>
                            </label>
                            <div class="col-sm-5">
                                <input type="text" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="zones_list"
                                       placeholder="auto (détection automatique)" />
                            </div>
                            <div class="col-sm-3">
                                <button class="btn btn-default btn-sm" id="bt_reset_zones" type="button">
                                    <i class="fas fa-redo"></i> {{Auto}}
                                </button>
                                <button class="btn btn-info btn-sm" id="bt_sync_zones" type="button" style="margin-top:4px;">
                                    <i class="fas fa-sync"></i> {{Synchroniser}}
                                </button>
                            </div>
                        </div>
                        <div class="form-group" id="div_zones_discovered" style="display:none;">
                            <label class="col-sm-4 control-label">{{Zones détectées}}</label>
                            <div class="col-sm-8">
                                <span id="span_zones_discovered" class="label label-info"></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Nb partitions}}</label>
                            <div class="col-sm-3">
                                <input type="number" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="nb_partitions"
                                       placeholder="1" min="1" max="32" />
                            </div>
                            <label class="col-sm-2 control-label">{{Nb zones}}</label>
                            <div class="col-sm-3">
                                <input type="number" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="nb_zones"
                                       placeholder="32" min="1" max="512" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{PIN pour armer}}</label>
                            <div class="col-sm-2">
                                <input type="checkbox" class="eqLogicAttr bootstrapSwitch"
                                       data-l1key="configuration" data-l2key="pin_arm" />
                            </div>
                            <label class="col-sm-4 control-label">{{PIN pour désarmer}}</label>
                            <div class="col-sm-2">
                                <input type="checkbox" class="eqLogicAttr bootstrapSwitch"
                                       data-l1key="configuration" data-l2key="pin_disarm" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Bypass zones}}</label>
                            <div class="col-sm-2">
                                <input type="checkbox" class="eqLogicAttr bootstrapSwitch"
                                       data-l1key="configuration" data-l2key="allow_bypass" />
                            </div>
                            <span class="col-sm-6 help-block">{{Crée les commandes bypass par zone}}</span>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Délai communication (s)}}
                                <sup><i class="fas fa-question-circle tooltips"
                                    title="{{Délai entre connexion TCP et premier envoi. Augmentez (ex: 0.5) si timeout sur RID}}"></i></sup>
                            </label>
                            <div class="col-sm-3">
                                <input type="number" step="0.1" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="communication_delay"
                                       placeholder="1" min="0" max="5" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-4 control-label">{{Concurrence max}}</label>
                            <div class="col-sm-3">
                                <input type="number" class="eqLogicAttr form-control"
                                       data-l1key="configuration" data-l2key="concurrency"
                                       placeholder="4" min="1" max="8" />
                            </div>
                            <span class="col-sm-5 help-block">{{Réduire à 1 ou 2 si erreurs de timeout}}</span>
                        </div>
                        <div class="form-group">
                            <div class="col-sm-offset-4 col-sm-8">
                                <button class="btn btn-info btn-sm" id="bt_recreate_cmds" type="button">
                                    <i class="fas fa-sync"></i> {{Recréer les commandes}}
                                </button>
                            </div>
                        </div>
                    </div>

                </form>
            </div>

            <!-- ─ Onglet Commandes ─ -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">{{ID}}</th>
                                <th style="min-width:150px;width:300px;">{{Nom}}</th>
                                <th style="width:130px;">{{Type}}</th>
                                <th style="min-width:200px;">{{Paramètres}}</th>
                                <th style="min-width:80px;width:120px;">{{Etat}}</th>
                                <th style="min-width:200px;width:300px;">{{Options}}</th>
                                <th style="min-width:80px;width:160px;">{{Action}}</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
$(function () {

    /* ── Synchroniser les zones depuis la centrale ── */
    $('#bt_sync_zones').on('click', function () {
        var id = $('.eqLogicAttr[data-l1key="id"]').val();
        if (!id) {
            $.fn.showAlert({ message: '{{Sauvegardez l equipement}}', level: 'warning' });
            return;
        }
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        $.ajax({
            type     : 'POST',
            url      : 'plugins/risco/core/ajax/risco.ajax.php',
            data     : { action: 'sync_zones', id: id, apikey: userProfils.hash },
            dataType : 'json',
            success  : function (r) {
                btn.prop('disabled', false).html('<i class="fas fa-sync"></i> {{Synchroniser}}');
                if (r.state !== 'ok') {
                    $.fn.showAlert({ message: r.result, level: 'danger' });
                    return;
                }
                $.fn.showAlert({ message: '{{Synchronisation lancée — rechargez dans 5 secondes}}', level: 'success' });
                setTimeout(function () {
                    $('.eqLogicDisplayCard[data-eqLogic_id="' + id + '"]').trigger('click');
                }, 5000);
            },
            error: function (xhr) {
                btn.prop('disabled', false).html('<i class="fas fa-sync"></i> {{Synchroniser}}');
                $.fn.showAlert({ message: 'Erreur HTTP ' + xhr.status, level: 'danger' });
            }
        });
    });

    /* ── Reset zones vers auto ── */
    $('#bt_reset_zones').on('click', function () {
        $('.eqLogicAttr[data-l2key="zones_list"]').val('auto');
        $.fn.showAlert({ message: '{{Zones réinitialisées en auto — sauvegardez et redémarrez le démon}}', level: 'info' });
    });

    /* ── Afficher les zones découvertes ── */
    $(document).on('eqLogic::updated', function () {
        var discovered = $('.eqLogicAttr[data-l2key="zones_discovered"]').val();
        if (discovered && discovered !== '') {
            $('#span_zones_discovered').text(discovered);
            $('#div_zones_discovered').show();
        }
    });

    /* ── Recréer les commandes ── */
    $('#bt_recreate_cmds').on('click', function () {
        var id = $('.eqLogicAttr[data-l1key="id"]').val();
        if (!id) {
            $.fn.showAlert({ message: '{{Sauvegardez l equipement}}', level: 'warning' });
            return;
        }
        var btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{En cours...}}');
        $.ajax({
            type     : 'POST',
            url      : 'plugins/risco/core/ajax/risco.ajax.php',
            data     : { action: 'recreate_cmds', id: id, apikey: userProfils.hash },
            dataType : 'json',
            success  : function (r) {
                btn.prop('disabled', false).html('<i class="fas fa-sync"></i> {{Recréer les commandes}}');
                if (r.state !== 'ok') {
                    $.fn.showAlert({ message: r.result, level: 'danger' });
                    return;
                }
                $.fn.showAlert({ message: '{{Commandes recréées avec succès}}', level: 'success' });
                $('.eqLogicDisplayCard[data-eqLogic_id="' + id + '"]').trigger('click');
            },
            error: function (xhr) {
                btn.prop('disabled', false).html('<i class="fas fa-sync"></i> {{Recréer les commandes}}');
                $.fn.showAlert({ message: 'Erreur HTTP ' + xhr.status, level: 'danger' });
            }
        });
    });

});
</script>

<?php include_file('desktop', 'risco', 'js', 'risco'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>