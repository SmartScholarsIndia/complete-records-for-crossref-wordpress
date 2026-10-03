<?php
/**
 * Complete Records for Crossref — after the deposit, read the record back from Crossref and say what landed.
 *
 * A deposit is accepted first and processed later, so the only proof that a reference list or a ROR ID reached the
 * record is the record itself. This compares what the plugin counted in the file it let through (CcrSchema::summarize)
 * with what Crossref's public REST API shows for the same DOI (api.crossref.org/works/{doi}), element by element.
 * Until Crossref shows a deposit date at or after ours, the answer is "waiting", never "missing".
 *
 * PHP 7.3 syntax on purpose: OJS 3.3 still runs there.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

require_once __DIR__ . '/CcrCompleter.php';

class CcrReadBack
{
    /** The elements compared, with the words shown for each. */
    const LABELS = [
        'references' => 'reference list', 'affiliations' => 'affiliations', 'ror' => 'ROR IDs', 'orcid' => 'ORCID iDs',
        'abstract' => 'abstract', 'license' => 'licence', 'funders' => 'funding', 'links' => 'full-text links',
        'crossmark' => 'Crossmark', 'relations' => 'relations',
    ];

    /** A Crossref REST "message" for one work, counted the same way as CcrSchema::summarize counts a deposit file. */
    public static function summarizeWork(array $m)
    {
        $s = ['references' => 0, 'affiliations' => 0, 'ror' => 0, 'orcid' => 0, 'authors' => 0, 'abstract' => false,
              'license' => false, 'funders' => 0, 'links' => 0, 'crossmark' => '', 'relations' => 0];
        $s['references'] = isset($m['reference-count']) ? (int) $m['reference-count'] : (isset($m['reference']) && is_array($m['reference']) ? count($m['reference']) : 0);
        foreach ((isset($m['author']) && is_array($m['author']) ? $m['author'] : []) as $a) {
            $s['authors']++;
            if (!empty($a['ORCID'])) $s['orcid']++;
            foreach ((isset($a['affiliation']) && is_array($a['affiliation']) ? $a['affiliation'] : []) as $af) {
                $s['affiliations']++;
                foreach ((isset($af['id']) && is_array($af['id']) ? $af['id'] : []) as $id) {
                    if (strtolower((string) ($id['id-type'] ?? '')) === 'ror') $s['ror']++;
                }
            }
        }
        $s['abstract'] = !empty($m['abstract']);
        $s['license'] = !empty($m['license']);
        $s['funders'] = isset($m['funder']) && is_array($m['funder']) ? count($m['funder']) : 0;
        $s['links'] = isset($m['link']) && is_array($m['link']) ? count($m['link']) : 0;
        if (!empty($m['update-policy'])) $s['crossmark'] = strtolower(CcrCompleter::bareDoi(preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', (string) $m['update-policy'])) ?: (string) $m['update-policy']);
        if (isset($m['relation']) && is_array($m['relation'])) foreach ($m['relation'] as $list) $s['relations'] += is_array($list) ? count($list) : 0;
        return $s;
    }

    /**
     * Element by element: 'landed', 'partly' (fewer than sent), 'missing', or 'waiting' (Crossref has not processed our
     * deposit yet). Elements the deposit did not carry are not listed.
     *
     * @param array $sent         CcrSchema::summarize of the file sent
     * @param array $message      Crossref REST message for the DOI
     * @param int|null $sentAt    Unix time of our deposit; when Crossref's last deposit date is older, everything is 'waiting'
     */
    public static function compare(array $sent, array $message, $sentAt = null)
    {
        $have = self::summarizeWork($message);
        $waiting = false;
        if ($sentAt !== null && isset($message['deposited']['date-time'])) {
            $dep = strtotime((string) $message['deposited']['date-time']);
            // Crossref stamps the deposit with its own clock; a minute of slack keeps a clock difference from deciding
            if ($dep !== false && $dep + 60 < (int) $sentAt) $waiting = true;
        }
        $out = [];
        foreach (self::LABELS as $k => $label) {
            if (!isset($sent[$k])) continue;
            $want = $sent[$k];
            if ($want === false || $want === 0 || $want === '') continue;
            if ($waiting) { $out[$k] = 'waiting'; continue; }
            $got = $have[$k];
            if (is_bool($want)) $out[$k] = $got ? 'landed' : 'missing';
            elseif (is_string($want)) $out[$k] = (strtolower($got) === strtolower($want)) ? 'landed' : ($got === '' ? 'missing' : 'partly');
            else $out[$k] = $got >= $want ? 'landed' : ($got > 0 ? 'partly' : 'missing');
        }
        return $out;
    }

    /** One line a person can read: "reference list landed · ROR IDs missing · …". */
    public static function sentence(array $compared)
    {
        $parts = [];
        foreach ($compared as $k => $st) $parts[] = (isset(self::LABELS[$k]) ? self::LABELS[$k] : $k) . ' ' . $st;
        return implode(' · ', $parts);
    }
}
