{*
  Project: OJS QuickStatements Export
  Template: quickstatements
  Purpose:  OJS-native backend UI (full page or modal) for export/settings.

  Notes:
  - Use {translate key="…"} for all visible strings.
  - Keep markup accessible (labels, aria-*), RTL-friendly.
  - Notifications are printed into $resultHtml (nofilter when it’s safe/escaped).
  - Forms should use AjaxFormHandler inside modals.

  (c) 2025 Saddam Al-Slfi / Queen Arwa University
  License: GPL-3.0-or-later  |  SPDX-License-Identifier: GPL-3.0-or-later
*}

{* إشعار نجاح اختياري *}
{if $saved}
    <div class="pkpNotification pkpNotification-success" role="status" aria-live="polite" style="margin-bottom:10px;">
        {translate key="common.saved"}
    </div>
{/if}

<form class="pkp_form" id="qsSettingsForm" method="post" action="{$formAction|escape}">
    {if $csrfToken}
        <input type="hidden" name="csrfToken" value="{$csrfToken|escape}" />
    {/if}

    <div class="section">
        <h3 style="margin-top:0;">{translate key="plugins.importexport.quickstatements.settings.heading"}</h3>

        <div class="formSection">
            <label for="journalQid"><strong>{translate key="plugins.importexport.quickstatements.settings.journalQid.label"}</strong></label><br/>
            <input type="text" class="field text" id="journalQid" name="journalQid" value="{$journalQid|escape}" style="width:100%;"/>
            <div class="description">{translate key="plugins.importexport.quickstatements.settings.journalQid.example"}</div>
        </div>

        <div class="formSection">
            <label for="prefLangs"><strong>{translate key="plugins.importexport.quickstatements.settings.prefLangs.label"}</strong></label><br/>
            <input type="text" class="field text" id="prefLangs" name="prefLangs" value="{$prefLangs|escape}" placeholder="{translate key='plugins.importexport.quickstatements.settings.prefLangs.placeholder'}" style="width:100%;"/>
            <div class="description">{translate key="plugins.importexport.quickstatements.settings.prefLangs.help"}</div>
        </div>

        <hr/>

        <div class="formSection">
            <label for="qsUsername"><strong>{translate key="plugins.importexport.quickstatements.settings.qsUsername.label"}</strong></label><br/>
            <input type="text" class="field text" id="qsUsername" name="qsUsername" value="{$qsUsername|escape}" placeholder="{translate key='plugins.importexport.quickstatements.settings.qsUsername.placeholder'}" style="width:100%;"/>
        </div>

        <div class="formSection">
            <label for="qsBatchPrefix"><strong>{translate key="plugins.importexport.quickstatements.settings.qsBatchPrefix.label"}</strong></label><br/>
            <input type="text" class="field text" id="qsBatchPrefix" name="qsBatchPrefix" value="{$qsBatchPrefix|escape}" placeholder="{translate key='plugins.importexport.quickstatements.settings.qsBatchPrefix.placeholder'}" style="width:100%;"/>
        </div>

        <div class="formSection">
            <label for="qsToken"><strong>{translate key="plugins.importexport.quickstatements.settings.qsToken.label"}</strong></label><br/>
            <input type="text" class="field text" id="qsToken" name="qsToken" value="{$qsToken|escape}" style="width:100%;" dir="ltr"/>
            <div class="description">{translate key="plugins.importexport.quickstatements.settings.qsToken.help"}</div>
        </div>

        <div class="formSection">
            <label class="context">
                <input type="checkbox" id="qsAutoSubmit" name="qsAutoSubmit" value="1" {if $qsAutoSubmit}checked="checked"{/if}/>
                {translate key="plugins.importexport.quickstatements.settings.qsAutoSubmit.label"}
            </label>
            <div class="description">{translate key="plugins.importexport.quickstatements.settings.qsAutoSubmit.help"}</div>
        </div>
    </div>

    <div class="buttons">
        <button class="pkp_button pkp_button_primary" type="submit">{translate key="common.save"}</button>
    </div>
</form>

{if $isModal}
{literal}
<script>
(function($) {
    $(function() {
        $('#qsSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    });
})(jQuery);
</script>
{/literal}
{/if}
