<p id="dfrn-request-homecoming" >
V&auml;lkommen hem $username. 
<br />
Bekr&auml;fta your introduction to $dfrn_url.

</p>
<form id="dfrn-request-homecoming-form" action="dfrn_request/$nickname" method="post"> 
<input type="hidden" name="dfrn_url" value="$dfrn_url" />
<input type="hidden" name="confirm_key" value="$confirm_key" />
<input type="hidden" name="localconfirm" value="1" />
$aes_allow

<div id="dfrn-request-homecoming-submit-wrapper" >
<input id="dfrn-request-homecoming-submit" type="submit" name="submit" value="Bekr&auml;fta" />
</div>
</form>