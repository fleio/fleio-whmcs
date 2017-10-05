<style>
    .panel-height-150 {
        height:150px;
    }
</style>

<div class="row">
    <div class="col-md-6">
        <div class="panel panel-default panel-height-150" id="FleioAddCreditPanel">
            <div class="panel-heading">
                <h3 class="panel-title">Available cloud credit: {if $summary} {$summary.billing_credit} {$summary.billing_currency} {/if}</h3>
            </div>
            <div class="panel-body">
                <span>The last billing cycle cost was: {$last_usage_price} {$currency.code} {if ($summary.client_currency ne $currency.code) } ({$summary.last_usage_price} {$summary.client_currency}) {/if}</span>
                <div class="col-lg-10 col-lg-offset-1">
                    <form role="form" method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                        <input type="hidden" name="customAction" value="createflinvoice" />
                        <div class="input-group">
                            <span class="input-group-addon">{$currency.code}</span>
                            {if $last_usage_price && $last_usage_price ge $minamount && $last_usage_price le $maxamount}
                              <input name="amount" type="number" min="{$minamount}" step="0.01" max="{$maxamount}" value="{$last_usage_price}" class="form-control text-right">
                            {else}
                              <input name="amount" type="number" min="{$minamount}" step="0.01" max="{$maxamount}" value="{$minamount}" class="form-control text-right">
                            {/if}
                            <span class="input-group-btn">
                                <input type="submit" value="Add funds" class="btn btn-success">
                            </span>
                        </div>
                    </form>
                </div>
            </div>
           {if $validateAmountError}
            <div class="text-danger text-center limit-near">{$validateAmountError}</div>
           {/if}
       </div>
    </div>

    <div class="col-md-6">
        <div class="panel panel-default panel-height-150" id="FleioSummaryPanel">
            <div class="panel-heading">
                <h3 class="panel-title">Summary:</h3>
            </div>
            <div class="panel-body">
                <div class="col-lg-4 col-lg-offset-4" style="margin: 0; padding: 2;">
                    <ul class="text-left">
                        <li>Instances:&nbsp;{$summary.instances}</li>
                        <li>Images:&nbsp;{$summary.images}</li>
                        <li>Volumes:&nbsp;{$summary.volumes}</li>
                    </ul>
                </div>
            </div>
       </div>
    </div>
</div>

