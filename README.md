# Wikidata QuickStatements Export — OJS Plugin

An Open Journal Systems (OJS) import/export plugin that exports **published articles** to **Wikidata** through **QuickStatements v2**.
It supports **file download** (TSV or QuickStatements command text) and **automatic deposit** to QuickStatements via its HTTP **API**, with clear success/error notifications and a direct link to the created batch.

> You’ll find the plugin under **Tools → Import/Export**. It also adds **Settings** and **Export** quick-action buttons beneath the plugin in the Plugins list. Both **full-page** and **modal** UIs follow OJS styles and use AJAX where appropriate.

---

## Table of Contents

* [Features](#features)
* [Requirements & Compatibility](#requirements--compatibility)
* [Installation](#installation)
* [Enabling the Plugin](#enabling-the-plugin)
* [Configuration](#configuration)
* [Usage](#usage)

  * [Export scopes & formats](#export-scopes--formats)
  * [Optional enrichment](#optional-enrichment)
  * [Full-page vs Modal](#full-page-vs-modal)
* [Automatic deposit to QuickStatements](#automatic-deposit-to-quickstatements)
* [Data mapping (Wikidata properties)](#data-mapping-wikidata-properties)
* [Localization](#localization)
* [Directory Layout](#directory-layout)
* [Troubleshooting](#troubleshooting)
* [Security Notes](#security-notes)
* [Contributing](#contributing)
* [Credits](#credits)
* [License](#license)

---

## Features

* **Flexible export scopes**

  * All **published submissions** in the journal
  * By **issue IDs**
  * By **submission IDs**
* **Two output formats**

  * **TSV** (for manual QuickStatements upload)
  * **QuickStatements v1 command text**
* **Automatic deposit (API)**

  * Sends commands directly to **quickstatements.toolforge.org/api.php**
  * Shows an in-app success notice with a **direct batch link**:

    ```
    https://quickstatements.toolforge.org/#/batch/{batch_id}
    ```
* **Optional enrichment**

  * **Labels/Descriptions** honoring **preferred language order**
  * **Authors** as string statements (**P2093**) with **series ordinal** (**P1545**)
  * Best-effort mapping of **affiliations** to Wikidata (**P1416**) by label
  * **Citations** (**P2860**) if reference DOIs resolve to Wikidata QIDs
  * **Update-if-exists**: detect an existing item by **DOI** and **update** instead of creating
* **Polished OJS UX**

  * Full backend page extends `layouts/backend.tpl`
  * Modal dialogs return `JSONMessage` and use `AjaxFormHandler` for inline notifications
  * Retains user selections after submit (page & modal)

---

## Requirements & Compatibility

* **OJS 3.5.x ** (tested with TemplateManager, Repo facade, SettingsPluginGridHandler)
* A Wikidata account and access to **QuickStatements**
  [https://quickstatements.toolforge.org/](https://quickstatements.toolforge.org/)

> Other OJS 3.x versions may work with minor routing/handler adjustments.

---

## Installation

Place the plugin inside `plugins/importexport/quickstatements`:

```
ojs/
└── plugins/
    └── importexport/
        └── quickstatements/
            index.php
            QuickStatementsPlugin.inc.php
            QuickStatementsBuilder.inc.php
            version.xml
            README.md
            locale/
            templates/
```

If using Git:

```bash
cd plugins/importexport
git clone <repo-url> quickstatements
```

Then clear template caches from the OJS admin (or remove `cache/t_compile` carefully).

---

## Enabling the Plugin

1. Sign in as **Journal Manager / Site Admin**.
2. Go to **Settings → Website → Plugins**.
3. Enable **Wikidata QuickStatements Export**.
4. Open **Tools → Import/Export → Wikidata QuickStatements Export** (or use the **Export** button under the plugin).

---

## Configuration

Open **Settings** (from the plugin row or inside the tool). The form follows OJS styling, includes CSRF protection, and saves via AJAX.

* **Journal QID (P1433)**
  The Wikidata item for your journal, e.g. `Q124499613`. Used for **“published in”**.
* **Preferred language codes**
  Comma-separated (e.g. `ar,en`). Determines the order for labels/titles.
* **qsUsername**
  Your QuickStatements username (e.g. `Saddam_Hussein_Alsalfi`).
* **qsBatchPrefix** *(optional)*
  Prefix added to generated batch names (e.g. `QAUSRJ`).
* **qsToken**
  Your **QuickStatements API token** (looks like `$2y$10$…`). Acquire it from QuickStatements while logged in.
* **qsAutoSubmit**
  If enabled, the plugin **sends** the generated commands directly to QuickStatements instead of downloading a file.

---

## Usage

Open the tool page (full page) or click **Export** under the plugin (modal).

### Export scopes & formats

* **Scope**

  * **All** published submissions
  * **Issues** (enter issue IDs)
  * **Submissions** (enter submission IDs)
* **IDs**
  Only required when Scope = Issues or Submissions. Example: `12,15,19`
* **Format**

  * **TSV** — for manual upload
  * **Commands** — QuickStatements v1 text

### Optional enrichment

* **With labels**: output `Lxx`/`Dxx` and **P1476** (title) in the preferred language
* **Include authors**: **P2093** (author name string) + **P1545** (author order)
* **Resolve affiliations**: try to link affiliations to **P1416** (best-effort, slower)
* **Include references**: add **P2860** when reference DOIs resolve to QIDs
* **Resolve reference DOIs**: try to resolve DOIs in references (slower)
* **Update if DOI exists**: look up the DOI first; if found, **update** that QID

### Full-page vs Modal

* **Full page**
  Uses OJS backend layout; on auto-submit success it shows a **green notice** with a link to the created batch.
* **Modal**
  Uses `JSONMessage` and `AjaxFormHandler`; the modal content refreshes with an inline success/error message and a batch link (if applicable).

---

## Automatic deposit to QuickStatements

When **qsAutoSubmit** is enabled (and **qsUsername** + **qsToken** are set):

1. The plugin generates **QuickStatements v1** commands.

2. It POSTs to:

   ```
   https://quickstatements.toolforge.org/api.php
   action=import
   submit=1
   format=v1
   username=<qsUsername>
   batchname=<prefix + journal name + timestamp>
   token=<qsToken>
   data=<commands>
   ```

3. Requests include a Toolforge-friendly **User-Agent**, e.g.
   `OJS-QuickStatements-Plugin/1.0 (+https://your-host; contact admin@your-host)`

4. On success you’ll see:
   `status: "OK"` and a `batch_id`, and the UI will show a link:

   ```
   https://quickstatements.toolforge.org/#/batch/{batch_id}
   ```

5. If `status: "No commands"`, the UI shows an informational notice.

6. On errors (HTTP ≥ 400), the UI shows a red error notice with the HTTP code and a short response snippet.

---

## Data mapping (Wikidata properties)

Typical output includes:

* **P31 → Q13442814** (instance of: scholarly article)
* **P1476** (title) with language tag
* **P577** (publication date) with precise granularity
* **P1433** (published in) → journal QID from settings
* **P478** (volume), **P433** (issue), **P304** (pages)
* **P356** (DOI)
* **P407** (language of work) using a bundled language map (ar, en, fr, de, es, it, ru, fa, tr)
* **P2093** (author name string) + **P1545** (author order)
* **P2860** (cites work) if references resolve to QIDs
* **P1416** (affiliation) if an organization label is matched
* **P953** (full work available at) and **P856** (official website) when URLs exist

The **Update-if-exists** option uses DOI → QID resolution to target an existing item when possible.

---

## Localization

Translations live in:

```
locale/
├── ar/locale.po
└── en/locale.po
```

Add more languages by creating `<lang>/locale.po` and translating the same keys.

---

## Directory Layout

```
quickstatements/
│  index.php
│  QuickStatementsPlugin.inc.php
│  QuickStatementsBuilder.inc.php
│  version.xml
│  README.md
│
├─ locale/
│  ├─ ar/locale.po
│  └─ en/locale.po
│
└─ templates/
   │  index.tpl          # full backend page (OJS layout)
   │  index_modal.tpl    # export modal (AJAX)
   │  settings.tpl       # settings form (page & modal)
```

**Core files**

* `QuickStatementsPlugin.inc.php` — routing, UI wiring (page + modal), settings, export orchestration, QS API calls, and UI notifications.
* `QuickStatementsBuilder.inc.php` — transforms OJS submissions into QuickStatements v1 commands or TSV; language handling; optional enrichment.

---

## Troubleshooting

* **403 — “Requests must have a user agent”**
  Toolforge requires a clear **User-Agent**. The plugin sets one automatically:
  `OJS-QuickStatements-Plugin/1.0 (+https://your-host; contact admin@your-host)`

* **404 when saving settings**
  Ensure settings POST to `ROUTE_COMPONENT` with `SettingsPluginGridHandler` and `verb=saveSettings`. The included templates do this.

* **No fields in modal**
  The modal must call `manage?verb=exportForm` (or `settings`) and return a `JSONMessage`. The templates attach `$.pkp.controllers.form.AjaxFormHandler`.

* **“No commands”**
  Nothing matched your filters. Confirm the items are **published** and that the chosen scope/IDs are correct.

* **Existing items not updated**
  Make sure **Update if DOI exists** is checked and that DOIs match what’s in Wikidata (case-insensitive normalization is applied).

---

## Security Notes

* Treat **qsToken** as a secret. Do **not** commit it or paste it publicly.
* Restrict access to the settings page to appropriate OJS roles.
* Strip tokens from logs or screenshots before sharing.

---

## Contributing

Issues and PRs are welcome. Please:

* Follow OJS routing conventions (`\Application::get()->getDispatcher()->url`).
* Keep UI consistent with OJS patterns (backend layouts, `JSONMessage`, `AjaxFormHandler`).
* Add new UI strings to **both** `locale/en/locale.po` and `locale/ar/locale.po`.

---

## Credits

**By Saddam Al-Slfi** — *Queen Arwa University*
📧 **[saddamalsalfi@qau.edu.ye](mailto:saddamalsalfi@qau.edu.ye)**

---

## License

This plugin is released under the **GNU General Public License v3 (GPL-3.0-or-later)**.
**SPDX-License-Identifier:** `GPL-3.0-or-later`

See the **LICENSE** file for the full license text.
