#!/bin/sh
# Builds the commentary corpus one book at a time, then imports it.
#
# It drives a fetching script that writes <corpus>/<book>/<chapter>/commentaries/<work>.md
# (see BIBLE-READER-SPEC.md §13b). Nothing is refetched: a chapter whose folder already has files
# is left alone, so this can be stopped and started as often as you like.
#
#   tools/fetch_commentary_corpus.sh <fetcher.py> <corpus dir> [book…]
#
# With no books named it works through the whole Bible in order. Fetching is deliberately unhurried
# (one chapter at a time, the fetcher's own pause between requests): this is somebody else's server.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. tools/site.sh

FETCHER=${1:?Usage: tools/fetch_commentary_corpus.sh <fetcher.py> <corpus dir> [book...]}
CORPUS=${2:?Usage: tools/fetch_commentary_corpus.sh <fetcher.py> <corpus dir> [book...]}
shift 2

# The books, with their chapter counts, in canonical order.
BOOKS='genesis:50 exodus:40 leviticus:27 numbers:36 deuteronomy:34 joshua:24 judges:21 ruth:4
1_samuel:31 2_samuel:24 1_kings:22 2_kings:25 1_chronicles:29 2_chronicles:36 ezra:10 nehemiah:13
esther:10 job:42 psalms:150 proverbs:31 ecclesiastes:12 songs:8 isaiah:66 jeremiah:52
lamentations:5 ezekiel:48 daniel:12 hosea:14 joel:3 amos:9 obadiah:1 jonah:4 micah:7 nahum:3
habakkuk:3 zephaniah:3 haggai:2 zechariah:14 malachi:4 matthew:28 mark:16 luke:24 john:21 acts:28
romans:16 1_corinthians:16 2_corinthians:13 galatians:6 ephesians:6 philippians:4 colossians:4
1_thessalonians:5 2_thessalonians:3 1_timothy:6 2_timothy:4 titus:3 philemon:1 hebrews:13 james:5
1_peter:5 2_peter:3 1_john:5 2_john:1 3_john:1 jude:1 revelation:22'

WANTED="$*"
for entry in $BOOKS; do
  book=${entry%%:*}
  chapters=${entry##*:}
  if [ -n "$WANTED" ]; then
    case " $WANTED " in *" $book "*) ;; *) continue ;; esac
  fi
  c=1
  while [ "$c" -le "$chapters" ]; do
    dir="$CORPUS/$book/$c"
    if [ -d "$dir/commentaries" ] && [ -n "$(ls -A "$dir/commentaries" 2>/dev/null)" ]; then
      c=$((c + 1))
      continue                       # already here
    fi
    mkdir -p "$dir"
    echo "== $book $c"
    python3 "$FETCHER" "$book" "$c" --outdir "$dir" || echo "!! $book $c failed; carrying on"
    c=$((c + 1))
  done
  echo "-- $book done; sending it up"
  rsync -a -e "$SSH" --include='*/' --include='commentaries/*.md' --exclude='*' --prune-empty-dirs \
    "$CORPUS/$book/" "$HOST:commentary-corpus/$book/"
  $SSH "$HOST" "cd ~/$REMOTE_APP && $PHP cli/import_commentary.php ~/commentary-corpus --book $book" | tail -3
done
echo "All done."
