<?php

/**
 * Project: OJS QuickStatements Export
 * File:    quickstatements
 * Summary: Export issues/articles from OJS to Wikidata via QuickStatements.
 *
 * Description:
 * - Builds QuickStatements commands from OJS submissions.
 * - Optional auto-submit to QuickStatements API with token.
 * - DOI de-duplication against Wikidata (update if exists).
 * - Arabic/English localization, modal settings via Ajax, OJS-native UI.
 *
 * Requirements: OJS/PKP 3.x (tested with recent 3.x)
 * Maintainer:  Saddam Al-Slfi <saddamalsalfi@qau.edu.ye>, Queen Arwa University
 * Repository:  https://github.com/saddamalsalfi/ojs-quickstatements-export
 * Issues:      https://github.com/saddamalsalfi/ojs-quickstatements-export/issues
 *
 * (c) 2025 Saddam Al-Slfi / Queen Arwa University. All rights reserved.
 *
 * License: GNU General Public License v3.0 or later
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * This file is part of the "OJS QuickStatements Export" plugin.
 * See the LICENSE file distributed with this source for full terms.
 *
 * Security note:
 * - Escape all user-facing output (TemplateManager assigns, Smarty templates).
 * - Validate/normalize external inputs (DOIs, usernames, tokens).
 * - Provide a descriptive User-Agent when calling external APIs (Toolforge).
 */

require_once('QuickStatementsPlugin.inc.php');
return new QuickStatementsPlugin();
