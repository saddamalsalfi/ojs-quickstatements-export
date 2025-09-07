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




class QuickStatementsBuilder
{
    /** @var string|null QID الخاص بالمجلة (P1433) */
    protected $journalQid = null;

    /** @var array أكواد اللغات المفضلة بالترتيب (ar, en, ...) */
    protected $preferredLangs = array();

    /** @var array كاش بسيط لاستعلامات WDQS/API */
    protected $wdqsCache = array();

    public function __construct($journalQid = null, $preferredLangs = array())
    {
        $this->journalQid = $journalQid;
        $pl = array();
        foreach ((array)$preferredLangs as $l) {
            $l = strtolower(trim((string)$l));
            if ($l !== '') { $pl[] = $l; }
        }
        $this->preferredLangs = $pl;
    }

    /* =========================
     * دوال بناء البيانات
     * ========================= */

    /**
     * يبني صفوف (rows) جاهزة للتصدير من لائحة submissions (أرقام أو كائنات)
     * $opts:
     *   includeAuthors     => bool
     *   includeRefs        => bool
     *   resolveAffil       => bool (محاولة تحويل الانتماء المؤسسي إلى QID عبر label)
     *   resolveRefs        => bool (محاولة تحويل DOIs إلى QIDs للمراجع)
     *   updateIfExists     => bool (إن وُجد QID لنفس DOI يبدأ التحديث به بدلاً من CREATE)
     *   skipLabelsOnUpdate => bool (تخطي Lxx/Dxx عند التحديث؛ افتراضيًا true)
     */
    public function buildForSubmissions($context, $submissions, $withLabels = true, $opts = array())
    {
        $rows = array();
        $firstRow = true;

        foreach ((array)$submissions as $submission) {

            // جلب كائن الـ submission عند تمرير ID
            if (!is_object($submission) || !method_exists($submission, 'getCurrentPublication')) {
                if (is_numeric($submission)) {
                    $submission = \APP\facades\Repo::submission()->get((int)$submission);
                } elseif (is_array($submission) && isset($submission['id'])) {
                    $submission = \APP\facades\Repo::submission()->get((int)$submission['id']);
                }
            }
            if (!$submission || !is_object($submission) || !method_exists($submission, 'getCurrentPublication')) {
                continue;
            }

            // Current publication
            $pub = $submission->getCurrentPublication();
            if ($pub && !is_object($pub)) {
                $pub = \APP\facades\Repo::publication()->get((int)$pub);
            }
            if (!$pub) {
                $pubId = (int)$submission->getData('currentPublicationId');
                if ($pubId) { $pub = \APP\facades\Repo::publication()->get($pubId); }
            }
            if (!$pub) { continue; }

            // العنوان واللغات
            $titlesRaw = $pub->getData('title');
            $titles = array();
            if (is_array($titlesRaw)) {
                $titles = $titlesRaw;
            } elseif (is_string($titlesRaw) && $titlesRaw !== '') {
                $lc = strtolower(substr((string)$context->getPrimaryLocale(), 0, 2));
                $titles[$lc] = $titlesRaw;
            }

            $langs = $this->langsInOrder($this->preferredLangs, $titles);
            if (empty($langs)) {
                $pl = (string)$context->getPrimaryLocale();
                $langs = array(strtolower(substr($pl, 0, 2)));
            }

            // التاريخ/المجلد/العدد/الصفحات
            $datePub = (string)$pub->getData('datePublished');
            $dateSub = (string)$submission->getData('datePublished');
            $date = $datePub ?: $dateSub;

            $issueId = $pub->getData('issueId');
            $issue   = $issueId ? \APP\facades\Repo::issue()->get($issueId) : null;
            $volume  = $issue ? $issue->getVolume() : null;
            $number  = $issue ? $issue->getNumber() : null;
            $pages   = (string)$pub->getData('pages');

            // DOI (بشكل متوافق مع 3.5)
            $doi = '';
            if (method_exists($pub, 'getDoi')) {
                $tmp = $pub->getDoi(); // قد يكون string أو كائن
                if (is_object($tmp) && method_exists($tmp, 'getData')) {
                    $doi = (string)$tmp->getData('value');
                } elseif (is_string($tmp)) {
                    $doi = $tmp;
                }
            }
            if (!$doi) {
                $doi = (string)$pub->getData('doi');
            }

            // تحضير الصف
            $row = array();

            // === التسميات (Labels / Descriptions) ===
            if ($withLabels) {
                foreach ($langs as $lc) {
                    if (!empty($titles[$lc])) {
                        $row['L' . $lc] = $this->quote($this->sanitizeTitle($titles[$lc]));
                    }
                }
                if (!empty($langs)) {
                    $row['D' . $langs[0]] = $this->quote('scholarly article');
                }
            }

            // === الخصائص الأساسية ===
            $row['P31'] = 'Q13442814'; // instance of: scholarly article
            if (!empty($langs)) {
                $lc = $langs[0];
                if (!empty($titles[$lc])) {
                    $row['P1476'] = $lc . ':' . $this->quote($this->sanitizeTitle($titles[$lc])); // title (نظيف)
                }
            }
            if (!empty($date))             { $row['P577'] = $this->quickTime($date); }
            if (!empty($this->journalQid)) { $row['P1433'] = $this->journalQid; }
            if (!empty($volume))           { $row['P478'] = $this->quote((string)$volume); }
            if (!empty($number))           { $row['P433'] = $this->quote((string)$number); }
            if (!empty($pages))            { $row['P304'] = $this->quote($pages); }
            if (!empty($doi))              { $row['P356'] = $this->quote($doi); }
            if (!empty($langs)) {
                $qidLang = $this->languageQid($langs[0]);
                if ($qidLang) { $row['P407'] = $qidLang; }
            }

            // === الروابط ===
            $galleyUrl = $this->onePublicGalleyUrl($context, $submission, $pub);
            if (!empty($galleyUrl)) { $row['P953'] = $this->quote($galleyUrl); }
            $landing = $this->articleLandingUrl($context, $submission);
            if (!empty($landing))  { $row['P856'] = $this->quote($landing); }

            // المؤلفون
            if (!empty($opts['includeAuthors'])) {
                $authors = array();
                $a = $pub->getData('authors');
                if (is_array($a) && !empty($a)) {
                    $authors = $a;
                }
                if (empty($authors)) {
                    try {
                        $collector = \APP\facades\Repo::author()
                            ->getCollector()
                            ->filterByPublicationIds(array($pub->getId()));
                        foreach ($collector->getMany() as $au) {
                            $authors[] = $au;
                        }
                    } catch (\Throwable $e) {}
                }

                $i = 1;
                foreach ($authors as $au) {
                    $name = '';
                    $affText = '';
                    if (is_object($au)) {
                        if (method_exists($au, 'getFullName')) {
                            $name = (string)$au->getFullName();
                        }
                        if (method_exists($au, 'getLocalizedAffiliation')) {
                            $affText = (string)$au->getLocalizedAffiliation();
                        } elseif (method_exists($au, 'getAffiliation')) {
                            $affText = (string)$au->getAffiliation();
                        }
                    } elseif (is_array($au)) {
                        $name = isset($au['fullName'])
                            ? (string)$au['fullName']
                            : trim((string)($au['givenName'] ?? '') . ' ' . (string)($au['familyName'] ?? ''));
                        $affText = isset($au['affiliation']) ? (string)$au['affiliation'] : '';
                    } elseif (is_string($au)) {
                        $name = trim($au);
                    }

                    $name = trim($name);
                    if ($name === '') { continue; }

                    if (!isset($row['__authors'])) { $row['__authors'] = array(); }
                    $row['__authors'][] = array('name'=>$name,'aff'=>(string)$affText,'ordinal'=>$i);
                    $i++;
                }
            }

            // المراجع: استخراج DOIs
            if (!empty($opts['includeRefs'])) {
                $citRaw = $pub->getData('citations');
                $flat   = $this->flattenCitations($citRaw);
                if (empty($flat)) {
                    $citRaw2 = $pub->getData('citation');
                    $flat = $this->flattenCitations($citRaw2);
                }
                $dois = array();
                foreach ($flat as $line) {
                    if (!is_string($line)) { continue; }
                    if (preg_match_all('~10\.\d{4,9}/[-._;()/:A-Za-z0-9]+~', $line, $m)) {
                        foreach ($m[0] as $d) { $dois[] = rtrim($d, " \t\n\r\0\x0B.,;)"); }
                    }
                }
                if (!empty($dois)) {
                    $row['__refDois'] = array_values(array_unique($dois));
                }
            }

            if ($firstRow) {
                if (!array_key_exists('skipLabelsOnUpdate', $opts)) {
                    $opts['skipLabelsOnUpdate'] = true;
                }
                $row['__opts'] = (array)$opts;
                $firstRow = false;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** تنظيف العنوان من HTML وتوحيد المسافات */
    protected function sanitizeTitle($s)
    {
        $s = (string)$s;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = preg_replace('~\s+~u', ' ', $s);
        return trim($s);
    }

    /** إخراج TSV (المستوى الأول؛ المؤلف الأول فقط) */
    public function streamTSV($rows)
    {
        foreach ($rows as &$r) {
            if (isset($r['__authors']) && is_array($r['__authors']) && !empty($r['__authors'])) {
                $au = $r['__authors'][0];
                $nm = '"' . str_replace('"', '\\"', (string)$au['name']) . '"';
                $r['P2093']       = $nm;
                $r['P2093|P1545'] = '"' . strval($au['ordinal']) . '"';
            }
        } unset($r);

        $allKeys = array();
        foreach ($rows as $r) {
            foreach ($r as $k => $v) {
                if (strpos($k, '__') === 0) { continue; }
                if (is_array($v)) { continue; }
                if (!in_array($k, $allKeys, true)) { $allKeys[] = $k; }
            }
        }
        if (empty($allKeys)) { $allKeys = array('P31'); }

        echo implode("\t", $allKeys) . "\n";
        foreach ($rows as $r) {
            $line = array();
            foreach ($allKeys as $k) {
                $val = isset($r[$k]) ? $r[$k] : '';
                if (is_array($val)) { $val = ''; }
                $line[] = (string)$val;
            }
            echo implode("\t", $line) . "\n";
        }
    }

    /**
     * إخراج أوامر QuickStatements مع تحقق مسبق (API ثم WDQS)
     */
    public function streamCommands($rows)
    {
        $opts = isset($rows[0]['__opts']) ? (array)$rows[0]['__opts'] : array();
        $resolveAffil       = !empty($opts['resolveAffil']);
        $resolveRefs        = !empty($opts['resolveRefs']);
        $updateIfExists     = !empty($opts['updateIfExists']);
        $skipLabelsOnUpdate = array_key_exists('skipLabelsOnUpdate', $opts) ? (bool)$opts['skipLabelsOnUpdate'] : true;

        foreach ($rows as $r) {
            $target   = 'LAST';
            $isCreate = true;

            // 1) إن كان هناك DOI: جرّب Wikidata API أولاً، ثم WDQS احتياطاً
            if ($updateIfExists && !empty($r['P356'])) {
                $doi = $this->normalizeDoi($this->stripQuotes((string)$r['P356']));
                if ($doi !== '') {
                    $qid = $this->wdApiFindByDOI($doi);
                    if (!$qid) { $qid = $this->wdqsFindByDOI($doi); }
                    if ($qid) {
                        $target   = $qid;
                        $isCreate = false;
                        echo "# matched by DOI via API: {$doi} -> {$qid}\n";
                    } else {
                        echo "# no existing item found for DOI (API+WDQS): {$doi}\n";
                    }
                }
            }

            // 2) إن لم نجد: جرّب العنوان+المجلّة عبر API، ثم WDQS بالـmetadata
            if ($updateIfExists && $isCreate && !empty($r['P1476']) && !empty($r['P1433'])) {
                $mono = $this->parseMonolingual((string)$r['P1476']); // [lc, text]
                if ($mono) {
                    list($lc, $title) = $mono;
                    $journalQid = (string)$r['P1433'];
                    $year  = $this->extractYearFromQuickTime(isset($r['P577']) ? (string)$r['P577'] : '');
                    $vol   = isset($r['P478']) ? $this->stripQuotes((string)$r['P478']) : '';
                    $iss   = isset($r['P433']) ? $this->stripQuotes((string)$r['P433']) : '';

                    $qid2 = $this->wdApiFindByLabelAndJournal($title, $lc, $journalQid);
                    if (!$qid2) { $qid2 = $this->wdqsFindByMetadata($title, $lc, $journalQid, $year, $vol, $iss); }
                    if ($qid2) {
                        $target   = $qid2;
                        $isCreate = false;
                        echo "# matched by metadata via API/WDQS: {$qid2}\n";
                    } else {
                        echo "# no match by metadata (title/journal) via API/WDQS\n";
                    }
                }
            }

            // طباعة CREATE فقط عند الإنشاء
            if ($isCreate) {
                echo "CREATE\n";
                $target = 'LAST';
            }

            // خصائص العنصر
            foreach ($r as $k => $v) {
                if (strpos($k, '__') === 0) { continue; }
                if (is_array($v))          { continue; }
                if ($v === '')             { continue; }
                if (!$isCreate && $skipLabelsOnUpdate && preg_match('~^[LD][a-z]{2}$~', $k)) {
                    continue;
                }
                echo "{$target}|{$k}|{$v}\n";
            }

            // المؤلفون
            if (isset($r['__authors']) && is_array($r['__authors'])) {
                foreach ($r['__authors'] as $au) {
                    $nm  = '"' . str_replace('"', '\\"', (string)$au['name']) . '"';
                    $ord = '"' . strval($au['ordinal']) . '"';
                    $line = "{$target}|P2093|{$nm}|P1545|{$ord}";
                    if (!empty($au['aff']) && $resolveAffil) {
                        $qidAff = $this->wdqsFindByLabel((string)$au['aff']);
                        if ($qidAff) { $line .= "|P1416|{$qidAff}"; }
                    }
                    echo $line . "\n";
                }
            }

            // المراجع
            if ($resolveRefs && isset($r['__refDois']) && is_array($r['__refDois'])) {
                foreach ($r['__refDois'] as $doiRaw) {
                    $doi = $this->normalizeDoi((string)$doiRaw);
                    if ($doi === '') { continue; }
                    $qidRef = $this->wdApiFindByDOI($doi);
                    if (!$qidRef) { $qidRef = $this->wdqsFindByDOI($doi); }
                    if ($qidRef) {
                        echo "{$target}|P2860|{$qidRef}\n";
                    } else {
                        echo "# ref DOI not found (API+WDQS): {$doi}\n";
                    }
                }
            }

            echo "\n";
        }
    }

    /* =========================
     * أدوات مساعدة
     * ========================= */

    protected function quote($s)
    {
        $s = (string)$s;
        $s = str_replace(array("\r", "\n"), ' ', $s);
        $s = trim($s);
        return '"' . str_replace('"', '\\"', $s) . '"';
    }

    protected function stripQuotes($s)
    {
        $s = (string)$s;
        if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
            return stripcslashes(substr($s, 1, -1));
        }
        return $s;
    }

    protected function normalizeDoi($s)
    {
        $s = strtolower(trim((string)$s));
        $s = preg_replace('~^(https?://(dx\\.)?doi\\.org/|doi:)~', '', $s);
        $s = rtrim($s, " \t\n\r\0\x0B.,;)");
        return $s;
    }

    protected function quickTime($date)
    {
        $date = trim((string)$date);
        if ($date === '') { return ''; }

        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) { return '+' . $date . 'T00:00:00Z/11'; }
        if (preg_match('~^\d{4}-\d{2}$~', $date))       { return '+' . $date . '-01T00:00:00Z/10'; }
        if (preg_match('~^\d{4}$~', $date))             { return '+' . $date . '-01-01T00:00:00Z/9'; }

        $ts = strtotime($date);
        if ($ts) { return '+' . date('Y-m-d', $ts) . 'T00:00:00Z/11'; }
        return '';
    }

    protected function langsInOrder($preferred, $titles)
    {
        $out = array();
        $preferred = array_values(array_unique(array_map('strval', (array)$preferred)));
        foreach ($preferred as $lc) {
            $lc = strtolower($lc);
            if (isset($titles[$lc]) && !in_array($lc, $out, true)) { $out[] = $lc; }
        }
        foreach ((array)$titles as $lc => $_v) {
            $lc = strtolower((string)$lc);
            if (!in_array($lc, $out, true)) { $out[] = $lc; }
        }
        return $out;
    }

    protected function languageQid($lc)
    {
        $lc = strtolower((string)$lc);
        $map = array(
            'ar' => 'Q13955',
            'en' => 'Q1860',
            'fr' => 'Q150',
            'de' => 'Q188',
            'es' => 'Q1321',
            'it' => 'Q652',
            'ru' => 'Q7737',
            'fa' => 'Q9168',
            'tr' => 'Q256',
        );
        return isset($map[$lc]) ? $map[$lc] : '';
    }

    protected function onePublicGalleyUrl($context, $submission, $pub)
    {
        try {
            $galleys = (array)$pub->getData('galleys');
            foreach ($galleys as $g) {
                if (is_object($g) && method_exists($g, 'getBestId')) {
                    $dispatcher = \APP\Application::get()->getDispatcher();
                    $request    = \APP\Application::get()->getRequest();
                    return $dispatcher->url(
                        $request,
                        \PKP\core\PKPApplication::ROUTE_PAGE,
                        $context->getPath(),
                        'article',
                        'view',
                        array($submission->getBestId(), $g->getBestId())
                    );
                }
            }
        } catch (\Throwable $e) {}
        return '';
    }

    protected function articleLandingUrl($context, $submission)
    {
        try {
            $dispatcher = \APP\Application::get()->getDispatcher();
            $request    = \APP\Application::get()->getRequest();
            return $dispatcher->url(
                $request,
                \PKP\core\PKPApplication::ROUTE_PAGE,
                $context->getPath(),
                'article',
                'view',
                array($submission->getBestId())
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function flattenCitations($raw)
    {
        $out = array();

        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw !== '') {
                $parts = preg_split('~\r?\n~', $raw);
                foreach ($parts as $p) { $p = trim($p); if ($p !== '') { $out[] = $p; } }
            }
            return $out;
        }

        if (is_array($raw)) {
            foreach ($raw as $c) {
                if (is_string($c)) {
                    $c = trim($c);
                    if ($c !== '') { $out[] = $c; }
                    continue;
                }
                if (is_object($c)) {
                    try {
                        if (method_exists($c, 'getRawCitation')) {
                            $t = (string)$c->getRawCitation(); $t = trim($t);
                            if ($t !== '') { $out[] = $t; continue; }
                        }
                        if (method_exists($c, 'getCitation')) {
                            $t = (string)$c->getCitation(); $t = trim($t);
                            if ($t !== '') { $out[] = $t; continue; }
                        }
                        if (method_exists($c, 'getData')) {
                            $t = (string)$c->getData('rawCitation'); $t = trim($t);
                            if ($t !== '') { $out[] = $t; continue; }
                        }
                    } catch (\Throwable $e) {}
                }
            }
            return $out;
        }

        if (is_object($raw)) {
            try {
                if (method_exists($raw, 'getRawCitation')) {
                    $t = trim((string)$raw->getRawCitation()); if ($t !== '') { $out[] = $t; }
                } elseif (method_exists($raw, 'getCitation')) {
                    $t = trim((string)$raw->getCitation()); if ($t !== '') { $out[] = $t; }
                } elseif (method_exists($raw, 'getData')) {
                    $t = trim((string)$raw->getData('rawCitation')); if ($t !== '') { $out[] = $t; }
                }
            } catch (\Throwable $e) {}
        }

        return $out;
    }

    /* =========================
     * استعلامات WDQS للمطابقة (احتياطيًا)
     * ========================= */

    protected function wdqsFindByDOI($doi)
    {
        $doi = $this->normalizeDoi($doi);
        if ($doi === '') { return ''; }

        $cacheKey = 'doi:' . $doi;
        if (isset($this->wdqsCache[$cacheKey])) {
            return $this->wdqsCache[$cacheKey];
        }

        $query = 'SELECT ?item WHERE { ' .
                 '  ?item wdt:P356 ?d . ' .
                 '  FILTER(LCASE(STR(?d)) = "' . addslashes($doi) . '") ' .
                 '} LIMIT 1';

        $qid = $this->runWdqsReturnQid($query);
        $this->wdqsCache[$cacheKey] = $qid;
        return $qid;
    }

    protected function wdqsFindByLabel($label)
    {
        $label = trim((string)$label);
        if ($label === '') { return ''; }
        $langs = array_unique(array_merge(['ar','en'], $this->preferredLangs));
        foreach ($langs as $lc) {
            $qid = $this->wdqsFindByLabelLang($label, $lc);
            if ($qid) { return $qid; }
        }
        return '';
    }

    protected function wdqsFindByLabelLang($label, $lc)
    {
        $lc = strtolower((string)$lc);
        $labelNorm = strtolower(trim((string)$label));
        if ($labelNorm === '' || $lc === '') { return ''; }

        $cacheKey = 'label:' . $lc . ':' . $labelNorm;
        if (isset($this->wdqsCache[$cacheKey])) {
            return $this->wdqsCache[$cacheKey];
        }

        $query = 'SELECT ?item WHERE { ' .
                 '  ?item rdfs:label ?l . ' .
                 '  FILTER(LANG(?l) = "' . addslashes($lc) . '") . ' .
                 '  FILTER(LCASE(STR(?l)) = "' . addslashes($labelNorm) . '") ' .
                 '} LIMIT 1';

        $qid = $this->runWdqsReturnQid($query);
        $this->wdqsCache[$cacheKey] = $qid;
        return $qid;
    }

    protected function wdqsFindByMetadata($title, $lc, $journalQid, $year = '', $volume = '', $issue = '')
    {
        $title = $this->sanitizeTitle($title);
        $lc    = strtolower(trim((string)$lc));
        $journalQid = trim((string)$journalQid);
        if ($title === '' || $lc === '' || $journalQid === '') { return ''; }

        $titleLC = strtolower($title);

        $cacheKey = 'meta:' . $lc . ':' . md5($titleLC) . ':' . $journalQid . ':' . $year . ':' . $volume . ':' . $issue;
        if (isset($this->wdqsCache[$cacheKey])) {
            return $this->wdqsCache[$cacheKey];
        }

        $filters = array();
        $filters[] = '  ?item wdt:P31 wd:Q13442814 .';
        $filters[] = '  ?item wdt:P1433 wd:' . addslashes($journalQid) . ' .';
        $filters[] = '  ?item p:P1476 ?st .';
        $filters[] = '  ?st ps:P1476 ?t .';
        $filters[] = '  FILTER(LANG(?t) = "' . addslashes($lc) . '") .';
        $filters[] = '  FILTER(LCASE(STR(?t)) = "' . addslashes($titleLC) . '") .';

        if ($year !== '') {
            $filters[] = '  OPTIONAL { ?item wdt:P577 ?d . }';
            $filters[] = '  FILTER(!BOUND(?d) || YEAR(?d) = ' . intval($year) . ') .';
        }
        if ($volume !== '') {
            $filters[] = '  OPTIONAL { ?item wdt:P478 ?vol . }';
            $filters[] = '  FILTER(!BOUND(?vol) || STR(?vol) = "' . addslashes($volume) . '") .';
        }
        if ($issue !== '') {
            $filters[] = '  OPTIONAL { ?item wdt:P433 ?iss . }';
            $filters[] = '  FILTER(!BOUND(?iss) || STR(?iss) = "' . addslashes($issue) . '") .';
        }

        $query = "SELECT ?item WHERE {\n" . implode("\n", $filters) . "\n} LIMIT 1";

        $qid = $this->runWdqsReturnQid($query);
        $this->wdqsCache[$cacheKey] = $qid;
        return $qid;
    }

    protected function runWdqsReturnQid($query)
    {
        $url = 'https://query.wikidata.org/sparql?format=json&query=' . urlencode($query);
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'OJS-QuickStatements-Exporter/1.1');
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/sparql-results+json']);
            $res = curl_exec($ch);
            if ($res === false) { return ''; }
            $data = json_decode($res, true);
            if (!isset($data['results']['bindings'][0]['item']['value'])) { return ''; }
            $uri = $data['results']['bindings'][0]['item']['value'];
            if (preg_match('~Q(\d+)$~', $uri, $m)) { return 'Q' . $m[1]; }
        } catch (\Throwable $e) {}
        return '';
    }

    /* =========================
     *  Wikidata API (Action API) — تحقق فوري
     * ========================= */

    /** ابحث بالـDOI عبر API باستخدام haswbstatement:P356=... (تجربة بدون/مع اقتباس) */
    protected function wdApiFindByDOI($doi)
    {
        $doi = $this->normalizeDoi($doi);
        if ($doi === '') { return ''; }

        // المحاولة 1: بدون اقتباس
        $q = 'haswbstatement:P356=' . $doi;
        $qid = $this->wdApiSingleResultQid($q);
        if ($qid) { return $qid; }

        // المحاولة 2: مع اقتباس (بعض القيم الحساسة للرموز تعمل أفضل)
        $q = 'haswbstatement:P356="' . $this->escapeForSrsearch($doi) . '"';
        return $this->wdApiSingleResultQid($q);
    }

    /** ابحث بعنوان + لغة + المجلّة */
    protected function wdApiFindByLabelAndJournal($title, $lc, $journalQid)
    {
        $title = $this->sanitizeTitle($title);
        $lc    = strtolower(trim((string)$lc));
        $journalQid = trim((string)$journalQid);
        if ($title === '' || $lc === '' || $journalQid === '') { return ''; }

        // نقيّد بنوع "مقال علمي" + المجلة + label.<lc>:"النص"
        $parts = array(
            'haswbstatement:P31=Q13442814',
            'haswbstatement:P1433=' . $journalQid,
            'label.' . $lc . ':"' . $this->escapeForSrsearch($title) . '"'
        );
        $q = implode(' ', $parts);
        return $this->wdApiSingleResultQid($q);
    }

    /** نفّذ استعلام list=search وأعد QID واحد إن وُجد */
    protected function wdApiSingleResultQid($srsearch)
    {
        // نطلب نتيجة واحدة فقط
        $url = 'https://www.wikidata.org/w/api.php?action=query&format=json&list=search'
             . '&srnamespace=0&srlimit=1&srinfo=totalhits&srprop='
             . '&srsearch=' . urlencode($srsearch);

        $data = $this->httpGetJson($url);
        if (!$data || !isset($data['query']['search'][0]['title'])) { return ''; }
        $t = (string)$data['query']['search'][0]['title']; // مثل "Q12345"
        if (preg_match('~^Q\d+$~', $t)) { return $t; }
        return '';
    }

    /** استدعاء GET يعيد JSON */
    protected function httpGetJson($url)
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'OJS-QuickStatements-Exporter/1.1');
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            $res = curl_exec($ch);
            if ($res === false) { return null; }
            $data = json_decode($res, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** للهروب داخل srsearch (داخل علامات اقتباس) */
    protected function escapeForSrsearch($s)
    {
        $s = (string)$s;
        return str_replace('"', '\"', $s);
    }

    /* ======== أدوات تحليل قيم الحقول ======== */

    /** تفكيك قيمة P1476 بنمط quickstatements: ar:"النص" => [ 'ar', 'النص' ] */
    protected function parseMonolingual($v)
    {
        $v = (string)$v;
        if (!preg_match('~^([a-z]{2,3}):"(.*)"$~us', $v, $m)) {
            return null;
        }
        $lc = strtolower($m[1]);
        $text = stripcslashes($m[2]);
        return array($lc, $text);
    }

    /** استخراج السنة من وقت بصيغة QuickStatements مثل +2011-12-30T00:00:00Z/11 */
    protected function extractYearFromQuickTime($qsTime)
    {
        if (preg_match('~\+(\d{4})~', (string)$qsTime, $m)) {
            return $m[1];
        }
        return '';
    }
}
