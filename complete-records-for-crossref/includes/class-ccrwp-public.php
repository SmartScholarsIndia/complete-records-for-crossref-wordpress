<?php
/**
 * What readers (and indexers) see on a registered article: the DOI as a link, the citation tags Google Scholar and
 * others read, the tag Crossref's Crossmark widget reads, and — when the record went to Crossref with Crossmark —
 * Crossref's Crossmark button at the top of the article.
 *
 * A DOI is shown only once Crossref has registered it on the live system (its result said so): a DOI that does not yet
 * resolve is never printed.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

defined('ABSPATH') || exit;

class CcrWp_Public
{
    public static function init()
    {
        add_action('wp_head', [__CLASS__, 'head'], 5);
        add_filter('the_content', [__CLASS__, 'content'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'scripts']);
    }

    /** The article's live, registered deposit (or null) — for the post being shown. */
    private static function registered($post)
    {
        if (!$post instanceof WP_Post || !in_array($post->post_type, (array) CcrWp_Settings::get('post_types'), true)) return null;
        $dep = CcrWp_Record::registered($post->ID);
        return ($dep && $dep['server'] === 'live') ? $dep : null;
    }

    public static function head()
    {
        if (!is_singular()) return;
        $post = get_queried_object();
        if (!$post instanceof WP_Post || !in_array($post->post_type, (array) CcrWp_Settings::get('post_types'), true)) return;
        $rec = CcrWp_Record::stored($post->ID);
        if (!$rec['authors']) return;   // nothing entered for Crossref: the theme's own tags stand
        $d = CcrWp_Record::data($post);
        $s = CcrWp_Settings::all();
        $tags = [['citation_title', $d['title']]];
        foreach ($d['authors'] as $a) $tags[] = ['citation_author', trim($a['family'] . ($a['given'] !== '' ? ', ' . $a['given'] : ''))];
        $tags[] = ['citation_publication_date', sprintf('%04d/%02d/%02d', $d['year'], $d['month'], $d['day'])];
        if ($s['journal_title'] !== '') $tags[] = ['citation_journal_title', $s['journal_title']];
        if ($s['publisher'] !== '') $tags[] = ['citation_publisher', $s['publisher']];
        foreach (['issn_e', 'issn_p'] as $k) if ($s[$k] !== '') $tags[] = ['citation_issn', $s[$k]];
        foreach (['volume' => 'citation_volume', 'issue' => 'citation_issue', 'first_page' => 'citation_firstpage', 'last_page' => 'citation_lastpage'] as $k => $n) {
            if ($d[$k] !== '') $tags[] = [$n, $d[$k]];
        }
        if ($d['pdf'] !== '') $tags[] = ['citation_pdf_url', $d['pdf']];
        $dep = self::registered($post);
        if ($dep) $tags[] = ['citation_doi', $dep['doi']];
        foreach ($tags as $t) echo '<meta name="' . esc_attr($t[0]) . '" content="' . esc_attr($t[1]) . '" />' . "\n";
        if ($dep) echo CcrCrossmark::metaTag($dep['doi']) . "\n";
    }

    public static function scripts()
    {
        if (!is_singular()) return;
        $dep = self::registered(get_queried_object());
        if ($dep && !empty($dep['sent']['crossmark'])) {
            wp_enqueue_script('crossref-crossmark', CcrCrossmark::SCRIPT, [], null, true);
        }
    }

    public static function content($html)
    {
        if (!is_singular() || !in_the_loop() || !is_main_query()) return $html;
        $post = get_post();
        $dep = self::registered($post);
        if (!$dep) return $html;
        $url = 'https://doi.org/' . $dep['doi'];
        $top = '';
        if (!empty($dep['sent']['crossmark'])) {
            // Crossref's button (widget v2.0), held at Crossref's 150 pixels whatever the theme says about images
            $top = '<div class="ccrwp-crossmark"><a data-target="crossmark"><img src="' . esc_url(CcrCrossmark::LOGO) . '" width="150" style="'
                 . esc_attr(CcrCrossmark::SIZE) . '" alt="Crossmark: check for updates" /></a></div>';
        }
        return $top . $html . '<p class="ccrwp-doi">DOI: <a href="' . esc_url($url) . '">' . esc_html($url) . '</a></p>';
    }
}
