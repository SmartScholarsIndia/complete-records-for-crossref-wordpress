<?php
/**
 * The plugin inside a real WordPress (tests/wp_env.sh), talking to the stand-in for Crossref (tests/stand_in_crossref.py).
 *
 *   php tests/test_wp_plugin.php SITE_DIR STAND_IN_LOG
 *
 * Settings (the password kept encrypted), an article's Crossref record (ORCID and ROR checked), the deposit file — built
 * as OJS's Crossref plugin builds one and completed by the shared core — checked against copies of Crossref's 5.4.0
 * schema, the deposit, Crossref's result (queued, then completed), the version-number rule, and the read-back.
 */
$site = $argv[1];
$standInLog = $argv[2];
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user(1);

$P = 0; $F = 0;
function ck($label, $ok, $detail = '') {
    global $P, $F;
    if ($ok) { $P++; echo "  ok   $label\n"; } else { $F++; echo "  FAIL $label\n"; if ($detail !== '') echo '       ' . str_replace("\n", "\n       ", substr(is_string($detail) ? $detail : var_export($detail, true), 0, 1500)) . "\n"; }
}
$pass = trim(file_get_contents($site . '/.standin-pass'));

echo "--- settings, and the Crossref password kept encrypted ---\n";
$problems = CcrWp_Settings::save([
    'journal_title' => 'Example Journal of Testing & Practice', 'abbrev_title' => 'Ex. J. Test. Pract.', 'issn_e' => '1234-5679',
    'issn_p' => '1234-5678', 'publisher' => 'Example Publishers', 'depositor_name' => 'Example Publishers',
    'depositor_email' => 'editor@journal.example.org', 'prefix' => '10.5555', 'suffix_pattern' => 'ejtp.{year}.{id}',
    'language' => 'en', 'licence' => 'https://creativecommons.org/licenses/by/4.0/', 'crossmark_policy' => 'https://doi.org/10.5555/example.crossmark',
    'server' => 'live', 'post_types' => ['post'], 'login_user' => 'example-user/role', 'login_pass' => $pass,
]);
ck('a print ISSN whose check digit is wrong is refused, and named', count($problems) === 1 && strpos($problems[0], '1234-5678') !== false, $problems);
$raw = get_option(CcrWp_Settings::OPTION);
ck('the password is stored encrypted (sb1:…), never as typed', strpos($raw['login_pass'], 'sb1:') === 0 && strpos($raw['login_pass'], $pass) === false && strpos(serialize($raw), $pass) === false);
ck('…and reads back with this site\'s keys', CcrWp_Settings::decrypt($raw['login_pass']) === $pass && CcrWp_Settings::passwordState() === 'stored');
CcrWp_Settings::save(array_merge(CcrWp_Settings::all(), ['login_pass' => '', 'issn_p' => '']));
ck('saving again with the password field empty keeps the stored password', CcrWp_Settings::decrypt(get_option(CcrWp_Settings::OPTION)['login_pass']) === $pass);
ck('the Crossmark policy is kept as a bare DOI', CcrWp_Settings::get('crossmark_policy') === '10.5555/example.crossmark');
ck('nothing missing before a deposit', CcrWp_Settings::missing() === [], CcrWp_Settings::missing());
$bad = CcrWp_Settings::encrypt('x');
ck('a password stored under other keys is "cannot be read", not "none"', CcrWp_Settings::decrypt('sb1:' . base64_encode(str_repeat('a', 40))) === null && CcrWp_Settings::decrypt($bad) === 'x');

echo "\n--- an article and its Crossref record ---\n";
$id = wp_insert_post(['post_title' => 'Records & Testing: An Example Article', 'post_content' => '<p>The article.</p>', 'post_excerpt' => 'Excerpt.',
                      'post_status' => 'publish', 'post_type' => 'post', 'post_date' => '2026-09-15 10:00:00'], true);
ck('the article is published', is_int($id) && get_post_status($id) === 'publish');
$post = get_post($id);
$refs = "1. Carberry J. An example of a cited article. Journal of Examples. 2020;1:1-10. https://doi.org/10.5555/12345678\n"
      . "[2] Second Author A. A cited book without a DOI. Example Press; 2019.\n\n"
      . "Third C. A reference naming its DOI as doi:10.1000/XYZ123.\n"
      . "Fourth D. Two DOIs in one line: 10.1000/aaa and 10.1000/bbb.\n";
$problems = CcrWp_Record::save($id, [
    'suffix' => '', 'abstract' => "First paragraph of the abstract, with < and & in it.\n\nSecond paragraph.",
    'volume' => '3', 'issue' => '2', 'first_page' => '15', 'last_page' => '28', 'references' => $refs,
    'licence' => '', 'pdf' => 'https://journal.example.org/files/article.pdf',
    'authors' => [
        ['given' => 'Josiah', 'family' => 'Carberry', 'orcid' => 'https://orcid.org/0000-0002-1825-0097', 'affiliation' => 'Example University (test value)', 'ror' => 'ror.org/0abcdef12'],
        ['given' => 'Ana R.', 'family' => 'Example', 'orcid' => '0000-0002-1825-0098', 'affiliation' => '', 'ror' => ''],
        ['given' => '', 'family' => '', 'orcid' => '', 'affiliation' => '', 'ror' => ''],
    ],
]);
$rec = CcrWp_Record::stored($id);
ck('an ORCID iD whose check digit fails is named and left out', count($problems) === 1 && strpos($problems[0], '0000-0002-1825-0098') !== false && $rec['authors'][1]['orcid'] === '', $problems);
ck('the good ORCID iD and ROR ID kept in Crossref\'s own forms', $rec['authors'][0]['orcid'] === 'https://orcid.org/0000-0002-1825-0097' && $rec['authors'][0]['ror'] === 'https://ror.org/0abcdef12');
ck('an empty author row is dropped', count($rec['authors']) === 2);
ck('the DOI: prefix + the pattern (ejtp.{year}.{id})', CcrWp_Record::doi($post) === '10.5555/ejtp.2026.' . $id, CcrWp_Record::doi($post));

echo "\n--- the deposit file: built as OJS builds one, completed by the shared core ---\n";
list($doc, $report, $d, $stamp, $batch) = CcrWp_Record::build($post);
$x = new DOMXPath($doc);
$x->registerNamespace('cr', 'http://www.crossref.org/schema/5.4.0');
$x->registerNamespace('jats', 'http://www.ncbi.nlm.nih.gov/JATS1');
$x->registerNamespace('ai', 'http://www.crossref.org/AccessIndicators.xsd');
$doi = CcrWp_Record::doi($post);
$row = $report['articles'][strtolower($doi)] ?? [];
ck('the core completed it: 4 references, 1 affiliation with its ROR ID, Crossmark', $report['changed'] && ($row['references'] ?? 0) === 4 && ($row['affiliations'] ?? 0) === 1 && ($row['ror'] ?? 0) === 1 && !empty($row['crossmark']), $report);
ck('no problem reported, nothing kept back', !$report['problems'] && !$report['kept_original'], $report['problems']);
ck('schema 5.4.0, the batch named after the article and its version number', CcrSchema::version($doc) === '5.4.0' && $batch === 'ccrwp-' . $id . '-' . $stamp && preg_match('/^\d{17}$/', $stamp));
ck('the person: given name, surname, affiliations, ORCID — in the schema\'s order', implode(',', array_map(function ($n) { return $n->localName; }, iterator_to_array($x->query('(//cr:person_name)[1]/*')))) === 'given_name,surname,affiliations,ORCID');
ck('the abstract: two paragraphs, xml:lang in the XML namespace, characters escaped in the file', $x->evaluate('count(//jats:abstract/jats:p)') == 2 && $x->evaluate('string(//jats:abstract/@xml:lang)') === 'en' && strpos($doc->saveXML(), 'with &lt; and &amp; in it') !== false);
ck('a reference that names exactly one DOI carries it; two DOIs: none asserted', $x->evaluate('string(//cr:citation[@key="ref1"]/cr:doi)') === '10.5555/12345678' && $x->evaluate('string(//cr:citation[@key="ref3"]/cr:doi)') === '10.1000/xyz123' && $x->evaluate('count(//cr:citation[@key="ref4"]/cr:doi)') == 0);
ck('list numbering dropped from the reference text', strpos($x->evaluate('string(//cr:citation[@key="ref2"]/cr:unstructured_citation)'), 'Second Author') === 0);
ck('Crossmark: the policy, and the article\'s own host as its domain', $x->evaluate('string(//cr:crossmark/cr:crossmark_policy)') === '10.5555/example.crossmark' && $x->evaluate('string(//cr:crossmark_domain/cr:domain)') === 'journal.localhost', $x->evaluate('string(//cr:crossmark_domain/cr:domain)'));
ck('…and the licence sits in custom_metadata (Crossmark or the bare program, never both)', $x->evaluate('count(//cr:crossmark/cr:custom_metadata/ai:program/ai:license_ref)') == 1 && $x->evaluate('count(/cr:doi_batch//cr:journal_article/ai:program)') == 0);
ck('the DOI leads to the article\'s page; the PDF named for Similarity Check and text mining', $x->evaluate('string(//cr:doi_data/cr:resource)') === get_permalink($post) && $x->evaluate('count(//cr:doi_data/cr:collection/cr:item)') == 2);
ck('the issue: volume 3, issue 2, pages 15–28', $x->evaluate('string(//cr:journal_volume/cr:volume)') === '3' && $x->evaluate('string(//cr:journal_issue/cr:issue)') === '2' && $x->evaluate('string(//cr:pages/cr:last_page)') === '28');
$sent = $report['sent'];
ck('what the file carries, counted as the read-back will count it', $sent['authors'] === 2 && $sent['orcid'] === 1 && $sent['affiliations'] === 1 && $sent['ror'] === 1 && $sent['references'] === 4 && $sent['abstract'] && $sent['license'] && $sent['links'] === 2 && $sent['crossmark'] === '10.5555/example.crossmark', $sent);
ck('what the record lacks, said before the deposit', CcrWp_Record::lacks($d) === ['ORCID iDs for 1 of 2 authors', 'affiliations for 1 of 2 authors'], CcrWp_Record::lacks($d));

echo "\n--- checked against Crossref's published schema (copies of 5.4.0) ---\n";
$v = CcrWp_Crossref::validate($doc);
ck('valid', $v['state'] === 'valid', $v);
$broken = new DOMDocument(); $broken->loadXML($doc->saveXML());
$bx = new DOMXPath($broken); $bx->registerNamespace('cr', 'http://www.crossref.org/schema/5.4.0');
$t = $bx->query('//cr:journal_article/cr:titles')->item(0); $t->parentNode->appendChild($t);   // titles moved to the end
$v2 = CcrWp_Crossref::validate($broken);
ck('a file with an element out of place: invalid, with the schema\'s own words', $v2['state'] === 'invalid' && $v2['errors'], $v2);

echo "\n--- the deposit, to the stand-in for Crossref ---\n";
$s = CcrWp_Settings::all();
$r = CcrWp_Crossref::deposit($doc->saveXML(), $batch . '.xml', $s['login_user'], 'not the password', 'live');
ck('a wrong password: refused (HTTP 401), said in words', !$r['ok'] && strpos($r['message'], 'HTTP 401') !== false && strpos($r['message'], '/ROLE') !== false, $r);
$r = CcrWp_Crossref::deposit($doc->saveXML(), $batch . '.xml', $s['login_user'], 'not the password', 'test');
ck('…on the test system the message says the test system keeps its own logins', !$r['ok'] && strpos($r['message'], 'test system keeps its own copy') !== false, $r);
$r = CcrWp_Crossref::deposit($doc->saveXML(), $batch . '.xml', $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
ck('the deposit: received', $r['ok'], $r);
$logText = file_get_contents($standInLog);
ck('Crossref got doMDUpload, the right login, the file named after the batch', strpos($logText, "POST live deposit operation=doMDUpload login_ok=True file=$batch.xml") !== false, $logText);
ck('the file Crossref got is the file that was checked', file_get_contents($standInLog . '.last.xml') === $doc->saveXML());
CcrWp_Record::addDeposit($id, ['at' => time(), 'batch' => $batch, 'stamp' => $stamp, 'server' => 'live', 'doi' => $doi, 'sent' => $sent, 'result' => 'sent', 'message' => '']);

echo "\n--- Crossref's result: queued first, then completed ---\n";
$res = CcrWp_Crossref::result($batch, $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
ck('first asked: still in Crossref\'s queue — "waiting", never "failed"', $res['state'] === 'waiting', $res);
$res = CcrWp_Crossref::result($batch, $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
ck('then: success, with Crossref\'s words for the DOI', $res['state'] === 'success' && strpos($res['message'], strtolower($doi) . ': Successfully added') !== false, $res);
CcrWp_Record::updateLatestDeposit($id, ['result' => $res['state'], 'message' => $res['message']]);
ck('the article counts as registered', (bool) CcrWp_Record::registered($id));
$res = CcrWp_Crossref::result('ccrwp-nothing', $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
ck('a batch Crossref does not know: "waiting" (not processed yet)', $res['state'] === 'waiting' && strpos($res['message'], 'not processed') !== false, $res);

echo "\n--- the version number: Crossref updates a record only with a greater one ---\n";
$next = CcrWp_Record::stamp($id);
ck('the next deposit\'s number is above the last one', strcmp($next, $stamp) > 0, "$next vs $stamp");
$old = new DOMDocument(); $old->loadXML($doc->saveXML());
$old->getElementsByTagName('timestamp')->item(0)->nodeValue = '20260101000000000';
$old->getElementsByTagName('doi_batch_id')->item(0)->nodeValue = 'ccrwp-old';
CcrWp_Crossref::deposit($old->saveXML(), 'ccrwp-old.xml', $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
CcrWp_Crossref::result('ccrwp-old', $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
$res = CcrWp_Crossref::result('ccrwp-old', $s['login_user'], CcrWp_Settings::decrypt($s['login_pass']), 'live');
ck('an older number: Crossref\'s refusal read as "failure", and the number it named kept', $res['state'] === 'failure' && $res['min_stamp'] === $stamp, $res);
update_post_meta($id, '_ccrwp_min_stamp', '29990101000000000');
ck('…the next deposit is numbered above what Crossref named', strcmp(CcrWp_Record::stamp($id), '29990101000000000') > 0, CcrWp_Record::stamp($id));
delete_post_meta($id, '_ccrwp_min_stamp');

echo "\n--- the read-back: Crossref's public record against what was sent ---\n";
$m = CcrWp_Crossref::work($doi, 'editor@journal.example.org');
ck('the record is there, asked with the depositor\'s address (Crossref\'s polite pool)', is_array($m) && strpos(file_get_contents($standInLog), 'GET works ' . strtolower($doi) . ' mailto=True') !== false);
$c = CcrReadBack::compare($sent, $m, time() - 5);
ck('every element landed: references, affiliations, ROR, ORCID, abstract, licence, links, Crossmark', $c === ['references' => 'landed', 'affiliations' => 'landed', 'ror' => 'landed', 'orcid' => 'landed', 'abstract' => 'landed', 'license' => 'landed', 'links' => 'landed', 'crossmark' => 'landed'], $c);
ck('a DOI Crossref does not show: null (not yet), never an error', CcrWp_Crossref::work('10.5555/not-there', '') === null);

echo "\n--- Crossref's reports read on their own ---\n";
$rr = CcrWp_Crossref::readResult(200, '<?xml version="1.0"?><doi_batch_diagnostic status="in_process"/>');
ck('in_process: waiting', $rr['state'] === 'waiting');
$rr = CcrWp_Crossref::readResult(200, '<doi_batch_diagnostic status="completed"><record_diagnostic status="Warning"><doi>10.5555/a</doi><msg>Added with conflict</msg></record_diagnostic></doi_batch_diagnostic>');
ck('a warning: warning, with Crossref\'s words', $rr['state'] === 'warning' && strpos($rr['message'], 'Added with conflict') !== false);
$rr = CcrWp_Crossref::readResult(500, 'oops');
ck('no report: unknown, said so', $rr['state'] === 'unknown' && strpos($rr['message'], 'HTTP 500') !== false);

echo "\n" . ($P + $F) . " checks, $F failed" . ($F ? '' : ' — ALL OK') . "\n";
file_put_contents($site . '/.test-post-id', (string) $id);
exit($F ? 1 : 0);
