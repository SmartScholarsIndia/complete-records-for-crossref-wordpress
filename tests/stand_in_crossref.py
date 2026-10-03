#!/usr/bin/env python3
"""A stand-in for the parts of Crossref the plugin talks to, for the tests only.

    python3 tests/stand_in_crossref.py PORT LOGIN_USER PASSWORD_FILE LOG_FILE

  POST /{test|live}/servlet/deposit             operation=doMDUpload, login_id, login_passwd, fname (multipart)
  POST /{test|live}/servlet/submissionDownload  usr, pwd, doi_batch_id, file_name, type=result
  GET  /api/works/{doi}                         the public record, as Crossref's REST API shapes it (live deposits only)

It keeps Crossref's rules that matter to the plugin: a wrong login is HTTP 401; a deposit is first "queued" and then
"completed"; a record is updated only by a deposit whose <timestamp> is greater than the last one ("less or equal to
previously submitted version"); test-system records never reach the REST API. The record it serves is made from the XML
that was actually deposited, so a read-back compares the plugin's file with what came out of it.
The log names each request and whether its login was right — never the password.
"""
import email.parser
import email.policy
import json
import sys
import time
import urllib.parse
import xml.etree.ElementTree as ET
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT, USER, PASS, LOG = int(sys.argv[1]), sys.argv[2], open(sys.argv[3]).read().strip(), sys.argv[4]
CR = '{http://www.crossref.org/schema/5.4.0}'
JATS = '{http://www.ncbi.nlm.nih.gov/JATS1}'
AI = '{http://www.crossref.org/AccessIndicators.xsd}'
batches = {}     # (server, batch id) -> {'dois': [...], 'asked': n, 'diag': [...]}
versions = {}    # (server, doi) -> last accepted timestamp
records = {}     # doi -> (accepted xml article element, received time) — live only


def log(line):
    with open(LOG, 'a') as f:
        f.write(line + '\n')


def text(el, path):
    x = el.find(path)
    return (x.text or '').strip() if x is not None and x.text else ''


def work(doi):
    art, at = records[doi]
    m = {'DOI': doi, 'type': 'journal-article', 'title': [text(art, CR + 'titles/' + CR + 'title')],
         'deposited': {'date-time': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(at))}, 'author': []}
    for p in art.findall(CR + 'contributors/' + CR + 'person_name'):
        a = {'given': text(p, CR + 'given_name'), 'family': text(p, CR + 'surname'), 'sequence': p.get('sequence'), 'affiliation': []}
        if text(p, CR + 'ORCID'):
            a['ORCID'] = text(p, CR + 'ORCID'); a['authenticated-orcid'] = False
        for inst in p.findall(CR + 'affiliations/' + CR + 'institution'):
            af = {'name': text(inst, CR + 'institution_name')}
            ids = [{'id': i.text.strip(), 'id-type': 'ROR', 'asserted-by': 'publisher'} for i in inst.findall(CR + 'institution_id') if i.get('type') == 'ror']
            if ids:
                af['id'] = ids
            a['affiliation'].append(af)
        m['author'].append(a)
    ab = art.find(JATS + 'abstract')
    if ab is not None:
        m['abstract'] = ''.join('<jats:p>' + (p.text or '') + '</jats:p>' for p in ab.findall(JATS + 'p'))
    lic = art.findall('.//' + AI + 'license_ref')
    if lic:
        m['license'] = [{'URL': l.text.strip(), 'content-version': l.get('applies_to', 'unspecified')} for l in lic]
    links = []
    for col in art.findall(CR + 'doi_data/' + CR + 'collection'):
        for item in col.findall(CR + 'item'):
            links.append({'URL': text(item, CR + 'resource'), 'intended-application': col.get('property')})
    if links:
        m['link'] = links
    refs = art.findall(CR + 'citation_list/' + CR + 'citation')
    m['reference-count'] = len(refs)
    m['reference'] = [dict({'key': r.get('key'), 'unstructured': text(r, CR + 'unstructured_citation')}, **({'DOI': text(r, CR + 'doi')} if text(r, CR + 'doi') else {})) for r in refs]
    pol = text(art, CR + 'crossmark/' + CR + 'crossmark_policy')
    if pol:
        m['update-policy'] = 'https://doi.org/' + pol
    m['resource'] = {'primary': {'URL': text(art, CR + 'doi_data/' + CR + 'resource')}}
    return m


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def reply(self, code, body, ctype='text/html'):
        b = body.encode() if isinstance(body, str) else body
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def do_GET(self):
        if self.path.startswith('/api/works/'):
            doi = urllib.parse.unquote(self.path[len('/api/works/'):].split('?')[0]).lower()
            log('GET works %s mailto=%s' % (doi, 'mailto=' in self.path))
            if doi in records:
                return self.reply(200, json.dumps({'status': 'ok', 'message-type': 'work', 'message': work(doi)}), 'application/json')
            return self.reply(404, 'Resource not found.', 'text/plain')
        self.reply(404, 'not here')

    def do_POST(self):
        n = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(n)
        parts = self.path.strip('/').split('/')
        server = parts[0] if parts else ''
        if len(parts) != 3 or server not in ('test', 'live') or parts[1] != 'servlet':
            return self.reply(404, 'not here')
        if parts[2] == 'deposit':
            ctype = self.headers.get('Content-Type', '')
            msg = email.parser.BytesParser(policy=email.policy.HTTP).parsebytes(b'Content-Type: ' + ctype.encode() + b'\r\n\r\n' + body)
            f, fname = {}, ''
            for part in msg.iter_parts():
                name = part.get_param('name', header='content-disposition')
                f[name] = part.get_payload(decode=True)
                if name == 'fname':
                    fname = part.get_filename() or ''
            ok = f.get('login_id', b'').decode() == USER and f.get('login_passwd', b'').decode() == PASS
            log('POST %s deposit operation=%s login_ok=%s file=%s' % (server, f.get('operation', b'').decode(), ok, fname))
            if not ok:
                return self.reply(401, '<html><body><h2>FAILURE</h2><p>Authentication failed</p></body></html>')
            if f.get('operation') != b'doMDUpload' or 'fname' not in f:
                return self.reply(200, '<html><body><h2>FAILURE</h2><p>No file</p></body></html>')
            root = ET.fromstring(f['fname'])
            bid = text(root, CR + 'head/' + CR + 'doi_batch_id')
            stamp = int(text(root, CR + 'head/' + CR + 'timestamp') or 0)
            diag = []
            for art in root.iter(CR + 'journal_article'):
                doi = text(art, CR + 'doi_data/' + CR + 'doi').lower()
                last = versions.get((server, doi))
                if last is not None and stamp <= last:
                    diag.append((doi, 'Failure', 'Record not processed because submitted version: %d is less or equal to previously submitted version %d' % (stamp, last)))
                    continue
                diag.append((doi, 'Success', 'Successfully updated' if last is not None else 'Successfully added'))
                versions[(server, doi)] = stamp
                if server == 'live':
                    records[doi] = (art, time.time())
            batches[(server, bid)] = {'asked': 0, 'diag': diag}
            open(LOG + '.last.xml', 'wb').write(f['fname'])
            return self.reply(200, '<html><head><title>SUCCESS</title></head><body><h2>SUCCESS</h2><p>Your batch submission was successfully received.</p></body></html>')
        if parts[2] == 'submissionDownload':
            q = urllib.parse.parse_qs(body.decode())
            g = lambda k: (q.get(k) or [''])[0]
            ok = g('usr') == USER and g('pwd') == PASS
            log('POST %s submissionDownload batch=%s file=%s type=%s login_ok=%s' % (server, g('doi_batch_id'), g('file_name'), g('type'), ok))
            if not ok:
                return self.reply(401, 'Unauthorized')
            b = batches.get((server, g('doi_batch_id')))
            if b is None:
                return self.reply(200, '<?xml version="1.0" encoding="UTF-8"?><doi_batch_diagnostic status="unknown_submission"/>', 'text/xml')
            b['asked'] += 1
            if b['asked'] == 1:
                return self.reply(200, '<?xml version="1.0" encoding="UTF-8"?><doi_batch_diagnostic status="queued" sp="standin"/>', 'text/xml')
            rows = ''.join('<record_diagnostic status="%s"><doi>%s</doi><msg>%s</msg></record_diagnostic>' % (d[1], d[0], d[2]) for d in b['diag'])
            ok_n = sum(1 for d in b['diag'] if d[1] == 'Success')
            return self.reply(200, '<?xml version="1.0" encoding="UTF-8"?><doi_batch_diagnostic status="completed" sp="standin">'
                              '<submission_id>1</submission_id><batch_id>%s</batch_id>%s<batch_data><record_count>%d</record_count>'
                              '<success_count>%d</success_count><warning_count>0</warning_count><failure_count>%d</failure_count></batch_data>'
                              '</doi_batch_diagnostic>' % (g('doi_batch_id'), rows, len(b['diag']), ok_n, len(b['diag']) - ok_n), 'text/xml')
        self.reply(404, 'not here')


HTTPServer(('127.0.0.1', PORT), H).serve_forever()
