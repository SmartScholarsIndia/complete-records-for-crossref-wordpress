=== Complete Records for Crossref ===
Contributors: smartscholars
Tags: doi, crossref, journal, orcid, scholarly publishing
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Registers DOIs with Crossref for articles published with WordPress, with complete records — and checks each one before and after.

== Description ==

For journals that publish with WordPress and are Crossref members. Each article gets a DOI with a record as complete as
Crossref's Participation Reports measure:

* title, authors in order, ORCID iDs (check digit verified), affiliations with ROR IDs;
* abstract (JATS), online publication date, volume, issue and pages;
* licence (Access Indicators), full-text links for Similarity Check and text mining;
* the reference list, each reference carrying its DOI when it names exactly one;
* Crossmark, when the journal has a Crossmark policy DOI — and Crossref's Crossmark button on the article.

Before anything is sent, the deposit file is shown and checked against Crossref's own published schema (5.4.0); a file
the schema refuses is never sent. After the deposit, Crossref's result for that deposit is read, and the public record is
read back from Crossref's REST API and compared, element by element, with what was sent.

The record is built and completed by the same core as Complete Records for Crossref for OJS
(https://github.com/SmartScholarsIndia/complete-records-for-crossref).

The journal's Crossref password is stored encrypted with the site's own secret keys and never shown again. Deposits can
go to Crossref's test system first.

Registered articles show their DOI as a link, carry the citation tags indexers read (citation_title, citation_author,
citation_doi …) and, with Crossmark, Crossref's button.

== Installation ==

1. Install and activate the plugin.
2. Crossref → Settings: the journal's title and ISSN, the publisher, your DOI prefix, the depositor, your Crossref login.
   Start on the test system.
3. On each article: the "Crossref record" box (authors, ORCID iDs, affiliations, abstract, references, pages).
4. Crossref → Records → the article: look at the file and the schema's verdict, then Deposit; then "Ask Crossref for its
   result"; on the live system, "Read the record back".

== Frequently Asked Questions ==

= Does it need anything from Crossref besides my login? =

Your DOI prefix and a login that may deposit for it. Crossmark needs your Crossmark policy page to have its own DOI.

= What does it send to anyone else? =

Nothing but the deposit to Crossref, the request for Crossref's result, Crossref's schema files (fetched once from
crossref.org) and, when you ask, the public record from api.crossref.org.

== Changelog ==

= 1.0.0 =
* First release.
