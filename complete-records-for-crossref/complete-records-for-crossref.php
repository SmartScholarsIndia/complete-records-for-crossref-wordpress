<?php
/**
 * Plugin Name:       Complete Records for Crossref
 * Plugin URI:        https://github.com/SmartScholarsIndia/complete-records-for-crossref-wordpress
 * Description:       Registers DOIs with Crossref for articles published with WordPress — titles, authors with ORCID iDs and affiliations (ROR), abstracts, dates, pages, licences, reference lists and Crossmark — checks every deposit against Crossref's own schema before it is sent, and reads each record back from Crossref.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Smart Scholars
 * Author URI:        https://smartscholars.in
 * License:           GPL-3.0
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       complete-records-for-crossref
 *
 * The same core as Complete Records for Crossref for OJS (core/): the record is built the way OJS's own Crossref plugin
 * builds one, then completed by the very class that completes OJS's deposits (reference list, affiliations with ROR IDs,
 * Crossmark), checked against Crossref's published schema, sent with the journal's own Crossref login, and read back.
 *
 * Copyright (c) 2026 Smart Scholars. Distributed under the GNU GPL v3. See LICENSE.
 */

defined('ABSPATH') || exit;

define('CCRWP_VERSION', '1.0.0');
define('CCRWP_FILE', __FILE__);
define('CCRWP_DIR', __DIR__);

require_once __DIR__ . '/core/CcrCompleter.php';   // brings CcrSchema and CcrCitations
require_once __DIR__ . '/core/CcrValidator.php';
require_once __DIR__ . '/core/CcrReadBack.php';
require_once __DIR__ . '/core/CcrCrossmark.php';
require_once __DIR__ . '/includes/class-ccrwp-settings.php';
require_once __DIR__ . '/includes/class-ccrwp-record.php';
require_once __DIR__ . '/includes/class-ccrwp-crossref.php';
require_once __DIR__ . '/includes/class-ccrwp-admin.php';
require_once __DIR__ . '/includes/class-ccrwp-public.php';

CcrWp_Admin::init();
CcrWp_Public::init();
