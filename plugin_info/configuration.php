<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-network-wired"></i> {{Démon}}</legend>
        <div class="form-group">
            <label class="col-xs-4 control-label">{{Port socket interne}}</label>
            <div class="col-xs-2">
                <input type="number" class="configKey form-control" data-l1key="socketport" placeholder="55010" />
            </div>
            <span class="col-xs-4 help-block">
                {{Port TCP local entre le démon Python et Jeedom (défaut : 55010). Ne modifier qu'en cas de conflit.}}
            </span>
        </div>
    </fieldset>
</form>