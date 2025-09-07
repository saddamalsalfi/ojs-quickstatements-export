
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


{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="pkp_page_content pkp_page_importexport_plugins">
  <h1>{$plugin->getDisplayName()|escape}</h1>
  <p class="pkp_help">{$plugin->getDescription()|escape}</p>

{if $resultHtml}
  <div id="qsResultZone" class="pkp_form" style="margin-bottom:12px;">
    {$resultHtml nofilter}
  </div>

  {literal}
  <script>
  (function(){
    try {
      var el = document.getElementById('qsResultZone');
      if (el) {
        el.setAttribute('tabindex','-1');
        el.focus({preventScroll:true});
        el.scrollIntoView({behavior:'smooth', block:'start'});
      }
    } catch(e){}
  })();
  </script>
  {/literal}
{/if}


  {assign var=_mode value=$smarty.request.mode|default:'all'}
  {assign var=_fmt  value=$smarty.request.fmt|default:'tsv'}

  {assign var=_withLabels    value=$smarty.request.withLabels|default:1}
  {assign var=_includeAuthors value=$smarty.request.includeAuthors|default:1}
  {assign var=_resolveAffil   value=$smarty.request.resolveAffil|default:0}
  {assign var=_includeRefs    value=$smarty.request.includeRefs|default:1}
  {assign var=_resolveRefs    value=$smarty.request.resolveRefs|default:1}
  {assign var=_updateIfExists value=$smarty.request.updateIfExists|default:1}

  <div class="pkp_form">
    <form method="get" action="{$exportAction|escape}" autocomplete="off">
      <input type="hidden" name="verb" value="export"/>

      <fieldset class="fields">
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
        </div>

        <div class="field">
          <label class="context"><input type="checkbox" name="includeAuthors" value="1" {if $_includeAuthors}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.includeAuthors"}</label>
          <label class="context"><input type="checkbox" name="resolveAffil" value="1" {if $_resolveAffil}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.resolveAffil"}</label>
          <label class="context"><input type="checkbox" name="includeRefs" value="1" {if $_includeRefs}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.includeRefs"}</label>
          <label class="context"><input type="checkbox" name="resolveRefs" value="1" {if $_resolveRefs}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.resolveRefs"}</label>
          <label class="context"><input type="checkbox" name="updateIfExists" value="1" {if $_updateIfExists}checked="checked"{/if}/> {translate key="plugins.importexport.quickstatements.export.updateIfExists"}</label>
        </div>
      </fieldset>

      <div class="buttons">
        <button type="submit" class="pkp_button">
          {translate key="plugins.importexport.quickstatements.export.run" default="Run export"}
        </button>
      </div>
    </form>
  </div>
</div>
{/block}
