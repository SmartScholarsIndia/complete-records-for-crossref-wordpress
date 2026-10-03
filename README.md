# Complete Records for Crossref for WordPress

A WordPress plugin that registers DOIs with Crossref for articles published with WordPress — with records as complete as
Crossref's Participation Reports measure — checks every deposit against Crossref's own schema before it is sent, and
reads each record back from Crossref afterwards.

Developed and maintained by **Smart Scholars** (Hyderabad, India) · mou@smartscholars.in

It is the WordPress sibling of [Complete Records for Crossref for OJS](https://github.com/SmartScholarsIndia/complete-records-for-crossref)
and shares its core (`core/`): the record is first written the way OJS's own Crossref plugin writes one, then completed
by the very class that completes OJS's deposits — the reference list, each author's affiliation with its ROR ID, and
Crossmark — under the same rules and the same checks of the schema's order.

## What each deposit carries

| | |
|---|---|
| The article | title, online publication date, volume, issue, pages, the DOI and the article's page |
| Authors | in the order printed, given and family names, ORCID iDs (check digit verified), affiliations with ROR IDs |
| Abstract | JATS, paragraph by paragraph, with its language |
| Licence | Access Indicators (`ai:license_ref`) — the journal's, or the article's own |
| Full text | the PDF's address for Similarity Check and for text and data mining, when given |
| References | one per line in the article's box; a reference carries its DOI when it names exactly one |
| Crossmark | when the journal has a Crossmark policy DOI; registered articles then show Crossref's Crossmark button |

Schema 5.4.0. Before anything is sent, the file is shown and checked against Crossref's published schema (fetched once
from crossref.org); a file the schema refuses is never sent. The version number (`<timestamp>`) is always above the
article's last deposit and above any number Crossref names when it refuses one as "less or equal to previously submitted
version".

## After the deposit

**Crossref → Records → the article**: *Ask Crossref for its result* reads Crossref's own report on that deposit
(queued, then success, warning or failure, in Crossref's words). On the live system, *Read the record back from
Crossref* reads the public record from Crossref's REST API and compares it, element by element, with what was sent:
*landed*, *partly*, *missing*, or *waiting*.

On the article itself, once Crossref has registered it on the live system: the DOI as a link, the citation tags
indexers read (`citation_title`, `citation_author`, `citation_doi`, …) and, with Crossmark, Crossref's button held at
Crossref's 150 pixels whatever the theme says about images.

## Install

1. Download `complete-records-for-crossref-<version>.zip` from this repository's **Releases** page and use
   **Plugins → Add New → Upload Plugin**. WordPress 6.4 or later, PHP 7.4 or later.
2. **Crossref → Settings**: the journal's title and ISSN, the publisher, your DOI prefix and suffix pattern, the
   depositor, your Crossref login. The password is stored encrypted with your site's own secret keys and never shown
   again. Start on Crossref's test system.
3. On each article, the **Crossref record** box: authors, ORCID iDs, affiliations and ROR IDs, abstract, pages,
   references.
4. **Crossref → Records → the article**: look at the file and the schema's verdict, then **Deposit**.

## Tests

`bash tests/run_tests.sh` sets up a real WordPress (a checkout of WordPress's own GitHub mirror at a release tag — 7.1.2
in CI) on SQLite (WordPress's SQLite Database Integration), installs the plugin as it ships, starts a stand-in for
Crossref's deposit service and REST API (`tests/stand_in_crossref.py`, which keeps Crossref's rules for logins, the
queue and version numbers), and checks the settings, the record, the file against copies of Crossref's 5.4.0 schema,
the deposit, Crossref's result, the read-back and the article page. With `SHOTS=folder` it also drives the screens in
Chromium — the settings, the Crossref box in WordPress's block editor, the records, Deposit, the result, the read-back,
the article page — and keeps a screenshot of each. GitHub runs it on every push.

The articles, people and DOIs in the tests are example values (DOIs under Crossref's example prefix 10.5555).

## Licence

GNU General Public License v3 — see [LICENSE](LICENSE). Copyright © 2026 Smart Scholars.
