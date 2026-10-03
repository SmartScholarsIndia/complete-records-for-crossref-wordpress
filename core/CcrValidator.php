<?php
/**
 * Complete Records for Crossref — check a deposit file against Crossref's own published schema before it is sent.
 *
 * The file names its schema version (doi_batch xmlns="http://www.crossref.org/schema/5.3.1"). That schema
 * (https://www.crossref.org/schemas/crossref5.3.1.xsd) and every file it includes or imports, on any host (Crossref's
 * common, fundref, AccessIndicators, relations, clinicaltrials and JATS files, W3C's MathML and xml.xsd), are copied
 * once into a cache folder laid out by host and path, so each relative schemaLocation resolves on disk exactly as on
 * the web; afterwards the check uses no network. libxml (PHP's XML library) then checks the file, reading every
 * schema from the copy only.
 *
 * Crossref's 4.3.6 set names an xml.xsd that crossref.org does not serve; W3C's copy of that standard file stands in for it
 * (see XML_XSD), and the result names every file stood in for ('stoodIn').
 *
 * Three answers, never a guess:
 *   valid     — checked completely against the schema;
 *   invalid   — the schema's own messages are returned;
 *   unchecked — the file could NOT be checked, and why: a schema file could not be had, or the schema uses XML
 *               Schema 1.1 assertions (5.5.0 does), which libxml cannot read. "unchecked" is never called valid.
 *
 * Schemas 4.3.6 (OJS 3.3), 5.3.1 (OJS 3.4) and 5.4.0 (OJS 3.5) are XML Schema 1.0. 5.3.1 and 5.4.0 were checked to
 * compile and validate under libxml on 3 Oct 2026 (copies of Crossref's files; tests/test_validator.php).
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3.
 */
final class CcrValidator
{
    const SCHEMAS = 'https://www.crossref.org/schemas/';
    const XS = 'http://www.w3.org/2001/XMLSchema';

    /**
     * Crossref's 4.3.6 schema set (OJS 3.3) names xml.xsd beside its own files, https://www.crossref.org/schemas/xml.xsd,
     * and crossref.org answers 404 there (checked 3 Oct 2026; seen on a member journal's OJS the same morning). The schema of
     * the XML namespace is W3C's own, and Crossref's 5.4.0 schema imports it from this address. When a set's xml.xsd cannot
     * be had where the set names it, this copy stands in — and the check says so.
     */
    const XML_XSD = 'http://www.w3.org/2009/01/xml.xsd';
    const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /** The schema version a deposit file names, or null when it is not a Crossref deposit file. */
    public static function version(DOMDocument $doc)
    {
        $root = $doc->documentElement;
        if (!$root || $root->localName !== 'doi_batch') {
            return null;
        }
        return preg_match('~^http://www\.crossref\.org/schema/(\d+\.\d+\.\d+)$~', (string) $root->namespaceURI, $m) ? $m[1] : null;
    }

    /** https://www.crossref.org/schemas/a.xsd -> <cache>/www.crossref.org/schemas/a.xsd — never outside the cache. */
    public static function localPath($cacheDir, $url)
    {
        $u = parse_url($url);
        $parts = [];
        foreach (explode('/', (isset($u['host']) ? $u['host'] : '') . (isset($u['port']) ? '_' . $u['port'] : '') . '/' . (isset($u['path']) ? $u['path'] : '')) as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                continue;
            }
            $parts[] = preg_replace('/[^A-Za-z0-9._-]/', '_', $p);
        }
        return rtrim($cacheDir, '/') . '/' . implode('/', $parts);
    }

    /**
     * Copy $rootUrl and everything it reaches into the cache. $fetch(string $url): ?string returns a file's body, or
     * null when it cannot be had; with $fetch null only the copies already in the cache are used.
     *
     * @return array [files (local paths of the whole set), missing (url => why), stoodIn (url => W3C's address)]
     */
    public static function mirror($rootUrl, $cacheDir, $fetch = null)
    {
        $queue = [$rootUrl];
        $seen = [];
        $files = [];
        $missing = [];
        $stoodIn = [];
        while ($queue) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $path = self::localPath($cacheDir, $url);
            $data = is_file($path) && filesize($path) > 0 ? file_get_contents($path) : null;
            if ($data !== null && is_file($path . '.stand-in')) {
                $stoodIn[$url] = trim((string) file_get_contents($path . '.stand-in'));
            }
            if ($data === null) {
                $why = 'not in the copy, and nothing may be fetched';
                if ($fetch !== null) {
                    $got = $fetch($url);
                    if (!is_string($got) || $got === '') {
                        $why = 'could not be fetched';
                    } elseif (strpos(substr($got, 0, 400000), self::XS) === false) {
                        $why = 'not a schema (' . strlen($got) . ' bytes beginning ' . json_encode(substr($got, 0, 40)) . ')';
                    } else {
                        $data = $got;
                    }
                }
                if ($data === null && ($alt = self::xmlStandIn($url, $cacheDir, $fetch)) !== null) {
                    $data = $alt;
                    $stoodIn[$url] = self::XML_XSD;
                }
                if ($data === null) {
                    $missing[$url] = $why;
                    continue;
                }
                if (!self::keep($path, $data) || (isset($stoodIn[$url]) && !self::keep($path . '.stand-in', self::XML_XSD))) {
                    $missing[$url] = 'the copy could not be written in ' . dirname($path);
                    continue;
                }
            }
            $kids = self::children($data, $url);
            if ($kids === null) {
                $missing[$url] = 'the copy is not well-formed XML';
                continue;
            }
            $files[] = $path;
            foreach ($kids as $k) {
                if (preg_match('~^https?://~', $k) && !isset($seen[$k])) {
                    $queue[] = $k;
                }
            }
        }
        return [$files, $missing, $stoodIn];
    }

    /** W3C's xml.xsd for a set's xml.xsd that cannot be had where the set names it; null when that does not apply. */
    private static function xmlStandIn($url, $cacheDir, $fetch)
    {
        if ($url === self::XML_XSD || basename((string) parse_url($url, PHP_URL_PATH)) !== 'xml.xsd') {
            return null;
        }
        $p = self::localPath($cacheDir, self::XML_XSD);
        $data = is_file($p) && filesize($p) > 0 ? file_get_contents($p) : null;
        if ($data === null && $fetch !== null) {
            $data = $fetch(self::XML_XSD);
            if (is_string($data) && self::isXmlNamespaceSchema($data)) {
                self::keep($p, $data);
            }
        }
        return is_string($data) && self::isXmlNamespaceSchema($data) ? $data : null;
    }

    /** True for a schema of the XML namespace (xml:lang, xml:space, xml:base, xml:id). */
    private static function isXmlNamespaceSchema($xml)
    {
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $r = $ok ? $d->documentElement : null;
        return $r && $r->localName === 'schema' && $r->namespaceURI === self::XS && $r->getAttribute('targetNamespace') === self::XML_NS;
    }

    /** Write a copy atomically (a part file, then a rename); false when it cannot be written. */
    private static function keep($path, $data)
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            return false;
        }
        $tmp = $path . '.part' . getmypid();
        if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Check $doc. Returns ['state' => valid|invalid|unchecked, 'version' => ?, 'errors' => [line: message, …],
     * 'why' => '' or the reason it was not checked, 'files' => schema files used, 'stoodIn' => url => W3C's address].
     */
    public static function check(DOMDocument $doc, $cacheDir, $fetch = null, $schemasUrl = self::SCHEMAS)
    {
        $out = ['state' => 'unchecked', 'version' => null, 'errors' => [], 'why' => '', 'files' => 0, 'stoodIn' => []];
        // OJS's exportXML switches libxml's internal errors on, runs the filter (and this check, inside its hook), then
        // fails the export on any ERROR or FATAL message in libxml's buffer. So the buffer is left exactly as found: when
        // it already holds an error from OJS's own work, nothing here touches it (clearing it would hide OJS's error);
        // otherwise every message this check makes is cleared before it returns.
        foreach (libxml_get_errors() as $e) {
            if ($e->level >= LIBXML_ERR_ERROR) {
                $out['why'] = 'PHP\'s XML library already holds an error from OJS\'s own work on this file (' . trim($e->message)
                    . '), so it was left untouched and this file was not checked';
                return $out;
            }
        }
        $v = self::version($doc);
        if ($v === null) {
            $out['why'] = 'not a Crossref deposit file';
            return $out;
        }
        $out['version'] = $v;
        $rootUrl = rtrim($schemasUrl, '/') . '/crossref' . $v . '.xsd';
        list($files, $missing, $stoodIn) = self::mirror($rootUrl, $cacheDir, $fetch);
        $out['files'] = count($files);
        $out['stoodIn'] = $stoodIn;
        if ($missing) {
            $why = [];
            foreach ($missing as $u => $w) {
                $why[] = $u . ' — ' . $w;
            }
            $out['why'] = 'Crossref\'s schema ' . $v . ' is incomplete here: ' . implode('; ', array_slice($why, 0, 3)) . (count($why) > 3 ? '; …' : '');
            return $out;
        }
        foreach ($files as $f) {
            if (self::hasAssertions(file_get_contents($f))) {
                $out['why'] = 'schema ' . $v . ' uses XML Schema 1.1 assertions, which PHP\'s XML library cannot read';
                return $out;
            }
        }
        $real = realpath($cacheDir);
        $loader = function ($public, $system, $context) use ($cacheDir, $real) {
            $system = (string) $system;
            if (preg_match('~^https?://~', $system)) {
                $p = CcrValidator::localPath($cacheDir, $system);
                return is_file($p) ? $p : null;
            }
            // a local schema file: only ever one inside the copy
            $rp = realpath(preg_replace('~^file://~', '', $system));
            return ($rp !== false && $real !== false && strpos($rp, $real . DIRECTORY_SEPARATOR) === 0) ? $rp : null;
        };
        $previousLoader = function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader($loader);
        try {
            $ok = @$doc->schemaValidate(self::localPath($cacheDir, $rootUrl));
            $errs = libxml_get_errors();
        } finally {
            libxml_set_external_entity_loader($previousLoader);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $schemaErrors = [];
        foreach ($errs as $e) {
            if ($e->level < LIBXML_ERR_ERROR) {
                continue;   // warnings, e.g. "Skipping import … already imported": not about the file
            }
            $where = preg_replace('~^file://~', '', (string) $e->file);
            // an error raised while reading a schema file (it names a file in the copy, or no line of the deposit)
            // means the schema could not be read — the deposit was not checked; never call that "invalid"
            if ($where !== '' && ($real !== false && strpos((string) realpath($where), $real) === 0 || strpos($where, rtrim($cacheDir, '/')) === 0)) {
                $schemaErrors[] = basename($where) . ' line ' . $e->line . ': ' . trim($e->message);
                continue;
            }
            $out['errors'][] = 'line ' . $e->line . ': ' . trim($e->message);
        }
        if ($schemaErrors) {
            $out['errors'] = [];
            $out['why'] = 'Crossref\'s schema ' . $v . ' could not be read here: ' . implode('; ', array_slice($schemaErrors, 0, 2));
        } elseif ($ok && !$out['errors']) {
            $out['state'] = 'valid';
        } elseif (!$out['errors']) {
            $out['why'] = 'Crossref\'s schema ' . $v . ' could not be read here';
        } else {
            $out['state'] = 'invalid';
        }
        return $out;
    }

    /** The absolute URLs a schema file includes, imports, redefines or overrides; null when it is not well-formed. */
    private static function children($xml, $url)
    {
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$d->documentElement) {
            return null;
        }
        $out = [];
        foreach (['include', 'import', 'redefine', 'override'] as $tag) {
            foreach ($d->getElementsByTagNameNS(self::XS, $tag) as $el) {
                $loc = trim($el->getAttribute('schemaLocation'));
                if ($loc !== '') {
                    $out[] = self::resolve($url, $loc);
                }
            }
        }
        return $out;
    }

    private static function hasAssertions($xml)
    {
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok && ($d->getElementsByTagNameNS(self::XS, 'assert')->length + $d->getElementsByTagNameNS(self::XS, 'assertion')->length) > 0;
    }

    /** A schemaLocation made absolute against the URL of the file that names it. */
    public static function resolve($base, $loc)
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $loc)) {
            return $loc;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        $dir = isset($b['path']) ? preg_replace('~/[^/]*$~', '/', $b['path']) : '/';
        $path = $loc[0] === '/' ? $loc : $dir . $loc;
        $seg = [];
        foreach (explode('/', $path) as $p) {
            if ($p === '..') {
                array_pop($seg);
            } elseif ($p !== '.' && $p !== '') {
                $seg[] = $p;
            }
        }
        return $origin . '/' . implode('/', $seg);
    }
}
