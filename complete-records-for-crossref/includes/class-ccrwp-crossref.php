<?php
/**
 * Talking to Crossref: the deposit, Crossref's own result for it, the schema files, and the record read back.
 *
 *   deposit   POST {doi|test}.crossref.org/servlet/deposit (operation=doMDUpload, the journal's login, the file)
 *   result    POST …/servlet/submissionDownload (type=result) — Crossref's report on that one deposit, credentials in the
 *             form body, never in an address
 *   read back GET api.crossref.org/works/{doi} — the public record, compared element by element with what was sent
 *
 * Crossref's test system (test.crossref.org) keeps its own copy of members' logins and its records are not in the public
 * REST API: there, Crossref's result is the read-back.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

defined('ABSPATH') || exit;

class CcrWp_Crossref
{
    /** Where deposits go. A filter, so a test can point it at a stand-in; journals choose test or live in the settings. */
    public static function base($server)
    {
        return apply_filters('ccrwp_crossref_base', $server === 'live' ? 'https://doi.crossref.org' : 'https://test.crossref.org', $server);
    }

    public static function api()
    {
        return apply_filters('ccrwp_crossref_api', 'https://api.crossref.org');
    }

    /** Where Crossref's schema files are kept once fetched (the uploads folder; they are public files). */
    public static function schemaDir()
    {
        $u = wp_upload_dir(null, false);
        return apply_filters('ccrwp_schema_dir', trailingslashit($u['basedir']) . 'ccrwp-schemas');
    }

    /** The finished file checked against Crossref's published schema for its version (CcrValidator, as in OJS). */
    public static function validate(DOMDocument $doc)
    {
        $fetch = apply_filters('ccrwp_schema_fetch', function ($url) {
            $r = wp_remote_get($url, ['timeout' => 30, 'redirection' => 3, 'user-agent' => self::agent()]);
            return (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) ? (string) wp_remote_retrieve_body($r) : null;
        });
        return CcrValidator::check($doc, self::schemaDir(), $fetch);
    }

    public static function agent()
    {
        return 'complete-records-for-crossref-wordpress/' . CCRWP_VERSION . ' (' . home_url('/') . ')';
    }

    /** Send the file. Returns ['ok' => bool, 'message' => words for a person]. */
    public static function deposit($xml, $fileName, $user, $pass, $server)
    {
        $boundary = 'ccrwp' . wp_generate_password(24, false);
        $body = '';
        foreach (['operation' => 'doMDUpload', 'login_id' => $user, 'login_passwd' => $pass] as $k => $v) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"fname\"; filename=\"" . $fileName . "\"\r\nContent-Type: application/xml\r\n\r\n"
               . $xml . "\r\n--$boundary--\r\n";
        $r = wp_remote_post(self::base($server) . '/servlet/deposit', [
            'timeout' => 120, 'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary], 'body' => $body,
            'user-agent' => self::agent(),
        ]);
        if (is_wp_error($r)) return ['ok' => false, 'message' => 'Crossref could not be reached: ' . $r->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($r);
        $text = trim(preg_replace('~\s+~', ' ', wp_strip_all_tags((string) wp_remote_retrieve_body($r))));
        if ($code === 401) {
            return ['ok' => false, 'message' => $server === 'test'
                ? 'Crossref\'s test system did not accept this login (HTTP 401). The test system keeps its own copy of members\' logins, so a login that works on the live system is often not on it at all — this does not mean your live login is wrong. Ask Crossref for a test login, or deposit on the live system.'
                : 'Crossref did not accept this login (HTTP 401). A sponsored member\'s login is often the e-mail address followed by /ROLE.'];
        }
        if ($code !== 200 || stripos($text, 'SUCCESS') === false) {
            return ['ok' => false, 'message' => sprintf('Crossref did not take the file (HTTP %d): %s', $code, mb_substr($text, 0, 300))];
        }
        return ['ok' => true, 'message' => 'Crossref received the file. It processes deposits in its own queue — usually within minutes.'];
    }

    /**
     * Crossref's result for one deposit. Returns ['state' => success|failure|warning|waiting|unknown, 'message' => …,
     * 'min_stamp' => version number Crossref named in a "previously submitted version" refusal, or ''].
     */
    public static function result($batchId, $user, $pass, $server)
    {
        $r = wp_remote_post(self::base($server) . '/servlet/submissionDownload', [
            'timeout' => 60, 'user-agent' => self::agent(),
            'body' => ['usr' => $user, 'pwd' => $pass, 'file_name' => $batchId . '.xml', 'doi_batch_id' => $batchId, 'type' => 'result'],
        ]);
        if (is_wp_error($r)) return ['state' => 'unknown', 'message' => 'Crossref could not be reached: ' . $r->get_error_message(), 'min_stamp' => ''];
        $code = (int) wp_remote_retrieve_response_code($r);
        $body = (string) wp_remote_retrieve_body($r);
        if ($code === 401) return ['state' => 'unknown', 'message' => 'Crossref did not accept the login when asked for the result (HTTP 401).', 'min_stamp' => ''];
        return self::readResult($code, $body);
    }

    /** Crossref's doi_batch_diagnostic read into a state (kept apart from the request, so it is tested on its own). */
    public static function readResult($code, $body)
    {
        $out = ['state' => 'unknown', 'message' => '', 'min_stamp' => ''];
        if ($code !== 200 || strpos($body, '<') === false) {
            $out['message'] = sprintf('Crossref answered HTTP %d without a report.', $code);
            return $out;
        }
        if (stripos($body, 'unknown_submission') !== false) {
            $out['state'] = 'waiting';
            $out['message'] = 'Crossref has not processed this deposit yet (it is still in its queue). Ask again in a few minutes.';
            return $out;
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($x === false || $x->getName() !== 'doi_batch_diagnostic') {
            $out['message'] = 'Crossref\'s answer was not a deposit report.';
            return $out;
        }
        $status = strtolower((string) $x['status']);
        if ($status !== 'completed') {
            $out['state'] = 'waiting';
            $out['message'] = sprintf('Crossref is still processing this deposit (its report says "%s"). Ask again in a few minutes.', $status ?: 'not finished');
            return $out;
        }
        $msgs = [];
        $worst = 'success';
        foreach ($x->record_diagnostic as $rd) {
            $st = strtolower((string) $rd['status']);
            $msg = trim((string) $rd->msg);
            $msgs[] = trim((string) $rd->doi) . ': ' . ($msg !== '' ? $msg : $st);
            if ($st === 'failure') $worst = 'failure';
            elseif ($st === 'warning' && $worst === 'success') $worst = 'warning';
            if (preg_match('~previously submitted version[:\s]+(\d+)~i', $msg, $m)) $out['min_stamp'] = $m[1];
        }
        if (!$msgs) {
            $out['message'] = 'Crossref\'s report names no record.';
            return $out;
        }
        $out['state'] = $worst;
        $out['message'] = implode(' · ', $msgs);
        return $out;
    }

    /** The public record of a DOI (Crossref's REST API "message"), or null when Crossref shows none (yet). */
    public static function work($doi, $mailto)
    {
        $url = self::api() . '/works/' . rawurlencode($doi) . ($mailto !== '' ? '?mailto=' . rawurlencode($mailto) : '');
        $r = wp_remote_get($url, ['timeout' => 30, 'user-agent' => self::agent()]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) return null;
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        return (is_array($j) && isset($j['message']) && is_array($j['message'])) ? $j['message'] : null;
    }
}
