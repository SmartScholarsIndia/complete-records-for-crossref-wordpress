<?php
/**
 * Complete Records for Crossref (WordPress) — assemble the plugin the way it ships.
 *
 *   php tools/build.php           build/complete-records-for-crossref (the plugin folder, with core/)
 *   php tools/build.php --zip     …and dist/complete-records-for-crossref-<version>.zip (WordPress's "Upload Plugin")
 *   php tools/build.php --quiet   no output unless something is wrong
 *
 * core/ is the shared core of Complete Records for Crossref (the OJS plugin): taken from ./core when this folder has one
 * (the public repository), else from ../ojs-plugin/core (the workspace both plugins are developed in).
 * The zip is reproducible: entries in name order, every time stamp the release date, so a build of the tagged source
 * gives the same bytes as the release.
 */
$root = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);
$zip = in_array('--zip', $argv, true);
$say = function ($s) use ($quiet) { if (!$quiet) echo $s, "\n"; };
$fail = function ($s) { fwrite(STDERR, "build: $s\n"); exit(1); };

$src = "$root/complete-records-for-crossref";
$core = is_dir("$root/core") ? "$root/core" : dirname($root) . '/ojs-plugin/core';
if (!is_dir($src)) $fail('no complete-records-for-crossref folder');
if (!is_file("$core/CcrCompleter.php")) $fail("the shared core is not at $core");
$head = file_get_contents("$src/complete-records-for-crossref.php");
if (!preg_match('/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $head, $m)) $fail('no Version in the plugin header');
$version = $m[1];
if (!preg_match("/define\\('CCRWP_VERSION', '" . preg_quote($version, '/') . "'\\)/", $head)) $fail("CCRWP_VERSION does not say $version");
if (!preg_match('/^Stable tag:\s*' . preg_quote($version, '/') . '\s*$/m', (string) file_get_contents("$src/readme.txt"))) $fail("readme.txt's Stable tag is not $version");

function ccr_rrm($p) {
    if (is_dir($p) && !is_link($p)) { foreach (scandir($p) as $f) if ($f !== '.' && $f !== '..') ccr_rrm("$p/$f"); return rmdir($p); }
    return !file_exists($p) || unlink($p);
}
function ccr_copy($from, $to) {
    if (is_dir($from)) {
        if (!is_dir($to) && !mkdir($to, 0755, true)) return false;
        foreach (scandir($from) as $f) if ($f !== '.' && $f !== '..' && !ccr_copy("$from/$f", "$to/$f")) return false;
        return true;
    }
    return copy($from, $to);
}
$dest = "$root/build/complete-records-for-crossref";
ccr_rrm("$root/build");
if (!ccr_copy($src, $dest) || !ccr_copy($core, "$dest/core")) $fail('copy failed');
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $files[] = substr((string) $f, strlen("$root/build/"));
    if (substr((string) $f, -4) === '.php') {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg((string) $f) . ' 2>&1', $out, $rc);
        if ($rc !== 0) $fail("does not parse: $f");
    }
}
sort($files);
$say("assembled $version in build/complete-records-for-crossref (" . count($files) . ' files)');
if ($zip) {
    if (!is_dir("$root/dist")) mkdir("$root/dist", 0755, true);
    $name = "complete-records-for-crossref-$version.zip";
    @unlink("$root/dist/$name");
    if (!preg_match('/^= ' . preg_quote($version, '/') . ' =/m', (string) file_get_contents("$src/readme.txt"))) $fail("readme.txt's changelog has no entry for $version");
    $t = gmmktime(0, 0, 0, 10, 3, 2026);   // the release date of 1.0.0, as every entry's time stamp
    if (!class_exists('ZipArchive') || getenv('CCR_BUILD_NO_ZIPARCHIVE')) {
        // a PHP without its zip extension (some hosts' builds): Python's zipfile writes the same entries, in the same
        // order, with the same time stamp — reproducible on its own, though not byte for byte the same as libzip's
        $py = 'import sys, zipfile, os' . "\n"
            . 'root, out, names = sys.argv[1], sys.argv[2], sys.argv[3:]' . "\n"
            . 'with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:' . "\n"
            . '    dirs = sorted({"/".join(n.split("/")[:i]) + "/" for n in names for i in range(1, len(n.split("/")))})' . "\n"
            . '    for d in dirs:' . "\n"
            . '        zi = zipfile.ZipInfo(d, (2026, 10, 3, 0, 0, 0)); zi.external_attr = 0o40755 << 16; z.writestr(zi, b"")' . "\n"
            . '    for n in names:' . "\n"
            . '        zi = zipfile.ZipInfo(n, (2026, 10, 3, 0, 0, 0)); zi.compress_type = zipfile.ZIP_DEFLATED; zi.external_attr = 0o100644 << 16' . "\n"
            . '        z.writestr(zi, open(os.path.join(root, n), "rb").read(), compresslevel=9)' . "\n";
        $cmd = 'python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg("$root/build") . ' ' . escapeshellarg("$root/dist/$name");
        foreach ($files as $rel) $cmd .= ' ' . escapeshellarg($rel);
        exec($cmd . ' 2>&1', $out, $rc);
        if ($rc !== 0 || !is_file("$root/dist/$name")) $fail("dist/$name could not be written (no zip extension in PHP, and Python's zipfile failed: " . implode(' ', $out) . ')');
        $say("  packed dist/$name (" . filesize("$root/dist/$name") . ' bytes, with Python\'s zipfile)');
        exit(0);
    }
    $z = new ZipArchive();
    if ($z->open("$root/dist/$name", ZipArchive::CREATE | ZipArchive::EXCL) !== true) $fail("cannot write dist/$name");
    $dirs = [];
    foreach ($files as $rel) {
        $parts = explode('/', $rel);
        for ($i = 1; $i < count($parts); $i++) $dirs[implode('/', array_slice($parts, 0, $i)) . '/'] = true;
    }
    ksort($dirs);
    foreach (array_keys($dirs) as $d) { $z->addEmptyDir(rtrim($d, '/')); $z->setMtimeName($d, $t); }
    foreach ($files as $rel) {
        $z->addFile("$root/build/$rel", $rel);
        $z->setMtimeName($rel, $t);
        $z->setCompressionName($rel, ZipArchive::CM_DEFLATE, 9);
    }
    if (!$z->close()) $fail("dist/$name could not be finished");
    $say("  packed dist/$name (" . filesize("$root/dist/$name") . ' bytes)');
}
exit(0);
