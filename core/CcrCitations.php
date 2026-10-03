<?php
/**
 * Complete Records for Crossref — a journal's reference list, as Crossref's <citation_list> wants it.
 *
 * OJS keeps each article's references as the editor pasted them (one per line, the "citationsRaw" field, split by
 * OJS into one Citation per line). Crossref takes each reference as an <unstructured_citation> and links it by itself;
 * a <doi> is added only when the reference names exactly ONE DOI, written out in full — two DOIs in one line, or a DOI
 * we would have to guess, is left for Crossref's own matching rather than asserted in the member's name.
 *
 * PHP 7.3 syntax on purpose: OJS 3.3 still runs there.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

class CcrCitations
{
    /** Longest reference text sent; a longer line is almost always two references run together. */
    const MAX_TEXT = 1000;

    /** At most this many references per article — far above any real reference list, a guard against a pasted article. */
    const MAX_REFS = 1000;

    /** A raw block of references (one per line) split into clean lines. */
    public static function splitRaw($raw)
    {
        $raw = str_replace(["\r\n", "\r"], "\n", (string) $raw);
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $t = self::clean($line);
            if ($t !== '') $out[] = $t;
        }
        return $out;
    }

    /** One reference as plain text: tags removed, entities decoded, white space collapsed, list numbering dropped. */
    public static function clean($text)
    {
        $t = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{3000}\s]+/u', ' ', $t);
        $t = trim((string) $t);
        // "1. Smith J …", "[12] Smith J …", "(3) Smith J …" — the number is the journal's list, not the reference.
        // Three digits at most: "(2020) Annual report" and "2020. Title" start with a YEAR, which is the reference's own.
        $t = (string) preg_replace('/^(\[\d{1,3}\]|\(\d{1,3}\)|\d{1,3}[.)])\s+/u', '', $t);
        return trim($t);
    }

    /**
     * The one DOI a reference names, normalised to "10.xxxx/…" in lower case, or '' when it names none or more than one.
     */
    public static function singleDoi($text)
    {
        $t = (string) $text;
        // doi.org links and "doi:" prefixes are the same DOI; read every DOI-shaped run of characters
        if (!preg_match_all('~(?:https?://(?:dx\.)?doi\.org/|doi:\s*)?(10\.\d{4,9}/[^\s"<>]+)~i', $t, $m, PREG_OFFSET_CAPTURE)) return '';
        $found = [];
        foreach ($m[1] as $hit) {
            list($d, $at) = $hit;
            $next = substr($t, $at + strlen($d), 1);
            // an old DOI with angle brackets in it (10.1002/(SICI)…<693::AID-ASI4>3.0.CO;2-0) would be cut short here,
            // and a cut DOI is a wrong DOI: leave that reference to Crossref's own matching
            if ($next === '<' || $next === '>') return '';
            $d = rtrim($d, '.,;:]}\'');
            // a closing bracket belongs to the DOI only when the DOI also opened one, e.g. 10.1002/(SICI)1097-…
            while (substr($d, -1) === ')' && substr_count($d, '(') < substr_count($d, ')')) $d = substr($d, 0, -1);
            $d = rtrim($d, '.,;:');
            $d = strtolower($d);
            // Crossref's own pattern for modern DOIs; anything outside it is not asserted
            if (preg_match('~^10\.\d{4,9}/[-._;()/:a-z0-9]+$~', $d)) $found[$d] = true;
            else return '';
        }
        return count($found) === 1 ? (string) key($found) : '';
    }

    /**
     * The references ready for Crossref: [['key' => 'ref1', 'text' => …, 'doi' => …], …], in the journal's order.
     * $refs is a list of strings (one reference each).
     */
    public static function prepare(array $refs)
    {
        $out = [];
        $n = 0;
        foreach ($refs as $r) {
            $t = self::clean($r);
            if ($t === '') continue;
            if (++$n > self::MAX_REFS) break;
            $text = function_exists('mb_substr') ? mb_substr($t, 0, self::MAX_TEXT, 'UTF-8') : substr($t, 0, self::MAX_TEXT);
            $out[] = ['key' => 'ref' . $n, 'text' => $text, 'doi' => self::singleDoi($t)];
        }
        return $out;
    }
}
