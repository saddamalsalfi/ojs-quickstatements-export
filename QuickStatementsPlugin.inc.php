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


use PKP\plugins\ImportExportPlugin;
use APP\template\TemplateManager;
use APP\facades\Repo;

use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\core\JSONMessage;

class QuickStatementsPlugin extends ImportExportPlugin {

    public function register($category, $path, $mainContextId = null) {
        $ok = parent::register($category, $path, $mainContextId);
        if ($ok) { $this->addLocaleData(); }
        return $ok;
    }

    public function getName() { return 'quickstatements'; }

    public function getDisplayName() {
        $k = 'plugins.importexport.quickstatements.displayName';
        $t = __($k); return $t !== $k ? $t : 'Wikidata QuickStatements Export';
    }

    public function getDescription() {
        $k = 'plugins.importexport.quickstatements.description';
        $t = __($k); return $t !== $k ? $t : 'Export issues or articles to Wikidata using QuickStatements v2.';
    }

    /** أزرار أسفل الإضافة (مودالات) */
    public function getActions($request, $actionArgs) {
        $router  = $request->getRouter();
        $actions = parent::getActions($request, $actionArgs);

        // الإعدادات
        $actions[] = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null, null, 'manage', null,
                    ['verb'=>'settings','plugin'=>$this->getName(),'category'=>$this->getCategory()]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings')
        );

        // التصدير
        $actions[] = new LinkAction(
            'export',
            new AjaxModal(
                $router->url(
                    $request,
                    null, null, 'manage', null,
                    ['verb'=>'exportForm','plugin'=>$this->getName(),'category'=>$this->getCategory()]
                ),
                $this->getDisplayName()
            ),
            __('common.export')
        );

        return $actions;
    }

    /** العرض الكامل عندما تُفتح صفحة الإضافة من تبويب الأدوات */
    public function display($args, $request) {
        $verb = $request->getUserVar('verb'); if (!$verb && is_array($args) && !empty($args)) $verb = $args[0];

        if ($verb === 'settings') {
            echo $this->renderSettingsHtml($request, false, false);
            return;
        }

        if ($verb === 'saveSettings' && strtoupper($request->getRequestMethod()) === 'POST') {
            $this->validateCSRF($request);
            $context = $request->getContext(); $contextId = $context? $context->getId():null;

            $this->updateSetting($contextId, 'journalQid',    trim((string)$request->getUserVar('journalQid')));
            $this->updateSetting($contextId, 'prefLangs',     trim((string)$request->getUserVar('prefLangs')));
            $this->updateSetting($contextId, 'qsToken',       trim((string)$request->getUserVar('qsToken')));
            $this->updateSetting($contextId, 'qsUsername',    trim((string)$request->getUserVar('qsUsername')));
            $this->updateSetting($contextId, 'qsBatchPrefix', trim((string)$request->getUserVar('qsBatchPrefix')));
            $this->updateSetting($contextId, 'qsAutoSubmit', (bool)$request->getUserVar('qsAutoSubmit'));

            echo $this->renderSettingsHtml($request, true, false);
            return;
        }

        if ($verb === 'export') {
            // نفّذ التصدير ثم أعِد عرض الصفحة مع إشعار HTML بدلاً من JSON الخام
            $resultHtml = $this->exportAndMaybeSendToQS($request, /*forModal*/false);
            $this->showExportPage($request, (string)$resultHtml);
            return;
        }

        // افتراضيًا: اعرض صفحة التصدير كاملة بنمط OJS
        $this->showExportPage($request);
    }

    /** مسارات المودال (Ajax) */
    public function manage($args, $request) {
        $verb = $request->getUserVar('verb'); if (!$verb && !empty($args)) $verb = array_shift($args);

        switch ($verb) {
            case 'settings': {
                $html = $this->renderSettingsHtml($request, false, true);
                return new JSONMessage(true, $html);
            }
            case 'saveSettings': {
                $this->validateCSRF($request);
                $context = $request->getContext(); $contextId = $context? $context->getId():null;

                $this->updateSetting($contextId, 'journalQid',    trim((string)$request->getUserVar('journalQid')));
                $this->updateSetting($contextId, 'prefLangs',     trim((string)$request->getUserVar('prefLangs')));
                $this->updateSetting($contextId, 'qsToken',       trim((string)$request->getUserVar('qsToken')));
                $this->updateSetting($contextId, 'qsUsername',    trim((string)$request->getUserVar('qsUsername')));
                $this->updateSetting($contextId, 'qsBatchPrefix', trim((string)$request->getUserVar('qsBatchPrefix')));
                $this->updateSetting($contextId, 'qsAutoSubmit', (bool)$request->getUserVar('qsAutoSubmit'));

                $html = $this->renderSettingsHtml($request, true, true);
                return new JSONMessage(true, $html);
            }
            case 'exportForm': {
                $html = $this->renderExportHtml($request, true);
                return new JSONMessage(true, $html);
            }
            case 'export': {
                // نفّذ التصدير للـ modal وأعد القالب مع إشعار النتيجة
                $resultHtml = $this->exportAndMaybeSendToQS($request, /*forModal*/true);
                if ($resultHtml !== null) {
                    $html = $this->renderExportHtml($request, /*forModal*/true, $resultHtml);
                    return new JSONMessage(true, $html);
                }
                // في حالة تنزيل ملف لن نصل هنا
                return true;
            }
        }
        return parent::manage($args, $request);
    }

    /** صفحة التصدير كاملة (layout backend) */
    protected function showExportPage($request, $resultHtml = '') {
        $tm = TemplateManager::getManager($request);
        $context = $request->getContext();
        $ctxPath = $context ? $context->getPath() : null;

        $dispatcher = \Application::get()->getDispatcher();

        $exportAction = $dispatcher->url(
            $request,
            \PKP\core\PKPApplication::ROUTE_PAGE,
            $ctxPath,
            'management',
            'importexport',
            ['plugin', $this->getName()],
            ['verb' => 'export']
        );

        $settingsUrl = $dispatcher->url(
            $request,
            \PKP\core\PKPApplication::ROUTE_PAGE,
            $ctxPath,
            'management',
            'importexport',
            ['plugin', $this->getName()],
            ['verb' => 'settings']
        );

        $tm->assign([
            'plugin'       => $this,
            'pluginName'   => $this->getName(),
            'exportAction' => $exportAction,
            'settingsUrl'  => $settingsUrl,
            'csrfToken'    => $this->getCsrfTokenSafe($request),
            'resultHtml'   => (string)$resultHtml,
        ]);

        $tm->display($this->getTemplateResource('index.tpl'));
    }

    /**
     * يبني HTML لنموذج التصدير.
     * - $forModal=true ⇒ نستخدم رابط manage للـ modal.
     * - $resultHtml لإظهار إشعارات النجاح/الخطأ أعلى النموذج.
     */
    protected function renderExportHtml($request, $forModal = false, $resultHtml = '') {
        $tm = TemplateManager::getManager($request);
        $context = $request->getContext();
        $ctxPath = $context ? $context->getPath() : null;

        $router     = $request->getRouter();
        $dispatcher = \Application::get()->getDispatcher();

        // رابط إجراء التصدير للصفحة الكاملة
        $exportActionPage = $dispatcher->url(
            $request,
            \PKP\core\PKPApplication::ROUTE_PAGE,
            $ctxPath,
            'management',
            'importexport',
            ['plugin', $this->getName()],
            ['verb' => 'export']
        );

        // رابط إجراء التصدير للمودال (إلى manage وليس component)
        $exportActionModal = $router->url(
            $request,
            null, null, 'manage', null,
            ['verb' => 'export', 'plugin' => $this->getName(), 'category' => $this->getCategory()]
        );

        $tm->assign([
            'plugin'       => $this,
            'pluginName'   => $this->getName(),
            'exportAction' => $forModal ? $exportActionModal : $exportActionPage,
            'resultHtml'   => (string)$resultHtml,
        ]);

        $tpl = $forModal ? 'index_modal.tpl' : 'index.tpl';
        return $tm->fetch($this->getTemplateResource($tpl));
    }

    /** يبني HTML لرسائل نتيجة الرفع إلى QuickStatements */
    protected function renderExportResultHtml(array $resp) {
        $http   = isset($resp['http_code']) ? (int)$resp['http_code'] : 0;
        $json   = isset($resp['json']) && is_array($resp['json']) ? $resp['json'] : [];
        $status = isset($json['status']) ? (string)$json['status'] : null;
        $batchId= isset($json['batch_id']) ? (int)$json['batch_id'] : 0;

        $okWithBatch = ($http >= 200 && $http < 300) && $status === 'OK' && $batchId > 0;
        $noCommands  = ($http >= 200 && $http < 300) && $status === 'No commands';

        $batchUrl = $batchId ? ('https://quickstatements.toolforge.org/#/batch/' . $batchId) : '';

        ob_start();
        if ($okWithBatch) {
            echo '<div class="pkpNotification pkpNotification-success" role="status" aria-live="polite" style="margin-bottom:10px;">';
            echo htmlspecialchars(__('plugins.importexport.quickstatements.notice.okTitle'), ENT_QUOTES, 'UTF-8') . ' — ';
            echo '<a href="' . htmlspecialchars($batchUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">';
            echo htmlspecialchars(__('plugins.importexport.quickstatements.notice.viewBatch'), ENT_QUOTES, 'UTF-8') . ' #' . (int)$batchId . '</a>';
            echo '</div>';
        } elseif ($noCommands) {
            echo '<div class="pkpNotification pkpNotification-notice" role="status" aria-live="polite" style="margin-bottom:10px;">';
            echo htmlspecialchars(__('plugins.importexport.quickstatements.notice.noCommands'), ENT_QUOTES, 'UTF-8');
            echo '</div>';
        } else {
            echo '<div class="pkpNotification pkpNotification-error" role="alert" style="margin-bottom:10px;">';
            echo '<strong>' . htmlspecialchars(__('plugins.importexport.quickstatements.notice.errorTitle'), ENT_QUOTES, 'UTF-8') . '</strong><br/>';
            echo htmlspecialchars(__('plugins.importexport.quickstatements.notice.httpCode'), ENT_QUOTES, 'UTF-8') . ': ' . (int)$http . '<br/>';
            if (!empty($resp['body'])) {
                $snippet = mb_substr((string)$resp['body'], 0, 1000, 'UTF-8');
                echo '<pre style="white-space:pre-wrap;background:#f7f7f7;border:1px solid #ddd;padding:8px;overflow:auto;max-height:260px;">'
                   . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') . '</pre>';
            }
            echo '</div>';
        }
        return ob_get_clean();
    }

    /** صفحة/مودال الإعدادات */
    protected function renderSettingsHtml($request, $saved=false, $forModal=true) {
        $tm = TemplateManager::getManager($request);
        $context = $request->getContext(); $contextId = $context? $context->getId():null;

        $dispatcher = \Application::get()->getDispatcher();
        $formAction = $dispatcher->url(
            $request,
            \PKP\core\PKPApplication::ROUTE_COMPONENT,
            null,
            'grid.settings.plugins.SettingsPluginGridHandler',
            'manage',
            null,
            ['verb'=>'saveSettings','plugin'=>$this->getName(),'category'=>$this->getCategory()]
        );

        $tm->assign([
            'plugin'        => $this,
            'journalQid'    => (string)$this->getSetting($contextId, 'journalQid'),
            'prefLangs'     => (string)$this->getSetting($contextId, 'prefLangs'),
            'qsToken'       => (string)$this->getSetting($contextId, 'qsToken'),
            'qsUsername'    => (string)$this->getSetting($contextId, 'qsUsername'),
            'qsBatchPrefix' => (string)$this->getSetting($contextId, 'qsBatchPrefix'),
            'qsAutoSubmit'  => (bool)$this->getSetting($contextId, 'qsAutoSubmit'),
            'saved'         => $saved,
            'isModal'       => $forModal,
            'formAction'    => $formAction,
            'csrfToken'     => $this->getCsrfTokenSafe($request),
        ]);

        return $tm->fetch($this->getTemplateResource('settings.tpl'));
    }

    /**
     * تنفيذ التصدير لسيناريو الصفحة الكاملة أو المودال وإرجاع HTML إشعار عند الإرسال التلقائي،
     * أو تنزيل الملف عند عدم التفعيل (وينتهي التنفيذ).
     *
     * @return string|null  HTML نتيجة (للعرض أعلى النموذج). عند تنزيل ملف لن تُعاد قيمة.
     */
    protected function exportAndMaybeSendToQS($request, $forModal=false) {
        $context   = $request->getContext(); 
        $contextId = $context ? $context->getId() : null;

        $withLabels = (bool)$request->getUserVar('withLabels');
        $fmt        = $request->getUserVar('fmt')  ?: 'tsv';
        $mode       = $request->getUserVar('mode') ?: 'all';
        $idsRaw     = (string)$request->getUserVar('ids');

        // اجمع المقالات
        $subs = [];
        if ($mode === 'all') {
            $collector = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([\APP\submission\Submission::STATUS_PUBLISHED]);
            foreach ($collector->getMany() as $s) { $subs[] = $s; }
        } elseif ($mode === 'issues') {
            $issueIds = $this->parseIds($idsRaw);
            if (!empty($issueIds)) {
                $collector = Repo::submission()->getCollector()
                    ->filterByContextIds([$contextId])
                    ->filterByIssueIds($issueIds)
                    ->filterByStatus([\APP\submission\Submission::STATUS_PUBLISHED]);
                foreach ($collector->getMany() as $s) { $subs[] = $s; }
            }
        } elseif ($mode === 'ids') {
            $submissionIds = $this->parseIds($idsRaw);
            if (!empty($submissionIds)) {
                $collector = Repo::submission()->getCollector()
                    ->filterByContextIds([$contextId]);
                if (method_exists($collector, 'filterByIds')) {
                    $collector = $collector->filterByIds($submissionIds);
                    foreach ($collector->getMany() as $s) { $subs[] = $s; }
                } else {
                    foreach ($submissionIds as $sid) {
                        $s = Repo::submission()->get((int)$sid);
                        if ($s && (int)$s->getData('contextId') === (int)$contextId) { $subs[] = $s; }
                    }
                }
            }
        }

        // ابنِ البيانات
        require_once($this->getPluginPath().'/QuickStatementsBuilder.inc.php');
        $journalQid = (string)$this->getSetting($contextId, 'journalQid');
        $prefLangs  = (string)$this->getSetting($contextId, 'prefLangs');
        $pl = [];
        foreach (explode(',', $prefLangs) as $p) { $p = trim($p); if ($p !== '') $pl[] = $p; }

        $builder = new QuickStatementsBuilder($journalQid, $pl);
        $opts = [
            'includeAuthors' => (bool)$request->getUserVar('includeAuthors'),
            'resolveAffil'   => (bool)$request->getUserVar('resolveAffil'),
            'includeRefs'    => (bool)$request->getUserVar('includeRefs'),
            'resolveRefs'    => (bool)$request->getUserVar('resolveRefs'),
            'updateIfExists' => (bool)$request->getUserVar('updateIfExists'),
        ];
        $rows = $builder->buildForSubmissions($context, $subs, $withLabels, $opts);

        // إعدادات QS
        $auto     = (bool)$this->getSetting($contextId, 'qsAutoSubmit');
        $username = (string)$this->getSetting($contextId, 'qsUsername');
        $token    = (string)$this->getSetting($contextId, 'qsToken');
        $prefix   = (string)$this->getSetting($contextId, 'qsBatchPrefix');
        $canAuto  = $auto && $username !== '' && $token !== '';

        if ($canAuto) {
            // أوامر v1 كنص
            ob_start();
            $builder->streamCommands($rows);
            $commands = (string)ob_get_clean();

            // اسم الدفعة
            $ctxName   = $context ? (string)$context->getLocalizedName() : 'OJS';
            $batchName = trim(($prefix ? $prefix.' - ' : '') . $ctxName . ' - ' . date('Y-m-d H:i'));

            // إرسال إلى QS
            $resp = $this->importToQuickStatements($batchName, $username, $token, $commands, 'v1', false, $request);

            // HTML إشعار
            return $this->renderExportResultHtml($resp);
        }

        // غير تلقائي: تنزيل الملف
        if ($fmt === 'commands') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="quickstatements_commands.txt"');
            $builder->streamCommands($rows);
        } else {
            header('Content-Type: text/tab-separated-values; charset=utf-8');
            header('Content-Disposition: attachment; filename="quickstatements.tsv"');
            $builder->streamTSV($rows);
        }
        exit;
    }

    /**
     * تنفيذ التصدير (أسلوب قديم للمودال). يُستخدم فقط من manage->export في بعض التركيبات.
     * إن كان auto-submit يعيد قالب المودال مضمّن فيه إشعار النتيجة، وإلا يبدأ تنزيل الملف.
     */
    protected function exportNow($request, $returnHtmlForModal = false) {
        $context = $request->getContext(); $contextId = $context? $context->getId():null;
        $withLabels = (bool)$request->getUserVar('withLabels');
        $fmt  = $request->getUserVar('fmt') ?: 'tsv';
        $mode = $request->getUserVar('mode') ?: 'all';
        $idsRaw = (string)$request->getUserVar('ids');

        $subs = [];
        if ($mode === 'all') {
            $collector = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([\APP\submission\Submission::STATUS_PUBLISHED]);
            foreach ($collector->getMany() as $s) { $subs[] = $s; }
        } elseif ($mode === 'issues') {
            $issueIds = $this->parseIds($idsRaw);
            if (!empty($issueIds)) {
                $collector = Repo::submission()->getCollector()
                    ->filterByContextIds([$contextId])
                    ->filterByIssueIds($issueIds)
                    ->filterByStatus([\APP\submission\Submission::STATUS_PUBLISHED]);
                foreach ($collector->getMany() as $s) { $subs[] = $s; }
            }
        } elseif ($mode === 'ids') {
            $submissionIds = $this->parseIds($idsRaw);
            if (!empty($submissionIds)) {
                $collector = Repo::submission()->getCollector()
                    ->filterByContextIds([$contextId]);
                if (method_exists($collector, 'filterByIds')) {
                    $collector = $collector->filterByIds($submissionIds);
                    foreach ($collector->getMany() as $s) { $subs[] = $s; }
                } else {
                    foreach ($submissionIds as $sid) {
                        $s = Repo::submission()->get((int)$sid);
                        if ($s && (int)$s->getData('contextId') === (int)$contextId) { $subs[] = $s; }
                    }
                }
            }
        }

        require_once($this->getPluginPath().'/QuickStatementsBuilder.inc.php');
        $journalQid = (string)$this->getSetting($contextId, 'journalQid');
        $prefLangs  = (string)$this->getSetting($contextId, 'prefLangs');
        $pl = []; foreach (explode(',', $prefLangs) as $p) { $p = trim($p); if ($p !== '') $pl[] = $p; }

        $builder = new QuickStatementsBuilder($journalQid, $pl);
        $opts = [
            'includeAuthors' => (bool)$request->getUserVar('includeAuthors'),
            'resolveAffil'   => (bool)$request->getUserVar('resolveAffil'),
            'includeRefs'    => (bool)$request->getUserVar('includeRefs'),
            'resolveRefs'    => (bool)$request->getUserVar('resolveRefs'),
            'updateIfExists' => (bool)$request->getUserVar('updateIfExists'),
        ];
        $rows = $builder->buildForSubmissions($context, $subs, $withLabels, $opts);

        // مسار الإرسال التلقائي
        $auto = (bool)$this->getSetting($contextId, 'qsAutoSubmit');
        if ($auto) {
            $username = (string)$this->getSetting($contextId, 'qsUsername');
            $token    = (string)$this->getSetting($contextId, 'qsToken');
            $prefix   = (string)$this->getSetting($contextId, 'qsBatchPrefix');

            // أوامر v1 كنص
            ob_start();
            $builder->streamCommands($rows);
            $commands = (string)ob_get_clean();

            // اسم الدفعة
            $ctxName  = $context ? (string)$context->getLocalizedName() : 'Batch';
            $batch    = trim(($prefix ? $prefix.' - ' : '') . $ctxName . ' - ' . date('H:i d-m-Y'));

            // أرسل إلى QS
            $resp = $this->importToQuickStatements($batch, $username, $token, $commands, 'v1', false, $request);

            if ($returnHtmlForModal) {
                $resultHtml = $this->renderExportResultHtml($resp);
                return $this->renderExportHtml($request, /*forModal*/ true, $resultHtml);
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            exit;
        }

        // تنزيل ملف
        if ($fmt === 'commands') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="quickstatements_commands.txt"');
            $builder->streamCommands($rows);
        } else {
            header('Content-Type: text/tab-separated-values; charset=utf-8');
            header('Content-Disposition: attachment; filename="quickstatements.tsv"');
            $builder->streamTSV($rows);
        }
        exit;
    }

    /** استدعاء QuickStatements API (مُحدّثة) */
    protected function importToQuickStatements($batchName, $username, $token, $data, $format='v1', $temporary=false, $request=null) {
        $url = 'https://quickstatements.toolforge.org/api.php';

        $host = '';
        try { if ($request && method_exists($request, 'getServerHost')) { $host = $request->getServerHost(); } } catch (\Throwable $e) {}
        if ($host === '') { $host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'unknown-host'); }

        $ua = 'OJS-QuickStatements-Plugin/1.0 (+https://' . $host . '; contact journal@' . $host . ')';

        $fields = [
            'action'    => 'import',
            'submit'    => '1',
            'format'    => $format,
            'username'  => $username,
            'batchname' => $batchName,
            'token'     => $token,
            'temporary' => $temporary ? '1' : '0',
            'openpage'  => '0',
            'data'      => (string)$data,
        ];
        $payload = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Expect:'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $json = null;
        if ($body !== false) {
            $json = json_decode($body, true);
        }

        $ok = ($body !== false && $http >= 200 && $http < 300);
        if ($ok && is_array($json) && isset($json['status'])) {
            $ok = in_array($json['status'], ['OK', 'No commands'], true);
        }

        return [
            'ok'        => $ok,
            'http_code' => $http,
            'body'      => $body,
            'error'     => $err ?: null,
            'json'      => $json,
            'debug'     => [
                'format'   => $format,
                'bytes'    => strlen((string)$data),
                'first100' => mb_substr((string)$data, 0, 100, 'UTF-8'),
                'userAgent'=> $ua,
            ],
        ];
    }

    protected function parseIds($s) {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)$s) as $p) { $p = trim($p); if ($p !== '' && ctype_digit($p)) $out[] = (int)$p; }
        return array_values(array_unique($out));
    }

    protected function validateCSRF($request) {
        if (method_exists($request, 'checkCSRF')) { $request->checkCSRF(); return; }
        $token = $request->getUserVar('csrfToken');
        if (!$token) { throw new \Exception('Invalid CSRF token'); }
    }

    protected function getCsrfTokenSafe($request) {
        try {
            $session = $request->getSession();
            if ($session) {
                if (method_exists($session, 'getCSRFToken')) return (string)$session->getCSRFToken();
                if (method_exists($session, 'get')) { $t = $session->get('csrfToken'); if ($t) return (string)$t; }
                if (method_exists($session, 'token')) return (string)$session->token();
            }
        } catch (\Throwable $e) {}
        return '';
    }

    public function executeCLI($scriptName, &$args) { return 0; }
    public function usage($scriptName) {}
}
