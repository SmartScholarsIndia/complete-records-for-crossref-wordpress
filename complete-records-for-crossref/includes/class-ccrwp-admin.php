<?php
/**
 * The admin side: Crossref → Records (every article, its DOI and what Crossref says), one article's check (the file
 * shown, what the core added, the schema's verdict, what the record still lacks) with Deposit / Crossref's result /
 * Read back, Crossref → Settings, and the "Crossref record" box on the article's edit screen.
 *
 * Every action that writes or sends is a POST with its own nonce and a capability check: editing an article's record
 * needs edit_post; depositing needs edit_others_posts (an editor); the settings need manage_options.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

defined('ABSPATH') || exit;

class CcrWp_Admin
{
    const CAP_DEPOSIT = 'edit_others_posts';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('add_meta_boxes', [__CLASS__, 'metaBoxes']);
        add_action('save_post', [__CLASS__, 'savePost'], 10, 2);
        add_action('admin_post_ccrwp_settings', [__CLASS__, 'handleSettings']);
        add_action('admin_post_ccrwp_deposit', [__CLASS__, 'handleDeposit']);
        add_action('admin_post_ccrwp_result', [__CLASS__, 'handleResult']);
        add_action('admin_post_ccrwp_readback', [__CLASS__, 'handleReadBack']);
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu()
    {
        add_menu_page('Crossref', 'Crossref', self::CAP_DEPOSIT, 'ccrwp', [__CLASS__, 'recordsPage'], 'dashicons-admin-links', 81);
        add_submenu_page('ccrwp', 'Crossref records', 'Records', self::CAP_DEPOSIT, 'ccrwp', [__CLASS__, 'recordsPage']);
        add_submenu_page('ccrwp', 'Crossref settings', 'Settings', 'manage_options', 'ccrwp-settings', [__CLASS__, 'settingsPage']);
    }

    public static function assets($hook)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $types = (array) CcrWp_Settings::get('post_types');
        if (strpos((string) $hook, 'ccrwp') === false && !($screen && in_array($screen->post_type, $types, true) && $screen->base === 'post')) return;
        wp_enqueue_style('ccrwp-admin', plugins_url('assets/admin.css', CCRWP_FILE), [], CCRWP_VERSION);
        wp_enqueue_script('ccrwp-admin', plugins_url('assets/admin.js', CCRWP_FILE), [], CCRWP_VERSION, true);
    }

    // ------------------------------------------------------------------------------------------------------- notices

    private static function notice($type, $text)
    {
        set_transient('ccrwp_notice_' . get_current_user_id(), ['type' => $type, 'text' => $text], 120);
    }

    public static function notices()
    {
        $n = get_transient('ccrwp_notice_' . get_current_user_id());
        if (!is_array($n)) return;
        delete_transient('ccrwp_notice_' . get_current_user_id());
        $cls = ['ok' => 'notice-success', 'warn' => 'notice-warning', 'error' => 'notice-error'][$n['type']] ?? 'notice-info';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible ccrwp-notice"><p>' . wp_kses_post($n['text']) . '</p></div>';
    }

    // ------------------------------------------------------------------------------------------------------ settings

    public static function settingsPage()
    {
        if (!current_user_can('manage_options')) return;
        $s = CcrWp_Settings::all();
        $pw = CcrWp_Settings::passwordState();
        $field = function ($key, $label, $help = '', $type = 'text') use ($s) {
            echo '<tr><th scope="row"><label for="ccrwp-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
               . '<input class="regular-text" type="' . esc_attr($type) . '" id="ccrwp-' . esc_attr($key) . '" name="ccrwp[' . esc_attr($key) . ']" value="' . esc_attr((string) $s[$key]) . '">'
               . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
        };
        echo '<div class="wrap ccrwp"><h1>Crossref settings</h1>';
        $miss = CcrWp_Settings::missing();
        if ($miss) echo '<p class="ccrwp-missing">Before the first deposit: ' . esc_html(implode(', ', $miss)) . '.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ccrwp_settings');
        echo '<input type="hidden" name="action" value="ccrwp_settings">';
        echo '<h2>The journal</h2><table class="form-table" role="presentation">';
        $field('journal_title', 'Journal title', 'As registered with Crossref.');
        $field('abbrev_title', 'Abbreviated title', 'Optional.');
        $field('issn_e', 'Online ISSN', 'At least one ISSN is needed.');
        $field('issn_p', 'Print ISSN');
        $field('publisher', 'Publisher', 'The registrant: the organisation that owns the DOI prefix.');
        $field('language', 'Language', 'Two letters, e.g. en.');
        $field('licence', 'Licence', 'The address of the licence your articles carry, e.g. https://creativecommons.org/licenses/by/4.0/ — an article can name its own.', 'url');
        $field('crossmark_policy', 'Crossmark policy DOI', 'Only when your Crossmark policy page has its own DOI registered at Crossref. Every deposit then carries Crossmark, and each registered article shows Crossref\'s Crossmark button.');
        echo '</table><h2>DOIs and deposits</h2><table class="form-table" role="presentation">';
        $field('prefix', 'DOI prefix', 'Your prefix from Crossref, e.g. 10.5555.');
        $field('suffix_pattern', 'DOI suffix pattern', '{year}, {month}, {id} (the post\'s number) and {slug}; {id} is required. An article can set its own suffix.');
        $field('depositor_name', 'Depositor name');
        $field('depositor_email', 'Depositor e-mail', 'Crossref writes to this address about each deposit.', 'email');
        $field('login_user', 'Crossref username', 'Your Crossref login (a sponsored member\'s is often your e-mail followed by /ROLE).');
        echo '<tr><th scope="row"><label for="ccrwp-login_pass">Crossref password</label></th><td>'
           . '<input class="regular-text" type="password" id="ccrwp-login_pass" name="ccrwp[login_pass]" value="" autocomplete="new-password">'
           . '<p class="description">' . esc_html($pw === 'stored' ? 'Stored, encrypted with this site\'s own keys. Leave empty to keep it.'
               : ($pw === 'unreadable' ? 'A password is stored but cannot be read with this site\'s keys (they have changed). Type it once more.' : 'Not stored yet.')) . '</p></td></tr>';
        echo '<tr><th scope="row">Crossref system</th><td><fieldset>'
           . '<label><input type="radio" name="ccrwp[server]" value="test"' . checked($s['server'], 'test', false) . '> Test system (test.crossref.org) — nothing is registered</label><br>'
           . '<label><input type="radio" name="ccrwp[server]" value="live"' . checked($s['server'], 'live', false) . '> Live (doi.crossref.org) — DOIs are registered</label></fieldset></td></tr>';
        echo '<tr><th scope="row">Content types</th><td><fieldset>';
        foreach (get_post_types(['public' => true], 'objects') as $pt) {
            if ($pt->name === 'attachment') continue;
            echo '<label><input type="checkbox" name="ccrwp[post_types][]" value="' . esc_attr($pt->name) . '"' . checked(in_array($pt->name, (array) $s['post_types'], true), true, false) . '> ' . esc_html($pt->labels->name) . '</label><br>';
        }
        echo '</fieldset></td></tr></table>';
        submit_button('Save settings');
        echo '</form></div>';
    }

    public static function handleSettings()
    {
        if (!current_user_can('manage_options')) wp_die('You may not change these settings.', 403);
        check_admin_referer('ccrwp_settings');
        $problems = CcrWp_Settings::save(isset($_POST['ccrwp']) && is_array($_POST['ccrwp']) ? $_POST['ccrwp'] : []);
        self::notice($problems ? 'warn' : 'ok', $problems ? 'Saved, except: ' . esc_html(implode(' ', $problems)) : 'Settings saved.');
        wp_safe_redirect(admin_url('admin.php?page=ccrwp-settings'));
        exit;
    }

    // ---------------------------------------------------------------------------------------------- the article's box

    public static function metaBoxes()
    {
        foreach ((array) CcrWp_Settings::get('post_types') as $t) {
            add_meta_box('ccrwp-record', 'Crossref record', [__CLASS__, 'metaBox'], $t, 'normal', 'default');
        }
    }

    public static function metaBox(WP_Post $post)
    {
        $r = CcrWp_Record::stored($post->ID);
        wp_nonce_field('ccrwp_record_' . $post->ID, 'ccrwp_record_nonce');
        $doi = CcrWp_Record::doi($post);
        echo '<div class="ccrwp-box">';
        echo '<p class="ccrwp-doi-line"><strong>DOI:</strong> ' . ($doi !== '' ? '<code>' . esc_html($doi) . '</code>' : '<em>set the DOI prefix in Crossref → Settings</em>') . '</p>';
        echo '<p><label>Own DOI suffix (optional): <input type="text" name="ccrwp[suffix]" value="' . esc_attr($r['suffix']) . '" placeholder="' . esc_attr(CcrWp_Record::suffixFromPattern($post)) . '"></label></p>';
        echo '<h4>Authors, in the order printed</h4><table class="widefat ccrwp-authors"><thead><tr><th>Given name(s)</th><th>Family name</th><th>ORCID iD</th><th>Affiliation</th><th>ROR ID</th><th></th></tr></thead><tbody>';
        $rows = $r['authors'] ?: [['given' => '', 'family' => '', 'orcid' => '', 'affiliation' => '', 'ror' => '']];
        foreach (array_values($rows) as $i => $a) {
            echo '<tr>';
            foreach (['given', 'family', 'orcid', 'affiliation', 'ror'] as $k) {
                echo '<td><input type="text" name="ccrwp[authors][' . (int) $i . '][' . $k . ']" value="' . esc_attr($a[$k]) . '"></td>';
            }
            echo '<td><button type="button" class="button-link ccrwp-remove" aria-label="Remove this author">Remove</button></td></tr>';
        }
        echo '</tbody></table><p><button type="button" class="button ccrwp-add">Add an author</button></p>';
        echo '<p><label>Abstract (empty: the post\'s excerpt)<br><textarea name="ccrwp[abstract]" rows="5" class="large-text">' . esc_textarea($r['abstract']) . '</textarea></label></p>';
        echo '<p class="ccrwp-row">';
        foreach (['volume' => 'Volume', 'issue' => 'Issue', 'first_page' => 'First page', 'last_page' => 'Last page'] as $k => $label) {
            echo '<label>' . esc_html($label) . ' <input type="text" size="6" name="ccrwp[' . $k . ']" value="' . esc_attr($r[$k]) . '"></label> ';
        }
        echo '</p>';
        echo '<p><label>References, one per line<br><textarea name="ccrwp[references]" rows="8" class="large-text code">' . esc_textarea($r['references']) . '</textarea></label></p>';
        echo '<p><label>Licence (empty: the journal\'s) <input type="url" class="regular-text" name="ccrwp[licence]" value="' . esc_attr($r['licence']) . '"></label></p>';
        echo '<p><label>Full-text PDF address (optional, for Similarity Check and text mining) <input type="url" class="regular-text" name="ccrwp[pdf]" value="' . esc_attr($r['pdf']) . '"></label></p>';
        if (current_user_can(self::CAP_DEPOSIT)) {
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=ccrwp&post=' . $post->ID)) . '">Check and deposit with Crossref</a></p>';
        }
        echo '</div>';
    }

    public static function savePost($postId, $post)
    {
        if (!isset($_POST['ccrwp_record_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ccrwp_record_nonce'])), 'ccrwp_record_' . $postId)) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) return;
        if (!in_array($post->post_type, (array) CcrWp_Settings::get('post_types'), true)) return;
        $problems = CcrWp_Record::save($postId, isset($_POST['ccrwp']) && is_array($_POST['ccrwp']) ? $_POST['ccrwp'] : []);
        if ($problems) self::notice('warn', 'Crossref record: ' . esc_html(implode(' ', $problems)));
    }

    // ---------------------------------------------------------------------------------------------------- records

    private static function status(WP_Post $post)
    {
        $deps = CcrWp_Record::deposits($post->ID);
        if (!$deps) return ['none', 'Not deposited'];
        $d = $deps[0];
        $where = $d['server'] === 'live' ? '' : ' (test system)';
        $res = isset($d['result']) ? $d['result'] : 'sent';
        if ($res === 'success') return ['ok', 'Registered' . $where . ' ' . esc_html(wp_date('j M Y', (int) $d['at']))];
        if ($res === 'warning') return ['warn', 'Registered with warnings' . $where];
        if ($res === 'failure') return ['bad', 'Crossref refused it' . $where];
        return ['wait', 'Sent' . $where . ' — Crossref\'s result not read yet'];
    }

    public static function recordsPage()
    {
        if (!current_user_can(self::CAP_DEPOSIT)) return;
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($id) { self::recordPage($id); return; }
        $types = (array) CcrWp_Settings::get('post_types');
        $paged = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $q = new WP_Query(['post_type' => $types, 'post_status' => ['publish', 'future', 'draft', 'pending'], 'posts_per_page' => 20,
                           'paged' => $paged, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => false]);
        echo '<div class="wrap ccrwp"><h1>Crossref records</h1>';
        $miss = CcrWp_Settings::missing();
        if ($miss) {
            echo '<p class="ccrwp-missing">Before the first deposit, Crossref → Settings needs: ' . esc_html(implode(', ', $miss)) . '.</p>';
        }
        echo '<p>Each article is deposited from its own page here: the file is shown and checked against Crossref\'s schema first. '
           . 'Deposits go to <strong>' . (CcrWp_Settings::get('server') === 'live' ? 'Crossref (live)' : 'Crossref\'s test system') . '</strong>.</p>';
        echo '<table class="widefat striped ccrwp-records"><thead><tr><th>Article</th><th>DOI</th><th>Crossref</th><th>What the record carries</th></tr></thead><tbody>';
        if (!$q->posts) echo '<tr><td colspan="4">No articles yet.</td></tr>';
        foreach ($q->posts as $p) {
            list($cls, $label) = self::status($p);
            $d = CcrWp_Record::data($p);
            $lacks = CcrWp_Record::lacks($d);
            echo '<tr><td><a href="' . esc_url(admin_url('admin.php?page=ccrwp&post=' . $p->ID)) . '"><strong>' . esc_html($d['title'] ?: '(no title)') . '</strong></a>'
               . ($p->post_status !== 'publish' ? ' <span class="ccrwp-tag">' . esc_html($p->post_status) . '</span>' : '') . '</td>'
               . '<td><code>' . esc_html($d['doi']) . '</code></td>'
               . '<td><span class="ccrwp-state ccrwp-' . esc_attr($cls) . '">' . esc_html($label) . '</span></td>'
               . '<td>' . ($lacks ? 'Lacks: ' . esc_html(implode('; ', $lacks)) : 'Complete') . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($q->max_num_pages > 1) {
            echo '<p class="ccrwp-pages">' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages]) . '</p>';
        }
        echo '</div>';
    }

    private static function recordPage($id)
    {
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, (array) CcrWp_Settings::get('post_types'), true)) {
            echo '<div class="wrap"><p>That article is not one this journal registers with Crossref.</p></div>';
            return;
        }
        list($doc, $report, $d) = CcrWp_Record::build($post);
        $check = CcrWp_Crossref::validate($doc);
        $xml = $doc->saveXML();
        $sent = $report['sent'];
        $lacks = CcrWp_Record::lacks($d);
        list($cls, $label) = self::status($post);
        echo '<div class="wrap ccrwp"><p><a href="' . esc_url(admin_url('admin.php?page=ccrwp')) . '">← All records</a></p>';
        echo '<h1>' . esc_html($d['title'] ?: '(no title)') . '</h1>';
        echo '<p><strong>DOI</strong> <code>' . esc_html($d['doi']) . '</code> → <a href="' . esc_url($d['url']) . '">' . esc_html($d['url']) . '</a></p>';
        echo '<p><span class="ccrwp-state ccrwp-' . esc_attr($cls) . '">' . esc_html($label) . '</span> · <a href="' . esc_url(get_edit_post_link($post->ID)) . '">Edit the article and its Crossref record</a></p>';

        echo '<h2>This deposit</h2><ul class="ccrwp-facts">';
        echo '<li>' . (int) $sent['authors'] . ' author(s), ' . (int) $sent['orcid'] . ' with an ORCID iD, ' . (int) $sent['affiliations'] . ' affiliation(s), ' . (int) $sent['ror'] . ' with a ROR ID</li>';
        echo '<li>Abstract: ' . ($sent['abstract'] ? 'yes' : 'no') . ' · licence: ' . ($sent['license'] ? 'yes' : 'no') . ' · full-text links: ' . (int) $sent['links'] . '</li>';
        echo '<li>Reference list: ' . (int) $sent['references'] . ' reference(s)</li>';
        echo '<li>Crossmark: ' . ($sent['crossmark'] !== '' ? 'yes (policy ' . esc_html($sent['crossmark']) . ')' : 'no') . '</li>';
        if ($lacks) echo '<li class="ccrwp-lacks">Still lacking: ' . esc_html(implode('; ', $lacks)) . '</li>';
        echo '</ul>';
        foreach ($report['problems'] as $p) echo '<p class="ccrwp-bad">' . esc_html($p) . '</p>';

        $verdict = $check['state'] === 'valid' ? ['ok', 'Valid against Crossref\'s schema ' . $check['version'] . ' — ready to deposit.']
                 : ($check['state'] === 'invalid' ? ['bad', 'Crossref\'s schema ' . $check['version'] . ' refuses this file: ' . implode(' · ', array_slice($check['errors'], 0, 5))]
                 : ['warn', 'Not checked: ' . $check['why']]);
        echo '<h2>Crossref\'s schema</h2><p class="ccrwp-state ccrwp-' . esc_attr($verdict[0]) . '">' . esc_html($verdict[1]) . '</p>';

        $miss = CcrWp_Settings::missing();
        $can = !$miss && $check['state'] === 'valid' && $post->post_status === 'publish';
        echo '<h2>Deposit</h2>';
        if ($miss) echo '<p>Crossref → Settings still needs: ' . esc_html(implode(', ', $miss)) . '.</p>';
        if ($post->post_status !== 'publish') echo '<p>Publish the article first: a DOI must lead to a page readers can open.</p>';
        $server = CcrWp_Settings::get('server');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ccrwp-inline" data-confirm="'
           . esc_attr(($server === 'live' ? 'Register' : 'Send to Crossref\'s TEST system') . ' the record for ' . $d['doi'] . '?') . '">';
        wp_nonce_field('ccrwp_deposit_' . $post->ID);
        echo '<input type="hidden" name="action" value="ccrwp_deposit"><input type="hidden" name="post" value="' . (int) $post->ID . '">';
        $again = (bool) CcrWp_Record::registered($post->ID);
        $label = $server === 'live' ? ($again ? 'Deposit again (updates the record)' : 'Deposit with Crossref') : 'Deposit on Crossref\'s test system';
        submit_button($label, 'primary', 'submit', false, $can ? [] : ['disabled' => 'disabled']);
        echo '</form>';
        $deps = CcrWp_Record::deposits($post->ID);
        if ($deps) {
            foreach ([['ccrwp_result', 'Ask Crossref for its result'], ['ccrwp_readback', 'Read the record back from Crossref']] as $b) {
                if ($b[0] === 'ccrwp_readback' && $deps[0]['server'] !== 'live') continue;
                echo ' <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ccrwp-inline">';
                wp_nonce_field($b[0] . '_' . $post->ID);
                echo '<input type="hidden" name="action" value="' . esc_attr($b[0]) . '"><input type="hidden" name="post" value="' . (int) $post->ID . '">';
                submit_button($b[1], 'secondary', 'submit', false);
                echo '</form>';
            }
            echo '<h2>Deposits</h2><table class="widefat striped ccrwp-deposits"><thead><tr><th>Sent</th><th>System</th><th>Batch</th><th>Crossref\'s result</th><th>Read back</th></tr></thead><tbody>';
            foreach ($deps as $dep) {
                $rb = isset($dep['readback']) && is_array($dep['readback']) ? CcrReadBack::sentence($dep['readback']) : '';
                echo '<tr><td>' . esc_html(wp_date('j M Y H:i', (int) $dep['at'])) . '</td><td>' . esc_html($dep['server'] === 'live' ? 'live' : 'test') . '</td>'
                   . '<td><code>' . esc_html($dep['batch']) . '</code></td>'
                   . '<td>' . esc_html((isset($dep['result']) ? $dep['result'] : 'sent') . (isset($dep['message']) && $dep['message'] !== '' ? ' — ' . $dep['message'] : '')) . '</td>'
                   . '<td>' . esc_html($rb) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>The file</h2><p>Exactly what is sent (version number and batch made afresh at each deposit).</p>';
        echo '<pre class="ccrwp-xml">' . esc_html($xml) . '</pre></div>';
    }

    private static function postFromRequest($action)
    {
        $id = isset($_POST['post']) ? absint($_POST['post']) : 0;
        if (!current_user_can(self::CAP_DEPOSIT) || !$id || !current_user_can('edit_post', $id)) wp_die('You may not do this.', 403);
        check_admin_referer($action . '_' . $id);
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, (array) CcrWp_Settings::get('post_types'), true)) wp_die('Not an article this journal registers.', 404);
        return $post;
    }

    private static function back($id)
    {
        wp_safe_redirect(admin_url('admin.php?page=ccrwp&post=' . (int) $id));
        exit;
    }

    public static function handleDeposit()
    {
        $post = self::postFromRequest('ccrwp_deposit');
        $miss = CcrWp_Settings::missing();
        if ($miss) { self::notice('error', 'Nothing was sent: Crossref → Settings still needs ' . esc_html(implode(', ', $miss)) . '.'); self::back($post->ID); }
        if ($post->post_status !== 'publish') { self::notice('error', 'Nothing was sent: the article is not published.'); self::back($post->ID); }
        list($doc, $report, $d, $stamp, $batch) = CcrWp_Record::build($post);
        if ($report['problems']) { self::notice('error', 'Nothing was sent: ' . esc_html(implode(' ', $report['problems']))); self::back($post->ID); }
        $check = CcrWp_Crossref::validate($doc);
        if ($check['state'] !== 'valid') {
            self::notice('error', 'Nothing was sent: ' . esc_html($check['state'] === 'invalid' ? 'Crossref\'s schema refuses the file — ' . implode(' · ', array_slice($check['errors'], 0, 3)) : 'the file could not be checked against Crossref\'s schema — ' . $check['why']));
            self::back($post->ID);
        }
        $s = CcrWp_Settings::all();
        $r = CcrWp_Crossref::deposit($doc->saveXML(), $batch . '.xml', $s['login_user'], (string) CcrWp_Settings::decrypt($s['login_pass']), $s['server']);
        if (!$r['ok']) { self::notice('error', esc_html($r['message'])); self::back($post->ID); }
        CcrWp_Record::addDeposit($post->ID, ['at' => time(), 'batch' => $batch, 'stamp' => $stamp, 'server' => $s['server'], 'doi' => $d['doi'],
                                            'sent' => $report['sent'], 'result' => 'sent', 'message' => '']);
        self::notice('ok', esc_html($r['message']) . ' Batch <code>' . esc_html($batch) . '</code>.');
        self::back($post->ID);
    }

    public static function handleResult()
    {
        $post = self::postFromRequest('ccrwp_result');
        $deps = CcrWp_Record::deposits($post->ID);
        if (!$deps) self::back($post->ID);
        $s = CcrWp_Settings::all();
        $r = CcrWp_Crossref::result($deps[0]['batch'], $s['login_user'], (string) CcrWp_Settings::decrypt($s['login_pass']), $deps[0]['server']);
        if (in_array($r['state'], ['success', 'warning', 'failure'], true)) {
            CcrWp_Record::updateLatestDeposit($post->ID, ['result' => $r['state'], 'message' => $r['message'], 'result_at' => time()]);
        }
        if ($r['min_stamp'] !== '') update_post_meta($post->ID, '_ccrwp_min_stamp', $r['min_stamp']);
        $type = ['success' => 'ok', 'warning' => 'warn', 'failure' => 'error'][$r['state']] ?? 'warn';
        self::notice($type, 'Crossref: ' . esc_html($r['message']));
        self::back($post->ID);
    }

    public static function handleReadBack()
    {
        $post = self::postFromRequest('ccrwp_readback');
        $deps = CcrWp_Record::deposits($post->ID);
        if (!$deps || $deps[0]['server'] !== 'live') self::back($post->ID);
        $m = CcrWp_Crossref::work($deps[0]['doi'], (string) CcrWp_Settings::get('depositor_email'));
        if ($m === null) {
            self::notice('warn', 'Crossref\'s public record for ' . esc_html($deps[0]['doi']) . ' is not there yet. Crossref shows a deposit some minutes after processing it.');
            self::back($post->ID);
        }
        $c = CcrReadBack::compare(is_array($deps[0]['sent']) ? $deps[0]['sent'] : [], $m, (int) $deps[0]['at']);
        CcrWp_Record::updateLatestDeposit($post->ID, ['readback' => $c, 'readback_at' => time()]);
        self::notice(in_array('missing', $c, true) || in_array('partly', $c, true) ? 'warn' : 'ok', 'Read back from Crossref: ' . esc_html(CcrReadBack::sentence($c) ?: 'nothing to compare'));
        self::back($post->ID);
    }
}
