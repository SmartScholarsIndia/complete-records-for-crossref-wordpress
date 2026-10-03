<?php
/**
 * Complete Records for Crossref — completes the deposit file OJS's own Crossref plugin has just written.
 *
 * OJS hands every deposit file it builds to a hook ("articlecrossrefxmlfilter::execute") before sending it to Crossref;
 * the Funding plugin in PKP's gallery adds its funding data the same way. This class adds what OJS holds but does not
 * send, and nothing else:
 *
 *   - the article's reference list (<citation_list>), from the references the journal entered in OJS;
 *   - each author's affiliation — <affiliations> with the institution's ROR ID from schema 5.3.0 on (OJS 3.4), the plain
 *     <affiliation> before it (OJS 3.3); OJS 3.5 already sends affiliations and is left alone;
 *   - Crossmark, only when the journal has set its Crossmark policy DOI — with the funding, licence and trial programs
 *     moved inside it, because the schema takes Crossmark OR those programs side by side, never both.
 *
 * It never replaces or removes what OJS wrote, never adds an element the file already has, and works on a copy: when
 * the finished copy breaks any rule of the schema's order, the copy is thrown away and OJS's own file goes to Crossref
 * exactly as OJS made it. A completed record is a gain; a refused deposit would be a loss.
 *
 * PHP 7.3 syntax on purpose: OJS 3.3 still runs there.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

require_once __DIR__ . '/CcrSchema.php';
require_once __DIR__ . '/CcrCitations.php';

class CcrCompleter
{
    /** Longest affiliation text sent. */
    const MAX_AFFILIATION = 512;

    /**
     * Complete a deposit file.
     *
     * @param DOMDocument $doc   the file OJS's Crossref plugin wrote; it is never changed
     * @param callable $dataFor  function (string $doi) returning null (not this journal's article) or
     *                           ['references' => string[], 'authors' => [['given' => , 'family' => , 'affiliation' => , 'ror' => ], …]]
     *                           with the authors in the order OJS lists them
     * @param array $opt         'references' => bool, 'affiliations' => bool, 'crossmarkPolicy' => DOI or '', 'crossmarkDomain' => host
     * @return array [DOMDocument $result, array $report] — $result is $doc itself when nothing was added or the copy failed
     */
    public static function complete(DOMDocument $doc, $dataFor, array $opt)
    {
        $report = ['version' => CcrSchema::version($doc), 'changed' => false, 'kept_original' => false, 'problems' => [], 'articles' => []];
        if ($report['version'] === '') {
            $report['problems'][] = 'not a Crossref deposit file';
            return [$doc, $report];
        }
        $work = self::copyOf($doc);
        if (!$work) {
            $report['problems'][] = 'OJS\'s file could not be copied to work on (an XML error was already pending, or the file did not read back)';
            $report['kept_original'] = true;
            return [$doc, $report];
        }
        $changed = false;
        try {
            foreach (CcrSchema::articles($work) as $article) {
                $asWritten = CcrSchema::articleDoi($article);
                if ($asWritten === '') continue;
                $doi = strtolower($asWritten);
                $row = ['references' => 0, 'affiliations' => 0, 'ror' => 0, 'crossmark' => false, 'notes' => []];
                $data = call_user_func($dataFor, $asWritten);
                if (!is_array($data)) {
                    $row['notes'][] = 'not found in this journal, left as OJS wrote it';
                    $report['articles'][$doi] = $row;
                    continue;
                }
                if (!empty($opt['references'])) $changed = self::addReferences($article, $data, $row) || $changed;
                if (!empty($opt['affiliations'])) $changed = self::addAffiliations($article, $data, $report['version'], $row) || $changed;
                if (!empty($opt['crossmarkPolicy'])) $changed = self::addCrossmark($article, (string) $opt['crossmarkPolicy'], (string) ($opt['crossmarkDomain'] ?? ''), $row) || $changed;
                $report['articles'][$doi] = $row;
            }
        } catch (Throwable $e) {
            $report['problems'][] = 'stopped: ' . $e->getMessage();
        }
        if (!$changed && !$report['problems']) return [$doc, $report];
        if (!$report['problems']) {
            foreach (CcrSchema::articles($work) as $article) {
                foreach (CcrSchema::articleProblems($article) as $p) $report['problems'][] = strtolower(CcrSchema::articleDoi($article)) . ': ' . $p;
            }
        }
        if (!$report['problems'] && !self::onlyAdded($doc, $work)) {
            $report['problems'][] = 'the finished file was not exactly OJS\'s own file plus the additions';
        }
        if ($report['problems']) {
            $report['kept_original'] = true;
            foreach ($report['articles'] as $d => $row) $report['articles'][$d]['notes'][] = 'nothing added: the file went to Crossref exactly as OJS wrote it';
            return [$doc, $report];
        }
        $report['changed'] = true;
        foreach (CcrSchema::articles($work) as $article) {
            $d = strtolower(CcrSchema::articleDoi($article));
            if (isset($report['articles'][$d])) $report['articles'][$d]['sent'] = CcrSchema::summarize($article);
        }
        return [$work, $report];
    }

    /** The reference list, when the file has none and the journal entered references. */
    private static function addReferences(DOMElement $article, array $data, array &$row)
    {
        if (CcrSchema::child($article, 'citation_list')) {
            $row['notes'][] = 'references: the file already carries a reference list, left as it is';
            return false;
        }
        $refs = CcrCitations::prepare(isset($data['references']) && is_array($data['references']) ? $data['references'] : []);
        if (!$refs) {
            $row['notes'][] = 'references: none entered in OJS';
            return false;
        }
        $doc = $article->ownerDocument;
        $ns = (string) $doc->documentElement->namespaceURI;
        $cl = $doc->createElementNS($ns, 'citation_list');
        foreach ($refs as $r) {
            $c = $doc->createElementNS($ns, 'citation');
            $c->setAttribute('key', $r['key']);
            if ($r['doi'] !== '') {
                $d = $doc->createElementNS($ns, 'doi');
                $d->appendChild($doc->createTextNode($r['doi']));
                $c->appendChild($d);
            }
            $u = $doc->createElementNS($ns, 'unstructured_citation');
            $u->appendChild($doc->createTextNode($r['text']));
            $c->appendChild($u);
            $cl->appendChild($c);
        }
        CcrSchema::insertInOrder($article, $cl, CcrSchema::ARTICLE_ORDER);
        $row['references'] = count($refs);
        return true;
    }

    /**
     * Each author's affiliation, matched to the right <person_name> by position AND name: OJS writes the authors in the
     * order it lists them, and a person whose surname does not match is left alone rather than given someone else's
     * institution.
     */
    private static function addAffiliations(DOMElement $article, array $data, $version, array &$row)
    {
        $authors = isset($data['authors']) && is_array($data['authors']) ? array_values($data['authors']) : [];
        $contribs = CcrSchema::child($article, 'contributors');
        if (!$contribs || !$authors) {
            $row['notes'][] = 'affiliations: the file names no authors';
            return false;
        }
        $held = 0;
        foreach ($authors as $au) if (self::oneLine(isset($au['affiliation']) ? $au['affiliation'] : '') !== '') $held++;
        if (!$held) {
            $row['notes'][] = 'affiliations: none entered in OJS';
            return false;
        }
        $doc = $article->ownerDocument;
        $ns = (string) $doc->documentElement->namespaceURI;
        $structured = CcrSchema::hasStructuredAffiliations($version);
        $changed = false;
        $i = -1;
        foreach (CcrSchema::children($contribs) as $p) {
            if ($p->localName !== 'person_name') continue;
            $i++;
            if (!isset($authors[$i])) break;
            $au = $authors[$i];
            if (CcrSchema::child($p, 'affiliation') || CcrSchema::child($p, 'affiliations')) continue;   // OJS (3.5) already sent it
            $aff = self::oneLine(isset($au['affiliation']) ? $au['affiliation'] : '');
            if ($aff === '') continue;
            if (!self::samePerson($p, $au)) {
                $row['notes'][] = 'affiliations: author ' . ($i + 1) . ' in the file is not author ' . ($i + 1) . ' in OJS, left alone';
                continue;
            }
            $aff = function_exists('mb_substr') ? mb_substr($aff, 0, self::MAX_AFFILIATION, 'UTF-8') : substr($aff, 0, self::MAX_AFFILIATION);
            if ($structured) {
                $affs = $doc->createElementNS($ns, 'affiliations');
                $inst = $doc->createElementNS($ns, 'institution');
                $name = $doc->createElementNS($ns, 'institution_name');
                $name->appendChild($doc->createTextNode($aff));
                $inst->appendChild($name);
                $ror = self::ror(isset($au['ror']) ? $au['ror'] : '');
                if ($ror !== '') {
                    $id = $doc->createElementNS($ns, 'institution_id');
                    $id->setAttribute('type', 'ror');
                    $id->appendChild($doc->createTextNode($ror));
                    $inst->appendChild($id);
                    $row['ror']++;
                }
                $affs->appendChild($inst);
                CcrSchema::insertInOrder($p, $affs, CcrSchema::PERSON_ORDER);
            } else {
                $a = $doc->createElementNS($ns, 'affiliation');
                $a->appendChild($doc->createTextNode($aff));
                CcrSchema::insertInOrder($p, $a, CcrSchema::PERSON_ORDER);
            }
            $row['affiliations']++;
            $changed = true;
        }
        return $changed;
    }

    /**
     * Crossmark, when the journal set its policy DOI — and only then: Crossref refuses Crossmark for a member that has
     * not turned it on, so the setting is the journal's own statement that it has.
     */
    private static function addCrossmark(DOMElement $article, $policy, $domain, array &$row)
    {
        if (CcrSchema::child($article, 'crossmark')) return false;
        $policy = self::bareDoi($policy);
        if (trim($domain) === '') {
            // no domain given: the host of the article's own landing page (doi_data/resource), which is where Crossmark
            // is shown — this also works in OJS's scheduled deposits, which run without a web request
            $dd = CcrSchema::child($article, 'doi_data');
            $res = $dd ? CcrSchema::child($dd, 'resource') : null;
            $domain = $res ? (string) parse_url(trim($res->textContent), PHP_URL_HOST) : '';
        }
        $domain = strtolower(trim(preg_replace('~^https?://~i', '', trim($domain))));
        $domain = explode('/', $domain)[0];
        if ($policy === '') {
            $row['notes'][] = 'crossmark: the policy DOI set in the plugin is not a DOI, Crossmark left out';
            return false;
        }
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            $row['notes'][] = 'crossmark: the journal has no web address to name as its Crossmark domain, Crossmark left out';
            return false;
        }
        $doc = $article->ownerDocument;
        $ns = (string) $doc->documentElement->namespaceURI;
        $cm = $doc->createElementNS($ns, 'crossmark');
        $add = function ($parent, $name, $text = null) use ($doc, $ns) {
            $e = $doc->createElementNS($ns, $name);
            if ($text !== null) $e->appendChild($doc->createTextNode($text));
            $parent->appendChild($e);
            return $e;
        };
        $add($cm, 'crossmark_version', '1');
        $add($cm, 'crossmark_policy', $policy);
        $domains = $add($cm, 'crossmark_domains');
        $one = $add($domains, 'crossmark_domain');
        $add($one, 'domain', $domain);
        $add($cm, 'crossmark_domain_exclusive', 'false');
        $programs = [];
        foreach (['fr:program', 'ai:program', 'ct:program'] as $k) {
            $n = CcrSchema::child($article, $k);
            if ($n) $programs[] = $n;
        }
        if ($programs) {
            $custom = $add($cm, 'custom_metadata');
            foreach ($programs as $n) $custom->appendChild(CcrSchema::detach($n));
        }
        CcrSchema::insertInOrder($article, $cm, CcrSchema::ARTICLE_ORDER);
        $row['crossmark'] = true;
        return true;
    }

    /**
     * The copy the additions are made in: OJS's file written out as text and read back, every character of it kept.
     *
     * Not DOMDocument::cloneNode(): on PHP 8.4.21 with libxml 2.9.14, cloning a file built the way OJS's Crossref
     * plugin builds it (each abstract's xml:lang set while its article is not yet in the document) moved xml:lang into
     * Crossref's own namespace, and Crossref refuses such a file. Found by running the plugin inside a real OJS 3.3.0.23
     * on 3 Oct 2026 (tests/test_completer.php keeps the case). Text read back is exactly what OJS would send.
     *
     * OJS's export reads libxml's error list after its filter has run and fails on any error in it (see CcrValidator),
     * so the list is left as found: nothing is read while it holds an error, and anything this reading adds is cleared.
     *
     * @return DOMDocument|null null when the file cannot be copied
     */
    public static function copyOf(DOMDocument $doc)
    {
        foreach (libxml_get_errors() as $e) if ($e->level >= LIBXML_ERR_ERROR) return null;
        $xml = $doc->saveXML();
        if (!is_string($xml) || $xml === '') return null;
        $copy = new DOMDocument('1.0', 'utf-8');
        $copy->preserveWhiteSpace = true;
        $prev = libxml_use_internal_errors(true);
        $before = count(libxml_get_errors());
        $ok = $copy->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        $added = count(libxml_get_errors()) > $before;
        if ($added) libxml_clear_errors();   // ours only: the list held no error when this began
        libxml_use_internal_errors($prev);
        if (!$ok || $added || !$copy->documentElement) return null;
        $copy->formatOutput = $doc->formatOutput;
        return $copy;
    }

    /**
     * The promise "never changes or removes what OJS wrote", checked on every finished file: with this class's additions
     * taken out again — the reference lists, the affiliations, Crossmark (its moved programs put back where the schema
     * puts them) — $work must be OJS's own file, compared in canonical XML (C14N) with the file's indentation set aside.
     */
    public static function onlyAdded(DOMDocument $original, DOMDocument $work)
    {
        $a = self::copyOf($original);
        $b = self::copyOf($work);
        if (!$a || !$b) return false;
        $was = CcrSchema::articles($a);
        $now = CcrSchema::articles($b);
        if (count($was) !== count($now)) return false;
        try {
            foreach ($now as $i => $art) {
                $o = $was[$i];
                if (!CcrSchema::child($o, 'citation_list') && ($cl = CcrSchema::child($art, 'citation_list'))) CcrSchema::detach($cl);
                if (!CcrSchema::child($o, 'crossmark') && ($cm = CcrSchema::child($art, 'crossmark'))) {
                    CcrSchema::detach($cm);
                    $custom = CcrSchema::child($cm, 'custom_metadata');
                    if ($custom) foreach (CcrSchema::children($custom) as $p) CcrSchema::insertInOrder($art, CcrSchema::detach($p), CcrSchema::ARTICLE_ORDER);
                }
                $po = self::people($o);
                $pn = self::people($art);
                if (count($po) !== count($pn)) return false;
                foreach ($pn as $k => $p) {
                    if (CcrSchema::child($po[$k], 'affiliation') || CcrSchema::child($po[$k], 'affiliations')) continue;
                    foreach (CcrSchema::children($p) as $c) {
                        if (in_array(CcrSchema::key($c), ['affiliation', 'affiliations'], true)) CcrSchema::detach($c);
                    }
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        self::dropLayout($a->documentElement);
        self::dropLayout($b->documentElement);
        $ca = $a->C14N();
        $cb = $b->C14N();
        return is_string($ca) && $ca !== '' && $ca === $cb;
    }

    /** The <person_name> elements of one article, in order. */
    private static function people(DOMElement $article)
    {
        $out = [];
        $c = CcrSchema::child($article, 'contributors');
        if ($c) foreach (CcrSchema::children($c) as $p) if ($p->localName === 'person_name') $out[] = $p;
        return $out;
    }

    /** Set the indentation aside: whitespace-only text between elements, in elements that hold no text of their own. */
    private static function dropLayout(DOMElement $el)
    {
        $kids = [];
        foreach ($el->childNodes as $c) $kids[] = $c;
        $text = false;
        $elements = false;
        foreach ($kids as $c) {
            if ($c instanceof DOMElement) $elements = true;
            elseif ($c->nodeType !== XML_TEXT_NODE || trim($c->data) !== '') $text = true;
        }
        foreach ($kids as $c) {
            if ($c instanceof DOMElement) self::dropLayout($c);
            elseif ($elements && !$text && $c->nodeType === XML_TEXT_NODE) $el->removeChild($c);
        }
    }

    /** True when the <person_name> carries this author's family name (or, for a one-name author, the given name). */
    private static function samePerson(DOMElement $p, array $au)
    {
        $sn = CcrSchema::child($p, 'surname');
        if (!$sn) return false;
        $have = self::fold($sn->textContent);
        foreach (['family', 'given'] as $k) {
            if (isset($au[$k]) && self::fold($au[$k]) !== '' && self::fold($au[$k]) === $have) return true;
        }
        return false;
    }

    private static function fold($s)
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private static function oneLine($s)
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** A ROR ID in the one form Crossref takes (https://ror.org/0xxxxxxxx), or ''. */
    public static function ror($id)
    {
        $id = strtolower(trim((string) $id));
        if (preg_match('~^(?:https?://ror\.org/|ror\.org/)?(0[a-z0-9]{6}[0-9]{2})$~', $id, $m)) return 'https://ror.org/' . $m[1];
        return '';
    }

    /** "10.xxxx/…" out of a DOI however it was typed (doi.org link, "doi:" prefix), or ''. */
    public static function bareDoi($s)
    {
        $s = trim((string) $s);
        $s = preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', $s);
        return preg_match('~^10\.\d{4,9}/\S+$~', $s) ? $s : '';
    }
}
