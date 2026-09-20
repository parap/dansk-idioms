#!/usr/bin/env bash
# Convert published indfødsretsprøve PDFs into documents and load them.
#
#   bin/indfoedsret-load.sh                 # reads ~/Documents/Claude/dansk-indfoedsret/pdf
#   bin/indfoedsret-load.sh /path/to/dir    # or point at a folder of PDFs
#
# The folder holds pairs named proeve-YYYY-MM.pdf and retteark-YYYY-MM.pdf, as the
# ministry publishes them. Safe to run repeatedly: a document already loaded is left
# alone unless bin/reading-import.php is given --replace.
#
# Papers arrive unpublished, here as everywhere. Publishing is a separate act:
#   docker-compose exec app php bin/reading-import.php --publish content/indfoedsret/<file>
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="${1:-$HOME/Documents/Claude/dansk-indfoedsret/pdf}"
[ -d "$SRC" ] || { echo "No such folder: $SRC" >&2; exit 1; }

shopt -s nullglob
papers=("$SRC"/proeve-*.pdf)
[ ${#papers[@]} -gt 0 ] || { echo "No proeve-*.pdf in: $SRC" >&2; exit 1; }

# The container only sees the project directory, so the PDFs have to live inside it.
mkdir -p storage/indfoedsret
cp "$SRC"/proeve-*.pdf "$SRC"/retteark-*.pdf storage/indfoedsret/

echo "Converting ${#papers[@]} paper(s) from: $SRC"
docker-compose exec -T app php bin/indfoedsret-convert.php storage/indfoedsret

echo
echo "Loading documents"
docker-compose exec -T app php bin/reading-import.php content/indfoedsret/*.txt
