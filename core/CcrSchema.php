<?php
/**
 * Complete Records for Crossref — what a Crossref deposit file may hold, and in which order.
 *
 * OJS's own Crossref plugin writes each deposit with Crossref's schema 4.3.6 (OJS 3.3), 5.3.1 (OJS 3.4) or 5.4.0
 * (OJS 3.5). This plugin only ever ADDS to that file, so it must put every element where the schema of that very file
 * expects it. The order below is Crossref's journal_article sequence; the tail of it, where the programs sit, was read
 * from crossref5.5.0.xsd itself and confirmed by deposits Crossref accepted:
 *
 *     <xsd:choice>
 *       <xsd:element ref="crossmark"/>
 *       <xsd:sequence> fr:program, ai:program, ct:program </xsd:sequence>
 *     </xsd:choice>
 *     rel:program, archive_locations, scn_policies, version_info, doi_data, citation_list, component_list
 *
 * so Crossmark and the bare funding/licence/clinical-trial programs are ALTERNATIVES: with Crossmark those programs
 * travel inside <crossmark><custom_metadata>.
 *
 * PHP 7.3 syntax on purpose: OJS 3.3 still runs there.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

class CcrSchema
{
    const NS_JATS = 'http://www.ncbi.nlm.nih.gov/JATS1';
    const NS_FR = 'http://www.crossref.org/fundref.xsd';
    const NS_AI = 'http://www.crossref.org/AccessIndicators.xsd';
    const NS_CT = 'http://www.crossref.org/clinicaltrials.xsd';
    const NS_REL = 'http://www.crossref.org/relations.xsd';
    const NS_PREFIX = 'http://www.crossref.org/schema/';

    /** journal_article's children, in schema order. */
    const ARTICLE_ORDER = [
        'titles' => 10, 'contributors' => 20, 'jats:abstract' => 30, 'publication_date' => 40, 'acceptance_date' => 45,
        'pages' => 50, 'publisher_item' => 60, 'crossmark' => 70, 'fr:program' => 80, 'ai:program' => 81, 'ct:program' => 82,
        'rel:program' => 90, 'archive_locations' => 100, 'scn_policies' => 110, 'version_info' => 115, 'doi_data' => 120,
        'citation_list' => 130, 'component_list' => 140,
    ];

    /** person_name's children, in schema order. */
    const PERSON_ORDER = [
        'given_name' => 10, 'surname' => 20, 'suffix' => 30, 'affiliation' => 40, 'affiliations' => 40, 'ORCID' => 50,
        'alt-name' => 60,
    ];

    /** crossmark's children, in schema order. */
    const CROSSMARK_ORDER = [
        'crossmark_version' => 10, 'crossmark_policy' => 20, 'crossmark_domains' => 30, 'crossmark_domain_exclusive' => 40,
        'updates' => 50, 'custom_metadata' => 60,
    ];

    /**
     * The schema version a deposit file was written with ("4.3.6", "5.3.1", "5.4.0" …), read from its root namespace.
     * Returns '' for a file that is not a Crossref deposit.
     */
    public static function version(DOMDocument $doc)
    {
        $root = $doc->documentElement;
        if (!$root || $root->localName !== 'doi_batch') return '';
        $ns = (string) $root->namespaceURI;
        if (strpos($ns, self::NS_PREFIX) !== 0) return '';
        $v = substr($ns, strlen(self::NS_PREFIX));
        return preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : '';
    }

    /** True from schema 5.3.0 on: <affiliations> with institution ids (ROR) exists. Before, only the plain <affiliation>. */
    public static function hasStructuredAffiliations($version)
    {
        return version_compare($version, '5.3.0', '>=');
    }

    /** The key an element is ranked by: "fr:program", "jats:abstract", or the bare name for Crossref's own namespace. */
    public static function key(DOMNode $n)
    {
        if (!($n instanceof DOMElement)) return '';
        switch ((string) $n->namespaceURI) {
            case self::NS_JATS: return 'jats:' . $n->localName;
            case self::NS_FR: return 'fr:' . $n->localName;
            case self::NS_AI: return 'ai:' . $n->localName;
            case self::NS_CT: return 'ct:' . $n->localName;
            case self::NS_REL: return 'rel:' . $n->localName;
        }
        return (string) $n->localName;
    }

    /** The element children of $parent, in document order. */
    public static function children(DOMElement $parent)
    {
        $out = [];
        foreach ($parent->childNodes as $c) if ($c instanceof DOMElement) $out[] = $c;
        return $out;
    }

    /** The first child of $parent with this key, or null. */
    public static function child(DOMElement $parent, $key)
    {
        foreach (self::children($parent) as $c) if (self::key($c) === $key) return $c;
        return null;
    }

    /**
     * Put $new among $parent's children where $order says it belongs: before the first child that ranks after it.
     * An element the order does not know is left where it is and never used as an anchor.
     *
     * When the file is indented (OJS writes it indented, and the completer's copy keeps every character of it), $new
     * gets the same indentation as its siblings and its own children one step deeper, so the finished file reads like
     * OJS's own. A file without indentation gets none.
     */
    public static function insertInOrder(DOMElement $parent, DOMElement $new, array $order)
    {
        $k = self::key($new);
        if (!isset($order[$k])) throw new RuntimeException('no place for ' . $k);
        $rank = $order[$k];
        $before = null;
        foreach (self::children($parent) as $c) {
            $ck = self::key($c);
            if (isset($order[$ck]) && $order[$ck] > $rank) {
                $before = $c;
                break;
            }
        }
        $indent = self::indentOf($parent);
        if ($indent !== '') self::indentInside($new, $indent);
        if ($before) {
            $parent->insertBefore($new, $before);
            if ($indent !== '') $parent->insertBefore($parent->ownerDocument->createTextNode($indent), $before);
            return $new;
        }
        $last = $parent->lastChild;
        if ($indent !== '' && $last instanceof DOMText && $last->nodeType === XML_TEXT_NODE && trim($last->data) === '') {
            // after the last child, inside the whitespace that closes $parent
            $parent->insertBefore($parent->ownerDocument->createTextNode($indent), $last);
            $parent->insertBefore($new, $last);
        } else {
            $parent->appendChild($new);
        }
        return $new;
    }

    /**
     * Take an element out of the file together with the indentation in front of it (a moved element must not leave an
     * empty line behind). Returns the element.
     */
    public static function detach(DOMElement $el)
    {
        $parent = $el->parentNode;
        $p = $el->previousSibling;
        if ($p instanceof DOMText && $p->nodeType === XML_TEXT_NODE && trim($p->data) === '' && $parent) $parent->removeChild($p);
        return $parent ? $parent->removeChild($el) : $el;
    }

    /** The whitespace the file puts in front of $parent's children ("\n" and spaces), or '' when it is not indented. */
    private static function indentOf(DOMElement $parent)
    {
        foreach (self::children($parent) as $c) {
            $p = $c->previousSibling;
            return ($p instanceof DOMText && $p->nodeType === XML_TEXT_NODE && trim($p->data) === '' && strpos($p->data, "\n") !== false) ? $p->data : '';
        }
        $p = $parent->previousSibling;   // no element child yet: one step deeper than $parent itself
        return ($p instanceof DOMText && $p->nodeType === XML_TEXT_NODE && trim($p->data) === '' && strpos($p->data, "\n") !== false) ? $p->data . '  ' : '';
    }

    /**
     * Indent a new element's own children one step deeper than $indent. Only element-only content is touched: an
     * element with text beside its children (mixed content) is left exactly as it is.
     */
    private static function indentInside(DOMElement $el, $indent)
    {
        $kids = self::children($el);
        if (!$kids) return;
        $blank = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) continue;
            if ($c->nodeType === XML_TEXT_NODE && trim($c->data) === '') {
                $blank[] = $c;
                continue;
            }
            return;
        }
        foreach ($blank as $b) $el->removeChild($b);
        $inner = $indent . '  ';
        foreach ($kids as $c) {
            $el->insertBefore($el->ownerDocument->createTextNode($inner), $c);
            self::indentInside($c, $inner);
        }
        $el->appendChild($el->ownerDocument->createTextNode($indent));
    }

    /**
     * Problems with the order of $parent's children, as sentences; [] when every known child follows the one before it.
     */
    public static function orderProblems(DOMElement $parent, array $order)
    {
        $out = [];
        $last = -1;
        $lastKey = '';
        foreach (self::children($parent) as $c) {
            $k = self::key($c);
            if (!isset($order[$k])) continue;
            if ($order[$k] < $last) $out[] = $k . ' comes after ' . $lastKey . ' in ' . $parent->localName;
            $last = $order[$k];
            $lastKey = $k;
        }
        return $out;
    }

    /**
     * Everything that could break the schema in one journal_article: elements out of order, an element the schema
     * allows once written twice, and Crossmark side by side with the bare programs (the schema's choice).
     */
    public static function articleProblems(DOMElement $article)
    {
        $out = self::orderProblems($article, self::ARTICLE_ORDER);
        $seen = [];
        foreach (self::children($article) as $c) {
            $k = self::key($c);
            if (in_array($k, ['titles', 'jats:abstract', 'publication_date'], true)) continue;   // these may repeat
            if (isset(self::ARTICLE_ORDER[$k])) {
                if (isset($seen[$k])) $out[] = $k . ' written twice in journal_article';
                $seen[$k] = true;
            }
        }
        if (isset($seen['crossmark']) && (isset($seen['fr:program']) || isset($seen['ai:program']) || isset($seen['ct:program']))) {
            $out[] = 'crossmark and a bare funding/licence/trial program side by side (the schema allows one or the other)';
        }
        foreach (self::children($article) as $c) {
            if (self::key($c) === 'contributors') {
                foreach (self::children($c) as $p) {
                    if (self::key($p) !== 'person_name') continue;
                    foreach (self::orderProblems($p, self::PERSON_ORDER) as $msg) $out[] = $msg;
                    $aff = 0;
                    foreach (self::children($p) as $pc) if (in_array(self::key($pc), ['affiliation', 'affiliations'], true)) $aff++;
                    if ($aff > 1 && self::child($p, 'affiliations')) $out[] = 'person_name has affiliations written more than once';
                }
            }
            if (self::key($c) === 'crossmark') {
                foreach (self::orderProblems($c, self::CROSSMARK_ORDER) as $msg) $out[] = $msg;
            }
        }
        return $out;
    }

    /**
     * The DOI of a journal_article (doi_data/doi), trimmed, in the case OJS wrote it — OJS looks a DOI up by its stored
     * text, and on PostgreSQL that comparison is case-sensitive. Lower-case it yourself where a key is needed.
     */
    public static function articleDoi(DOMElement $article)
    {
        $dd = self::child($article, 'doi_data');
        if (!$dd) return '';
        $d = self::child($dd, 'doi');
        return $d ? trim($d->textContent) : '';
    }

    /** Every journal_article in the file. */
    public static function articles(DOMDocument $doc)
    {
        $out = [];
        $root = $doc->documentElement;
        if (!$root) return $out;
        foreach ($doc->getElementsByTagNameNS((string) $root->namespaceURI, 'journal_article') as $a) $out[] = $a;
        return $out;
    }

    /**
     * What one journal_article carries, counted — the same summary is made of what Crossref then shows, so the two can
     * be compared line by line after the deposit.
     */
    public static function summarize(DOMElement $article)
    {
        $s = ['references' => 0, 'affiliations' => 0, 'ror' => 0, 'orcid' => 0, 'authors' => 0, 'abstract' => false,
              'license' => false, 'funders' => 0, 'links' => 0, 'crossmark' => '', 'relations' => 0];
        $cl = self::child($article, 'citation_list');
        if ($cl) foreach (self::children($cl) as $c) if ($c->localName === 'citation') $s['references']++;
        $contribs = self::child($article, 'contributors');
        if ($contribs) {
            foreach (self::children($contribs) as $p) {
                if ($p->localName !== 'person_name') continue;
                $s['authors']++;
                if (self::child($p, 'ORCID')) $s['orcid']++;
                $affs = self::child($p, 'affiliations');
                if ($affs) {
                    foreach (self::children($affs) as $inst) {
                        $s['affiliations']++;
                        foreach (self::children($inst) as $id) if ($id->localName === 'institution_id' && $id->getAttribute('type') === 'ror') $s['ror']++;
                    }
                } elseif (self::child($p, 'affiliation')) {
                    $s['affiliations']++;
                }
            }
        }
        $s['abstract'] = (bool) self::child($article, 'jats:abstract');
        $cm = self::child($article, 'crossmark');
        $programParent = $article;
        if ($cm) {
            $pol = self::child($cm, 'crossmark_policy');
            $s['crossmark'] = $pol ? trim($pol->textContent) : '';
            $custom = self::child($cm, 'custom_metadata');
            if ($custom) $programParent = $custom;
        }
        $fr = self::child($programParent, 'fr:program');
        if ($fr) {
            foreach (self::children($fr) as $g) if ($g->getAttribute('name') === 'fundgroup') $s['funders']++;
        }
        $ai = self::child($programParent, 'ai:program');
        if ($ai) foreach (self::children($ai) as $l) if ($l->localName === 'license_ref') $s['license'] = true;
        $rel = self::child($article, 'rel:program');
        if ($rel) foreach (self::children($rel) as $r) if ($r->localName === 'related_item') $s['relations']++;
        $dd = self::child($article, 'doi_data');
        if ($dd) {
            foreach (self::children($dd) as $col) {
                if ($col->localName !== 'collection') continue;
                foreach (self::children($col) as $item) if ($item->localName === 'item') $s['links']++;
            }
        }
        return $s;
    }
}
