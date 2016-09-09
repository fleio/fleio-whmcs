<style>
	.panel-height-150 {
		height:150px;
	}
</style>

<div class="row">
    <div class="col-md-6">
        <div class="panel panel-default panel-height-150" id="cPanelPackagePanel">
            <div class="panel-heading">
                <h3 class="panel-title">Available credit: {if $summary} {$summary.credit} {$summary.currency} {/if}</h3>
            </div>
            <div class="panel-body">
                <div class="col-lg-8 col-lg-offset-2">
                    <form role="form" method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                        <input type="hidden" name="customAction" value="createflinvoice" />
                        <div class="input-group">
                            <span class="input-group-addon">{$currency.code}</span>
                            <input name="amount" type="number" min="{$minamount}" step="0.01" max="{$maxamount}" value="{$minamount}" class="form-control text-right">
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
        <div class="panel panel-default panel-height-150" id="cPanelPackagePanel1">
            <div class="panel-heading">
                <h3 class="panel-title">Summary:</h3>
            </div>
            <div class="panel-body">
                <div class="col-lg-4 col-lg-offset-4" >
					<ul class="text-left">
						<li>
							Instances: {$summary.instances}
						</li>
						<li>
							Images: {$summary.images}
						</li>
						<li>
							Volumes: {$summary.volumes}
						</li>
					</ul>
                </div>
            </div>
       </div>
	</div>
</div>

{if $configurableoptions}
    <div class="panel panel-default" id="cPanelConfigurableOptionsPanel">
        <div class="panel-heading">
            <h3 class="panel-title">{$LANG.orderconfigpackage}</h3>
        </div>
        <div class="panel-body">
            {foreach from=$configurableoptions item=configoption}
                <div class="row">
                    <div class="col-md-5 col-xs-6 text-right">
                        <strong>{$configoption.optionname}</strong>
                    </div>
                    <div class="col-md-7 col-xs-6 text-left">
                        {if $configoption.optiontype eq 3}{if $configoption.selectedqty}{$LANG.yes}{else}{$LANG.no}{/if}{elseif $configoption.optiontype eq 4}{$configoption.selectedqty} x {$configoption.selectedoption}{else}{$configoption.selectedoption}{/if}
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
{/if}
{if $customfields}
    <div class="panel panel-default" id="cPanelAdditionalInfoPanel">
        <div class="panel-heading">
            <h3 class="panel-title">{$LANG.additionalInfo}</h3>
        </div>
        <div class="panel-body">
            {foreach from=$customfields item=field}
                <div class="row">
                    <div class="col-md-5 col-xs-6 text-right">
                        <strong>{$field.name}</strong>
                    </div>
                    <div class="col-md-7 col-xs-6 text-left">
                        {if empty($field.value)}
                            {$LANG.blankCustomField}
                        {else}
                            {$field.value}
                        {/if}
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
{/if}
