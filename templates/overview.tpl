<style>
    .panel-height-150 {
        height:150px;
    }
    .flnowraptext { white-space: nowrap;};
    .fldisplayblock { display: block};
</style>

<div class="row">
    <div class="col-md-8">
        <div class="panel panel-default panel-height-150" id="FleioAddCreditPanel">
            <div class="panel-heading">
                <h3 class="panel-title">Add additional cloud credit</h3>
            </div>
            <div class="panel-body">
                
                <div class="col-lg-10 col-lg-offset-1">
                    <form role="form" method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                        <input type="hidden" name="customAction" value="createflinvoice" />
                        <div class="input-group">
                            <span class="input-group-addon">{$currency.code}</span>
                            <input name="amount" type="number" min="{$minamount}" step="0.01" max="{$maxamount}" value="{$minamount}" class="form-control text-right">
                            <span class="input-group-btn">
                             <input type="submit" value="Create invoice" class="btn btn-success">
                            </span>
                        </div>
						{if $tax1_rate}
						<span class="flnowraptext fldisplayblock">{$tax1_rate}% VAT will be added to the amount you fill in.</span>
						{/if}
                        {if $uptodateCredit}
                        <span class="flnowraptext fldisplayblock">Current cloud credit: {$uptodateCredit}</span>
                        {/if}
                        {if $outofcreditDatetime}
                        <span class="flnowraptext fldisplayblock text-danger">Your account is out of credit since: {$outofcreditDatetime|date_format}</span>
                        {/if}
                    </form>
                </div>
            </div>
           {if $validateAmountError}
            <div class="text-danger text-center limit-near">{$validateAmountError}</div>
           {/if}
       </div>
    </div>
</div>

