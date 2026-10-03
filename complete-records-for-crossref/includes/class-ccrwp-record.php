<?php
/**
 * One article's Crossref record: what WordPress holds for the post, and the deposit file made from it.
 *
 * The file is made in two steps, as for OJS: first the record OJS's own Crossref plugin would write (titles, authors with
 * ORCID iDs, abstract, dates, pages, licence, the DOI and its page — schema 5.4.0), then the shared core completes it
 * (CcrCompleter: the reference list, each author's affiliation with its ROR ID, Crossmark) under the same rules and the
 * same checks of the schema's order. The finished file is checked against Crossref's published schema before it may
 * be sent (CcrWp_Crossref / the admin page).
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

defined('ABSPATH') || exit;

class CcrWp_Record
{
    const NS = 'http://www.crossref.org/schema/5.4.0';
    const SCHEMA = 'https://www.crossref.org/schemas/crossref5.4.0.xsd';
    const META = '_ccrwp_record';       // the article's Crossref data (array), kept with the post
    const DEPOSITS = '_ccrwp_deposits'; // what was sent, when, and what Crossref said (array, newest first)

    /** The fields of an article's record, empty. */
    public static function blank()
    {
        return ['suffix' => '', 'authors' => [], 'abstract' => '', 'volume' => '', 'issue' => '', 'first_page' => '',
                'last_page' => '', 'references' => '', 'licence' => '', 'pdf' => ''];
    }

    public static function stored($postId)
    {
        $m = get_post_meta($postId, self::META, true);
        return array_merge(self::blank(), is_array($m) ? $m : []);
    }

    /**
     * Keep what the article's Crossref box sent. Returns the problems found (each field with a problem is not kept).
     */
    public static function save($postId, array $in)
    {
        $old = self::stored($postId);
        $rec = $old;
        $problems = [];
        $t = function ($v, $max = 255) { return mb_substr(trim(sanitize_text_field(wp_unslash((string) $v))), 0, $max); };
        $suffix = $t(isset($in['suffix']) ? $in['suffix'] : '', 200);
        if ($suffix !== '' && !preg_match('~^[A-Za-z0-9._;()/-]+$~', $suffix)) $problems[] = 'The DOI suffix may hold only letters, digits and - . _ ; ( ) /.';
        else $rec['suffix'] = $suffix;
        $authors = [];
        $rows = isset($in['authors']) && is_array($in['authors']) ? $in['authors'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $a = ['given' => $t(isset($row['given']) ? $row['given'] : '', 120), 'family' => $t(isset($row['family']) ? $row['family'] : '', 120),
                  'orcid' => $t(isset($row['orcid']) ? $row['orcid'] : '', 60), 'affiliation' => $t(isset($row['affiliation']) ? $row['affiliation'] : '', 512),
                  'ror' => $t(isset($row['ror']) ? $row['ror'] : '', 60)];
            if ($a['given'] === '' && $a['family'] === '' && $a['orcid'] === '' && $a['affiliation'] === '') continue;   // an empty row
            if ($a['family'] === '') { $problems[] = sprintf('Author "%s": the family name (surname) is needed — Crossref requires it.', $a['given']); continue; }
            if ($a['orcid'] !== '') {
                $o = self::orcid($a['orcid']);
                if ($o === '') { $problems[] = sprintf('Author %s: "%s" is not an ORCID iD (its check digit does not match) — left out.', $a['family'], $a['orcid']); $a['orcid'] = ''; }
                else $a['orcid'] = $o;
            }
            if ($a['ror'] !== '') {
                $r = CcrCompleter::ror($a['ror']);
                if ($r === '') { $problems[] = sprintf('Author %s: "%s" is not a ROR ID — left out.', $a['family'], $a['ror']); $a['ror'] = ''; }
                else $a['ror'] = $r;
            }
            $authors[] = $a;
        }
        $rec['authors'] = $authors;
        $rec['abstract'] = isset($in['abstract']) ? mb_substr(trim(sanitize_textarea_field(wp_unslash($in['abstract']))), 0, 20000) : '';
        foreach (['volume' => 30, 'issue' => 30, 'first_page' => 30, 'last_page' => 30] as $k => $max) $rec[$k] = $t(isset($in[$k]) ? $in[$k] : '', $max);
        $rec['references'] = isset($in['references']) ? mb_substr(sanitize_textarea_field(wp_unslash($in['references'])), 0, 400000) : '';
        foreach (['licence', 'pdf'] as $k) $rec[$k] = isset($in[$k]) ? esc_url_raw(trim(wp_unslash($in[$k])), ['http', 'https']) : '';
        update_post_meta($postId, self::META, $rec);
        return $problems;
    }

    /** An ORCID iD in the form Crossref takes (https://orcid.org/0000-0000-0000-000X), or '' when the check digit fails. */
    public static function orcid($v)
    {
        $v = strtoupper(trim((string) $v));
        if (!preg_match('~^(?:HTTPS?://ORCID\.ORG/)?(\d{4})-?(\d{4})-?(\d{4})-?(\d{3}[\dX])$~', $v, $m)) return '';
        $d = $m[1] . $m[2] . $m[3] . $m[4];
        $total = 0;
        for ($i = 0; $i < 15; $i++) $total = ($total + (int) $d[$i]) * 2;
        $r = (12 - $total % 11) % 11;
        if (($r === 10 ? 'X' : (string) $r) !== $d[15]) return '';
        return 'https://orcid.org/' . $m[1] . '-' . $m[2] . '-' . $m[3] . '-' . $m[4];
    }

    /** The article's DOI: the journal's prefix and the article's own suffix (or the pattern's). '' without a prefix. */
    public static function doi(WP_Post $post)
    {
        $prefix = (string) CcrWp_Settings::get('prefix');
        if ($prefix === '') return '';
        $rec = self::stored($post->ID);
        $suffix = $rec['suffix'] !== '' ? $rec['suffix'] : self::suffixFromPattern($post);
        return $prefix . '/' . $suffix;
    }

    public static function suffixFromPattern(WP_Post $post)
    {
        $t = strtotime($post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt . ' UTC' : $post->post_date);
        $map = ['{year}' => gmdate('Y', $t), '{month}' => gmdate('m', $t), '{id}' => (string) $post->ID,
                '{slug}' => preg_replace('~[^a-z0-9-]~', '', strtolower((string) $post->post_name))];
        return strtr((string) CcrWp_Settings::get('suffix_pattern'), $map);
    }

    /** Everything the deposit needs about the article, read from WordPress and the article's Crossref box. */
    public static function data(WP_Post $post)
    {
        $rec = self::stored($post->ID);
        // WordPress keeps a lone "<" typed in a text box as the text "&lt;" (sanitize_textarea_field); the file carries the
        // character itself, escaped once by the XML writer
        $abstract = $rec['abstract'] !== '' ? $rec['abstract'] : trim(wp_strip_all_tags((string) $post->post_excerpt));
        $abstract = html_entity_decode($abstract, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = strtotime($post->post_date);
        return [
            'doi' => self::doi($post),
            'url' => get_permalink($post),
            'title' => trim(html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'authors' => $rec['authors'],
            'abstract' => $abstract,
            'year' => (int) date('Y', $t), 'month' => (int) date('n', $t), 'day' => (int) date('j', $t),
            'volume' => $rec['volume'], 'issue' => $rec['issue'], 'first_page' => $rec['first_page'], 'last_page' => $rec['last_page'],
            'references' => CcrCitations::splitRaw($rec['references']),
            'licence' => $rec['licence'] !== '' ? $rec['licence'] : (string) CcrWp_Settings::get('licence'),
            'pdf' => $rec['pdf'],
        ];
    }

    /** What the record lacks that Crossref's Participation Reports count — said before the deposit, not after. */
    public static function lacks(array $d)
    {
        $out = [];
        if (!$d['authors']) $out[] = 'no authors';
        else {
            $o = 0; $a = 0;
            foreach ($d['authors'] as $au) { if ($au['orcid'] !== '') $o++; if ($au['affiliation'] !== '') $a++; }
            if ($o < count($d['authors'])) $out[] = sprintf('ORCID iDs for %d of %d authors', $o, count($d['authors']));
            if ($a < count($d['authors'])) $out[] = sprintf('affiliations for %d of %d authors', $a, count($d['authors']));
        }
        if ($d['abstract'] === '') $out[] = 'no abstract';
        if (!$d['references']) $out[] = 'no reference list';
        if ($d['licence'] === '') $out[] = 'no licence';
        return $out;
    }

    /**
     * Crossref's version number for a deposit: YYYYMMDDHHMMSSmmm (UTC). Crossref updates a record only when the new number
     * is GREATER than the one it holds, so it is also kept above every earlier deposit of the article and above the
     * number Crossref named when it refused one as "less or equal to previously submitted version" (_ccrwp_min_stamp).
     */
    public static function stamp($postId)
    {
        $now = microtime(true);
        $stamp = gmdate('YmdHis', (int) $now) . sprintf('%03d', (int) (($now - floor($now)) * 1000));
        $floor = (string) get_post_meta($postId, '_ccrwp_min_stamp', true);
        foreach (self::deposits($postId) as $dep) {
            if (isset($dep['stamp']) && self::cmp((string) $dep['stamp'], $floor) > 0) $floor = (string) $dep['stamp'];
        }
        if ($floor !== '' && self::cmp($stamp, $floor) <= 0) $stamp = self::increment($floor);
        return $stamp;
    }

    /** Two version numbers compared as numbers (they can be longer than PHP's integers on 32-bit hosts). */
    private static function cmp($a, $b)
    {
        $a = ltrim((string) $a, '0'); $b = ltrim((string) $b, '0');
        return strlen($a) !== strlen($b) ? (strlen($a) < strlen($b) ? -1 : 1) : strcmp($a, $b);
    }

    /** A 17-digit number, plus one (as a string: PHP's integers would hold it, but the text is what Crossref reads). */
    private static function increment($s)
    {
        $s = str_pad((string) $s, 17, '0', STR_PAD_LEFT);
        for ($i = strlen($s) - 1; $i >= 0; $i--) {
            if ($s[$i] !== '9') { $s[$i] = (string) ((int) $s[$i] + 1); return $s; }
            $s[$i] = '0';
        }
        return '1' . $s;
    }

    /**
     * The record as OJS's own Crossref plugin writes it (schema 5.4.0): no reference list, no affiliations, no Crossmark
     * — those are added by the core, as in OJS.
     */
    public static function base(array $d, array $s, $stamp, $batchId)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $ns = self::NS;
        $el = function ($name, $text = null, array $attrs = [], $nsUri = null) use ($doc, $ns) {
            $e = $doc->createElementNS($nsUri ?: $ns, $name);
            foreach ($attrs as $k => $v) $e->setAttribute($k, $v);
            if ($text !== null) $e->appendChild($doc->createTextNode((string) $text));
            return $e;
        };
        $root = $el('doi_batch', null, ['version' => '5.4.0']);
        $doc->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jats', CcrSchema::NS_JATS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ai', CcrSchema::NS_AI);
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation', $ns . ' ' . self::SCHEMA);

        $head = $root->appendChild($el('head'));
        $head->appendChild($el('doi_batch_id', $batchId));
        $head->appendChild($el('timestamp', $stamp));
        $dep = $head->appendChild($el('depositor'));
        $dep->appendChild($el('depositor_name', $s['depositor_name']));
        $dep->appendChild($el('email_address', $s['depositor_email']));
        $head->appendChild($el('registrant', $s['publisher']));

        $journal = $root->appendChild($el('body'))->appendChild($el('journal'));
        $jm = $journal->appendChild($el('journal_metadata', null, ['language' => $s['language']]));
        $jm->appendChild($el('full_title', $s['journal_title']));
        if ($s['abbrev_title'] !== '') $jm->appendChild($el('abbrev_title', $s['abbrev_title']));
        if ($s['issn_p'] !== '') $jm->appendChild($el('issn', $s['issn_p'], ['media_type' => 'print']));
        if ($s['issn_e'] !== '') $jm->appendChild($el('issn', $s['issn_e'], ['media_type' => 'electronic']));

        if ($d['volume'] !== '' || $d['issue'] !== '') {
            $ji = $journal->appendChild($el('journal_issue'));
            $pd = $ji->appendChild($el('publication_date', null, ['media_type' => 'online']));
            $pd->appendChild($el('year', $d['year']));
            if ($d['volume'] !== '') $ji->appendChild($el('journal_volume'))->appendChild($el('volume', $d['volume']));
            if ($d['issue'] !== '') $ji->appendChild($el('issue', $d['issue']));
        }

        $art = $journal->appendChild($el('journal_article', null, ['publication_type' => 'full_text', 'language' => $s['language']]));
        $art->appendChild($el('titles'))->appendChild($el('title', $d['title']));
        if ($d['authors']) {
            $cs = $art->appendChild($el('contributors'));
            foreach (array_values($d['authors']) as $i => $au) {
                $p = $cs->appendChild($el('person_name', null, ['contributor_role' => 'author', 'sequence' => $i === 0 ? 'first' : 'additional']));
                if ($au['given'] !== '') $p->appendChild($el('given_name', $au['given']));
                $p->appendChild($el('surname', $au['family']));
                if ($au['orcid'] !== '') $p->appendChild($el('ORCID', $au['orcid']));
            }
        }
        if ($d['abstract'] !== '') {
            $ab = $art->appendChild($el('jats:abstract', null, [], CcrSchema::NS_JATS));
            $ab->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $s['language']);
            foreach (preg_split('~\n\s*\n~', str_replace(["\r\n", "\r"], "\n", $d['abstract'])) as $para) {
                $para = trim(preg_replace('~\s+~u', ' ', $para));
                if ($para !== '') $ab->appendChild($el('jats:p', $para, [], CcrSchema::NS_JATS));
            }
        }
        $pd = $art->appendChild($el('publication_date', null, ['media_type' => 'online']));
        $pd->appendChild($el('month', sprintf('%02d', $d['month'])));
        $pd->appendChild($el('day', sprintf('%02d', $d['day'])));
        $pd->appendChild($el('year', $d['year']));
        if ($d['first_page'] !== '') {
            $pg = $art->appendChild($el('pages'));
            $pg->appendChild($el('first_page', $d['first_page']));
            if ($d['last_page'] !== '') $pg->appendChild($el('last_page', $d['last_page']));
        }
        if ($d['licence'] !== '') {
            $prog = $art->appendChild($el('ai:program', null, ['name' => 'AccessIndicators'], CcrSchema::NS_AI));
            $prog->appendChild($el('ai:license_ref', $d['licence'], ['applies_to' => 'vor'], CcrSchema::NS_AI));
        }
        $dd = $art->appendChild($el('doi_data'));
        $dd->appendChild($el('doi', $d['doi']));
        $dd->appendChild($el('resource', $d['url']));
        if ($d['pdf'] !== '') {
            // the full text for Similarity Check and for text and data mining, as OJS's Crossref plugin names a PDF galley
            $c1 = $dd->appendChild($el('collection', null, ['property' => 'crawler-based']));
            $c1->appendChild($el('item', null, ['crawler' => 'iParadigms']))->appendChild($el('resource', $d['pdf']));
            $c2 = $dd->appendChild($el('collection', null, ['property' => 'text-mining']));
            $c2->appendChild($el('item'))->appendChild($el('resource', $d['pdf'], ['mime_type' => 'application/pdf']));
        }
        return $doc;
    }

    /**
     * The deposit file for a post: the base record completed by the core. Returns [DOMDocument, report, data, stamp, batch].
     * report: the core's report for the article, plus 'sent' (what the file carries, counted).
     */
    public static function build(WP_Post $post)
    {
        $s = CcrWp_Settings::all();
        $d = self::data($post);
        $stamp = self::stamp($post->ID);
        $batch = 'ccrwp-' . $post->ID . '-' . $stamp;
        $base = self::base($d, $s, $stamp, $batch);
        $host = (string) parse_url($d['url'], PHP_URL_HOST);
        $authorsForCore = [];
        foreach ($d['authors'] as $au) $authorsForCore[] = ['given' => $au['given'], 'family' => $au['family'], 'affiliation' => $au['affiliation'], 'ror' => $au['ror']];
        list($doc, $report) = CcrCompleter::complete($base, function ($doi) use ($d, $authorsForCore) {
            return strtolower($doi) === strtolower($d['doi']) ? ['references' => $d['references'], 'authors' => $authorsForCore] : null;
        }, ['references' => true, 'affiliations' => true, 'crossmarkPolicy' => (string) $s['crossmark_policy'], 'crossmarkDomain' => $host]);
        $arts = CcrSchema::articles($doc);
        $report['sent'] = $arts ? CcrSchema::summarize($arts[0]) : [];
        return [$doc, $report, $d, $stamp, $batch];
    }

    /** The article's deposits, newest first. */
    public static function deposits($postId)
    {
        $d = get_post_meta($postId, self::DEPOSITS, true);
        return is_array($d) ? $d : [];
    }

    public static function addDeposit($postId, array $row)
    {
        $all = self::deposits($postId);
        array_unshift($all, $row);
        update_post_meta($postId, self::DEPOSITS, array_slice($all, 0, 20));
    }

    public static function updateLatestDeposit($postId, array $changes)
    {
        $all = self::deposits($postId);
        if (!$all) return;
        $all[0] = array_merge($all[0], $changes);
        update_post_meta($postId, self::DEPOSITS, $all);
    }

    /** The newest deposit Crossref accepted (its result says success), or null. */
    public static function registered($postId)
    {
        foreach (self::deposits($postId) as $dep) {
            if (isset($dep['result']) && $dep['result'] === 'success') return $dep;
        }
        return null;
    }
}
