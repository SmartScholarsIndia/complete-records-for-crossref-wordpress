#!/bin/bash
# Build a copy of crossref.org's and w3.org's schema folders, laid out as tools/crossref_validate.py keeps its cache,
# for a machine that cannot reach crossref.org (the workspace where the plugin is built cannot). Anywhere that can,
# crossref_validate.py fetches Crossref's own files and this is not needed.
#
#   bash ojs-plugin/tests/schema_copies.sh /tmp/crossref-schema-copies
#   CR_SCHEMA_CACHE=/tmp/crossref-schema-copies python3 ojs-plugin/tests/test_validate.py
#
# 3 Oct 2026. The files come from the public project front-matter/commonmeta (MIT; it keeps Crossref's schema files to
# validate its own Crossref output), at pinned commits, so the copy is the same every time:
#   crossref5.3.1.xsd, common5.3.1.xsd                       commonmeta-py d9334468f99b8158c11e4cf5069347c28944a48e (2024-02-04)
#   crossref/common/languages/mediatypes/fundingdata 5.4.0   commonmeta    c18121f0ccacbba7da673a4868ab6e642704717d (2025-09-24)
#   the 5.5.0 set, and the files every version shares        commonmeta-py b9b4caad836f57d83e3761ebcb10b49b6bc238b3 (2026-09-16)
#     (fundref, AccessIndicators, relations, clinicaltrials, JATS, standard-modules — one file each on crossref.org,
#      so a 5.3.1 deposit is checked against today's copy of them, as Crossref checks it)
#   W3C's MathML 3 and xml.xsd: the copies in that same 5.5.0 set, placed where crossref*.xsd names them on www.w3.org.
# They are copies kept by a third party, not files fetched from crossref.org: a pass here is a strong check, and the
# check on a machine that reaches crossref.org (crossref_validate.py without --offline) is the one that counts.
set -euo pipefail
out=${1:?usage: schema_copies.sh OUTPUT_FOLDER}
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
git clone -q --filter=blob:none --no-checkout https://github.com/front-matter/commonmeta-py "$tmp/py"
git clone -q --filter=blob:none --no-checkout https://github.com/front-matter/commonmeta "$tmp/go"
mkdir -p "$tmp/531" "$tmp/540" "$tmp/550"
git -C "$tmp/py" archive d9334468f99b8158c11e4cf5069347c28944a48e commonmeta/resources/crossref | tar -x -C "$tmp/531" --strip-components=3
git -C "$tmp/go" archive c18121f0ccacbba7da673a4868ab6e642704717d resources/crossref | tar -x -C "$tmp/540" --strip-components=2
git -C "$tmp/py" archive b9b4caad836f57d83e3761ebcb10b49b6bc238b3 commonmeta/resources/crossref | tar -x -C "$tmp/550" --strip-components=3
S="$out/www.crossref.org/schemas"
M="$out/www.w3.org/Math/XMLSchema/mathml3"
mkdir -p "$S" "$M" "$out/www.w3.org/2009/01"
cp "$tmp/531/crossref5.3.1.xsd" "$tmp/531/common5.3.1.xsd" "$S/"
for f in crossref common languages mediatypes fundingdata; do
  cp "$tmp/540/${f}5.4.0.xsd" "$tmp/550/${f}5.5.0.xsd" "$S/"
done
cp "$tmp/550/fundref.xsd" "$tmp/550/AccessIndicators.xsd" "$tmp/550/relations.xsd" "$tmp/550/clinicaltrials.xsd" \
   "$tmp/550/module-ali.xsd" "$tmp/550/xml.xsd" "$tmp"/550/JATS-*.xsd "$S/"
cp -r "$tmp/550/standard-modules" "$S/"
cp "$tmp"/550/standard-modules/mathml3/*.xsd "$M/"
cp "$tmp/550/xml.xsd" "$out/www.w3.org/2009/01/xml.xsd"
echo "schema copies in $out: $(find "$out" -name '*.xsd' | wc -l) files"
