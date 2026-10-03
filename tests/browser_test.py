#!/usr/bin/env python3
"""The plugin's screens in Chromium, on the WordPress run_tests.sh sets up (after test_wp_plugin.php has run there).

    python3 tests/browser_test.py BASE_URL ADMIN_PASS_FILE SHOTS_FOLDER REGISTERED_POST_ID

Signs in as the scratch site's admin; the settings page shows what was saved and never the password; a new article is
written in WordPress's own block editor with its Crossref box filled in and published — the box's values are kept;
Crossref → Records lists it with what it lacks; its page shows the facts, the schema's verdict and the file; Deposit
asks first, then sends (to the stand-in); Crossref's result is asked twice (queued, then registered); the read-back says
what landed; the article page shows the DOI and the Crossmark button at Crossref's 150 pixels. A screenshot of each.
Crossref's own widget files cannot be reached from the test machine: a plain stand-in image is served at their address
so the button's size can be measured (the screenshots of the article page show the stand-in, not Crossref's logo).
"""
import re
import sys
from playwright.sync_api import sync_playwright

BASE, PASSFILE, SHOTS, REG_ID = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
PW = open(PASSFILE).read().strip()
P = F = 0


def ck(label, ok, detail=''):
    global P, F
    if ok:
        P += 1
        print('  ok   ' + label)
    else:
        F += 1
        print('  FAIL ' + label + ('\n       ' + str(detail)[:600] if detail != '' else ''))


STAND_IN_SVG = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 120"><rect width="600" height="120" fill="#eee"/>'
                '<text x="20" y="75" font-size="48" font-family="sans-serif">stand-in image</text></svg>')

with sync_playwright() as p:
    browser = p.chromium.launch()
    ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
    ctx.route('https://crossmark-cdn.crossref.org/**', lambda r: r.fulfill(
        status=200, content_type='image/svg+xml' if r.request.url.endswith('.svg') else 'application/javascript',
        body=STAND_IN_SVG if r.request.url.endswith('.svg') else '/* stand-in for Crossref\'s widget */'))
    page = ctx.new_page()
    dialogs = []
    page.on('dialog', lambda d: (dialogs.append(d.message), d.accept()))

    # sign in
    page.goto(BASE + '/wp-login.php')
    page.fill('#user_login', 'admin')
    page.fill('#user_pass', PW)
    page.click('#wp-submit')
    page.wait_for_url(re.compile(r'.*/wp-admin/.*'))
    ck('signed in to WordPress', '/wp-admin/' in page.url, page.url)

    # settings
    page.goto(BASE + '/wp-admin/admin.php?page=ccrwp-settings')
    ck('settings: the journal as saved', page.input_value('#ccrwp-journal_title') == 'Example Journal of Testing & Practice' and page.input_value('#ccrwp-prefix') == '10.5555')
    ck('settings: the password field is empty, the page says it is stored', page.input_value('#ccrwp-login_pass') == '' and 'Stored, encrypted' in page.inner_text('form'))
    page.screenshot(path=SHOTS + '/1-settings.png', full_page=True)
    page.fill('#ccrwp-abbrev_title', 'Ex. J. Test. Pract.')
    page.click('#submit')
    page.wait_for_load_state('networkidle')
    ck('settings saved through the form ("Settings saved.")', 'Settings saved.' in page.inner_text('.ccrwp-notice'))
    ck('…and the stored password still there', 'Stored, encrypted' in page.inner_text('form'))

    # a new article, in the block editor, with its Crossref box
    page.goto(BASE + '/wp-admin/post-new.php')
    # WordPress 7.1's editor: the article itself is drawn in an iframe (editor-canvas); a welcome guide opens over it the
    # first time; the boxes plugins add sit in a "Meta Boxes" panel under the article, closed at first
    canvas = page.frame_locator('iframe[name="editor-canvas"]')
    title = canvas.locator('.editor-post-title__input, [aria-label="Add title"]').first
    title.wait_for(timeout=90000)
    guide = page.locator('.components-modal__frame [aria-label="Close"]')
    if guide.count() and guide.first.is_visible():
        guide.first.click()
    title.click()
    title.type('A Second Example Article, Written in the Block Editor')
    if not page.locator('#ccrwp-record').is_visible():
        # the panel's resize handle lies over its title button: the button is pressed from the keyboard, as a person can
        mb = page.get_by_text('Meta Boxes', exact=True).first
        mb.focus()
        page.keyboard.press('Enter')
        page.wait_for_timeout(800)
    box = page.locator('#ccrwp-record')
    box.scroll_into_view_if_needed()
    ck('the "Crossref record" box is on the editor screen', box.count() == 1 and box.is_visible())
    page.fill('input[name="ccrwp[authors][0][given]"]', 'Josiah')
    page.fill('input[name="ccrwp[authors][0][family]"]', 'Carberry')
    page.fill('input[name="ccrwp[authors][0][orcid]"]', '0000-0002-1825-0097')
    page.fill('input[name="ccrwp[authors][0][affiliation]"]', 'Example University (test value)')
    page.click('.ccrwp-add')
    page.fill('input[name="ccrwp[authors][1][given]"]', 'Ana R.')
    page.fill('input[name="ccrwp[authors][1][family]"]', 'Example')
    ck('"Add an author" adds a numbered row', page.locator('input[name="ccrwp[authors][1][family]"]').count() == 1)
    page.fill('textarea[name="ccrwp[abstract]"]', 'An abstract typed in the editor.')
    page.fill('textarea[name="ccrwp[references]"]', 'Carberry J. A reference with a DOI. 2020. https://doi.org/10.5555/12345678\nA reference without one. 2019.')
    page.fill('input[name="ccrwp[first_page]"]', '1')
    page.fill('input[name="ccrwp[last_page]"]', '9')
    box.screenshot(path=SHOTS + '/2-editor-crossref-box.png')
    # publish (WordPress asks once more in its pre-publish panel)
    page.locator('.editor-header__settings .editor-post-publish-panel__toggle, .editor-header__settings .editor-post-publish-button__button').first.click()
    page.locator('.editor-post-publish-panel__header-publish-button button, .editor-post-publish-panel .editor-post-publish-button').first.click()
    page.wait_for_selector('.post-publish-panel__postpublish, .components-snackbar', timeout=90000)
    page.wait_for_timeout(2500)   # the editor saves the boxes below it in a second request after the post itself
    new_id = re.search(r'post=(\d+)', page.url)
    new_id = new_id.group(1) if new_id else ''
    ck('published from the block editor', new_id != '', page.url)

    # records
    page.goto(BASE + '/wp-admin/admin.php?page=ccrwp')
    rows = page.inner_text('.ccrwp-records')
    ck('Records: the new article, with what it lacks (one ORCID iD and one affiliation of two)', 'A Second Example Article' in rows and 'ORCID iDs for 1 of 2 authors' in rows, rows[:400])
    ck('Records: the article registered earlier shows as registered', 'Registered' in rows, rows[:400])
    page.screenshot(path=SHOTS + '/3-records.png', full_page=True)

    # the new article's own page: the check
    page.goto(BASE + '/wp-admin/admin.php?page=ccrwp&post=' + new_id)
    facts = page.inner_text('.ccrwp-facts')
    ck('its check: the box\'s values reached the record (2 authors, 1 ORCID iD, 2 references)', '2 author(s), 1 with an ORCID iD' in facts and 'Reference list: 2 reference(s)' in facts, facts)
    verdict = page.inner_text('p.ccrwp-state')
    ck('its check: valid against Crossref\'s schema 5.4.0', 'Valid against Crossref\'s schema 5.4.0' in verdict, verdict)
    ck('its check: the file shown', '<doi_batch' in page.inner_text('.ccrwp-xml'))
    page.screenshot(path=SHOTS + '/4-record-check.png', full_page=True)

    # deposit: asked first, then sent
    page.click('form[data-confirm] input[type=submit]')
    page.wait_for_load_state('networkidle')
    ck('Deposit asked first, naming the DOI', dialogs and dialogs[-1].startswith('Register the record for 10.5555/'), dialogs)
    note = page.inner_text('.ccrwp-notice')
    ck('…then sent: "Crossref received the file", with the batch', 'Crossref received the file' in note and 'ccrwp-' + new_id + '-' in note, note)
    page.screenshot(path=SHOTS + '/5-deposited.png', full_page=True)
    page.click('input[value="Ask Crossref for its result"]')
    page.wait_for_load_state('networkidle')
    ck('Crossref\'s result, asked at once: still in its queue', 'not processed this deposit yet' in page.inner_text('.ccrwp-notice') or 'still processing' in page.inner_text('.ccrwp-notice'), page.inner_text('.ccrwp-notice'))
    page.click('input[value="Ask Crossref for its result"]')
    page.wait_for_load_state('networkidle')
    note = page.inner_text('.ccrwp-notice')
    ck('…asked again: Successfully added', 'Successfully added' in note, note)
    ck('…and the article reads "Registered"', 'Registered' in page.inner_text('.wrap > p .ccrwp-state, .ccrwp-state'))
    page.screenshot(path=SHOTS + '/6-registered.png', full_page=True)
    page.click('input[value="Read the record back from Crossref"]')
    page.wait_for_load_state('networkidle')
    note = page.inner_text('.ccrwp-notice')
    ck('the read-back: what was sent landed', 'Read back from Crossref: reference list landed' in note and 'missing' not in note, note)
    page.screenshot(path=SHOTS + '/7-read-back.png', full_page=True)

    # the article as a reader sees it
    page.goto(BASE + '/?p=' + new_id)
    page.wait_for_load_state('networkidle')
    doi_line = page.locator('.ccrwp-doi')
    ck('the article page: the DOI as a link', doi_line.count() == 1 and 'https://doi.org/10.5555/' in doi_line.inner_text())
    img = page.locator('.ccrwp-crossmark img')
    w = img.evaluate('e => e.getBoundingClientRect().width') if img.count() else 0
    ck('the Crossmark button at Crossref\'s 150 pixels on the theme', img.count() == 1 and abs(w - 150) < 0.5, w)
    page.screenshot(path=SHOTS + '/8-article-page-stand-in-image.png', full_page=False)
    browser.close()

print('\n%d checks, %d failed%s' % (P + F, F, ' — ALL OK' if F == 0 else ''))
sys.exit(1 if F else 0)
