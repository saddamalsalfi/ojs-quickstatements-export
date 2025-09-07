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


{* نتيجة الإرسال (إن كانت موجودة) *}
{if $resultHtml}
  <div style="margin-bottom:12px;">{$resultHtml nofilter}</div>
{/if}

{* اجمع قيم الطلب حتى نسترجع الاختيارات بعد الإرسال *}
{assign var=_mode value=$smarty.request.mode|default:'all'}
{assign var=_fmt  value=$smarty.request.fmt|default:'tsv'}

{* صناديق اختيار: افتراضيًا مُفعّلة مثل السابق *}
{assign var=_withLabels    value=$smarty.request.withLabels|default:1}
{assign var=_includeAuthors value=$smarty.request.includeAuthors|default:1}
{assign var=_resolveAffil   value=$smarty.request.resolveAffil|default:1}
{assign var=_includeRefs    value=$smarty.request.includeRefs|default:0}
{assign var=_resolveRefs    value=$smarty.request.resolveRefs|default:0}
{assign var=_updateIfExists value=$smarty.request.updateIfExists|default:1}

<div class="pkp_form">
  <form class="qsExportForm" method="get" action="{$exportAction|escape}" autocomplete="off">
    <input type="hidden" name="verb" value="export"/>

    <div class="fields">
      <div class="field">
        <label class="label" for="mode">{translate key="plugins.importexport.quickstatements.export.scope"}</label>
        <select class="field select" name="mode" id="mode" aria-describedby="modeHelp">
          <option value="all"    {if $_mode == 'all'}selected="selected"{/if}>{translate key="plugins.importexport.quickstatements.export.scope.all"}</option>
          <option value="issues" {if $_mode == 'issues'}selected="selected"{/if}>{translate key="plugins.importexport.quickstatements.export.scope.issues"}</option>
          <option value="ids"    {if $_mode == 'ids'}selected="selected"{/if}>{translate key="plugins.importexport.quickstatements.export.scope.submissions"}</option>
        </select>
        <div id="modeHelp" class="description">{translate key="plugins.importexport.quickstatements.export.scope.help"}</div>
      </div>

      <div class="field">
        <label class="label" for="ids">IDs</label>
        <input class="field text" type="text" name="ids" id="ids" placeholder="12,15,19"
               value="{$smarty.request.ids|escape}" dir="ltr" aria-describedby="idsHelp"/>
        <div id="idsHelp" class="description">{translate key="plugins.importexport.quickstatements.export.ids.help"}</div>
      </div>

      <div class="field">
        <label class="label" for="fmt">{translate key="plugins.importexport.quickstatements.export.format"}</label>
        <select class="field select" name="fmt" id="fmt">
          <option value="tsv"      {if $_fmt == 'tsv'}selected="selected"{/if}>{translate key="plugins.importexport.quickstatements.export.format.tsv"}</option>
          <option value="commands" {if $_fmt == 'commands'}selected="selected"{/if}>{translate key="plugins.importexport.quickstatements.export.format.commands"}</option>
        </select>
      </div>

      <div class="field">
        <label class="context"><input type="checkbox" name="withLabels" value="1" {if $_withLabels}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.withLabels"}</label>
        <label class="context"><input type="checkbox" name="includeAuthors" value="1" {if $_includeAuthors}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.includeAuthors"}</label>
        <label class="context"><input type="checkbox" name="resolveAffil" value="1" {if $_resolveAffil}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.resolveAffil"}</label>
        <label class="context"><input type="checkbox" name="includeRefs" value="1" {if $_includeRefs}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.includeRefs"}</label>
        <label class="context"><input type="checkbox" name="resolveRefs" value="1" {if $_resolveRefs}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.resolveRefs"}</label>
        <label class="context"><input type="checkbox" name="updateIfExists" value="1" {if $_updateIfExists}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.updateIfExists"}</label>
      </div>
    </div>

    <div class="buttons">
      <button type="submit" class="pkp_button js-submit">
        {translate key="plugins.importexport.quickstatements.export.run"}
      </button>
    </div>
  </form>
</div>

{literal}
<script>
(function($){
  $(function(){
    // اربط النموذج بـ AjaxFormHandler ليُحدّث محتوى المودال بمخرجات JSONMessage
    $('.qsExportForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

    // تجربة مستخدم ألطف: عطّل زر الإرسال أثناء التنفيذ
    $('.qsExportForm').on('submit', function(){
      var $btn = $(this).find('.js-submit');
      $btn.prop('disabled', true);
      if (!$btn.data('orig')) $btn.data('orig', $btn.text());
      $btn.text($btn.text() + ' …');
    });
  });
})(jQuery);
</script>
{/literal}
