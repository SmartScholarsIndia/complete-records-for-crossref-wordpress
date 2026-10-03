<?php
/**
 * The journal's settings, and its Crossref login kept encrypted.
 *
 * The password is encrypted with a key made from this site's own secret keys in wp-config.php (wp_salt('auth')), using
 * libsodium's secretbox (WordPress ships sodium_compat, so it is there on every host). It is never shown again: the
 * settings page says "stored" and an empty field keeps it. If the site's keys change, the stored password can no
 * longer be read and the page says so — the journal types it once more.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

defined('ABSPATH') || exit;

class CcrWp_Settings
{
    const OPTION = 'ccrwp_settings';

    public static function defaults()
    {
        return [
            'journal_title' => '', 'abbrev_title' => '', 'issn_e' => '', 'issn_p' => '', 'publisher' => '',
            'depositor_name' => '', 'depositor_email' => '', 'prefix' => '', 'suffix_pattern' => '{year}.{id}',
            'language' => 'en', 'licence' => '', 'crossmark_policy' => '', 'server' => 'test', 'post_types' => ['post'],
            'login_user' => '', 'login_pass' => '',
        ];
    }

    public static function all()
    {
        $v = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($v) ? $v : []);
    }

    public static function get($key)
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * Save what the settings form sent. Returns a list of problems (empty when everything was kept as typed).
     * A field with a problem keeps its earlier value.
     */
    public static function save(array $in)
    {
        $old = self::all();
        $new = $old;
        $problems = [];
        $text = function ($k, $max = 255) use ($in) {
            return isset($in[$k]) ? mb_substr(trim(sanitize_text_field(wp_unslash($in[$k]))), 0, $max) : '';
        };
        foreach (['journal_title', 'abbrev_title', 'publisher', 'depositor_name'] as $k) $new[$k] = $text($k);
        foreach (['issn_e' => 'online ISSN', 'issn_p' => 'print ISSN'] as $k => $label) {
            $v = strtoupper($text($k, 9));
            if ($v !== '' && !self::isIssn($v)) { $problems[] = sprintf('The %s "%s" is not an ISSN (its check digit does not match).', $label, $v); continue; }
            $new[$k] = $v;
        }
        $email = $text('depositor_email');
        if ($email !== '' && !is_email($email)) $problems[] = sprintf('"%s" is not an e-mail address.', $email);
        else $new['depositor_email'] = $email;
        $prefix = $text('prefix', 40);
        if ($prefix !== '' && !preg_match('~^10\.\d{4,9}$~', $prefix)) $problems[] = sprintf('"%s" is not a DOI prefix (10. and four or more digits).', $prefix);
        else $new['prefix'] = $prefix;
        $pattern = $text('suffix_pattern', 100);
        if ($pattern === '' || !preg_match('~\{id\}~', $pattern) || !preg_match('~^[A-Za-z0-9._;()/{}-]+$~', $pattern)) {
            $problems[] = 'The DOI suffix pattern must contain {id} and only letters, digits and - . _ ; ( ) / — for example {year}.{id}.';
        } else {
            $new['suffix_pattern'] = $pattern;
        }
        $lang = strtolower($text('language', 5));
        $new['language'] = preg_match('~^[a-z]{2}$~', $lang) ? $lang : 'en';
        $lic = isset($in['licence']) ? esc_url_raw(trim(wp_unslash($in['licence']))) : '';
        $new['licence'] = $lic;
        $policy = CcrCompleter::bareDoi($text('crossmark_policy', 200));
        if ($text('crossmark_policy', 200) !== '' && $policy === '') $problems[] = 'The Crossmark policy must be a DOI (the DOI of your Crossmark policy page).';
        else $new['crossmark_policy'] = $policy;
        $new['server'] = (isset($in['server']) && $in['server'] === 'live') ? 'live' : 'test';
        $types = [];
        foreach ((isset($in['post_types']) && is_array($in['post_types']) ? $in['post_types'] : []) as $t) {
            $t = sanitize_key(wp_unslash($t));
            if (post_type_exists($t)) $types[] = $t;
        }
        $new['post_types'] = $types ?: ['post'];
        $new['login_user'] = $text('login_user', 200);
        $pass = isset($in['login_pass']) ? (string) wp_unslash($in['login_pass']) : '';
        if ($pass !== '') $new['login_pass'] = self::encrypt($pass);   // an empty field keeps the stored password
        update_option(self::OPTION, $new, false);
        return $problems;
    }

    /** ISSN check digit (ISO 3297): weights 8..2, mod 11, X for 10. */
    public static function isIssn($v)
    {
        if (!preg_match('~^(\d{4})-?(\d{3})([\dX])$~', strtoupper($v), $m)) return false;
        $d = $m[1] . $m[2];
        $sum = 0;
        for ($i = 0; $i < 7; $i++) $sum += (int) $d[$i] * (8 - $i);
        $c = (11 - $sum % 11) % 11;
        return ($c === 10 ? 'X' : (string) $c) === $m[3];
    }

    /** The key: this site's own secret keys, never stored anywhere by the plugin. */
    private static function key()
    {
        return hash('sha256', wp_salt('auth') . '|complete-records-for-crossref|crossref-login', true);
    }

    public static function encrypt($plain)
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox((string) $plain, $nonce, self::key()));
    }

    /** The plain password, or null when none is stored or it cannot be read with this site's keys. */
    public static function decrypt($stored)
    {
        if (!is_string($stored) || strpos($stored, 'sb1:') !== 0) return null;
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $plain === false ? null : $plain;
    }

    /** 'none', 'stored' or 'unreadable' — what the settings page says about the password. */
    public static function passwordState()
    {
        $s = self::get('login_pass');
        if (!is_string($s) || $s === '') return 'none';
        return self::decrypt($s) === null ? 'unreadable' : 'stored';
    }

    /** What is still missing before anything can be deposited (words for a person). */
    public static function missing()
    {
        $s = self::all();
        $miss = [];
        if ($s['journal_title'] === '') $miss[] = 'the journal\'s title';
        if ($s['issn_e'] === '' && $s['issn_p'] === '') $miss[] = 'an ISSN';
        if ($s['publisher'] === '') $miss[] = 'the publisher (registrant)';
        if ($s['depositor_name'] === '' || $s['depositor_email'] === '') $miss[] = 'the depositor\'s name and e-mail';
        if ($s['prefix'] === '') $miss[] = 'the DOI prefix';
        if ($s['login_user'] === '' || self::passwordState() !== 'stored') $miss[] = 'the Crossref login';
        return $miss;
    }
}
