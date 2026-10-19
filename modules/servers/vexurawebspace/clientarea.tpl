<section class="panel panel-default" aria-label="{$vxName|escape:'html'}">
    <div class="panel-heading"><h3 class="panel-title">{$vxName|escape:'html'}</h3></div>
    <div class="panel-body">
        {if $vxError}<div class="alert alert-warning" role="alert">{$vxError|escape:'html'}</div>{/if}
        {if $vxNotice}<p>{$vxNotice|escape:'html'}</p>{/if}
        {if $vxRows}
            <dl class="row">
                {foreach $vxRows as $row}
                    <dt class="col-sm-4">{$row.label|escape:'html'}</dt>
                    <dd class="col-sm-8" style="overflow-wrap:anywhere">{$row.value|escape:'html'}</dd>
                {/foreach}
            </dl>
        {/if}
        {if $vxControls}
            <form method="post" action="clientarea.php?action=productdetails&amp;id={$vxServiceId}">
                <input type="hidden" name="token" value="{$token|escape:'html'}">
                <div class="checkbox"><label><input type="checkbox" name="vx_confirm" value="1" required> Ich bestätige die gewählte Aktion.</label></div>
                {foreach $vxControls as $key => $control}
                    <button class="btn btn-default" type="submit" name="vx_action" value="{$key|escape:'html'}">{$control.label|escape:'html'}</button>
                {/foreach}
            </form>
        {/if}
        {foreach $vxSecrets as $secret}
            <h4>{$secret.label|escape:'html'}</h4>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere">{$secret.value|escape:'html'}</pre>
        {/foreach}
    </div>
</section>
