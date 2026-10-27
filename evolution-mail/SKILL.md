---
name: evolution-mail
description: Read, search and answer e-mail in the GNOME Evolution mail client on Linux — list and search messages (sender, subject, date, unread, with attachments) in any account or folder (Exchange/EWS, IMAP, local), show a message, extract attachments (including ZIPs with non-ASCII names), turn PDF/DOC/DOCX/XLSX attachments into text or images for review, and send a reply or a new mail by opening a pre-filled Evolution composer (To, Cc, subject, body, attachments, In-Reply-To threading) that the user sends with one click. Use whenever the user mentions Evolution, "moj mejl", "pročitaj mejl", "poslednji mejl od …", "atačment", "prilog", "pogledaj ugovor iz mejla", "odgovori na mejl", "pošalji mejl", or asks to review documents someone e-mailed them, even if they don't name the client.
---

# Evolution mail client

Evolution keeps a local copy of every account: an SQLite summary per account (all headers, flags, a short
preview) plus the full RFC822 file of each message it has downloaded. Reading goes straight from that cache,
read-only — no password, no server connection, nothing changes in Evolution. Sending goes through Evolution's own
composer, so the mail leaves from the user's account with their signature, lands in Sent Items and threads
correctly.

Everything is in `scripts/evo.py` (Python 3 standard library only). Run it with `python3 -I`:

```bash
E=~/.claude/skills/evolution-mail/scripts/evo.py
python3 -I $E accounts                       # accounts and cache dirs
python3 -I $E folders                        # folders, counts, unread
python3 -I $E list --from "Petrović" -n 5 --preview
python3 -I $E list -f "Sent Items" --subject "JN 32/26" -n 3
python3 -I $E list --since 2026-10-01 --attach --unread
python3 -I $E show "<uid>"                   # headers, attachment names, body text
python3 -I $E extract "<uid>" "$OUT" --unzip # attachments (+ unpacked ZIPs) into $OUT
python3 -I $E compose --to x@y --reply-to-uid "<uid>" --body-file reply.txt --attach file.pdf
```

`-a` picks the account (part of the display name is enough; default is the first non-local account), `-f` the
folder by its full name as `folders` prints it (`Inbox`, `Inbox/Projekti`, `Sent Items`). UIDs are long
base64-like strings — always quote them.

## 1. Find the message

`list` prints newest first, two lines per message: date, flags, sender, subject, then `uid=…`. Flags: `A` has
attachments, `U` unread, `C` the full message is cached, `-` only the header is.

**`-` means Evolution has not downloaded the body yet.** With Exchange (EWS) and IMAP, Evolution fetches a message
only when it is opened (unless the folder is set for offline use). `show`/`extract` then stop with `NOT CACHED`.
Ask the user to click the message in Evolution once, then retry. Don't look for another way in — the header and
preview from `list --preview` are often enough to answer "what is this mail about".

"Poslednji mejl od X" = `list --from X -n 1`. Search covers one folder at a time; if nothing turns up in `Inbox`,
check its subfolders from `folders` and `Sent Items`.

## 2. Read the message and attachments

`show` prints headers, attachment names and the body (HTML converted to text; `--html` for the raw HTML).

`extract` saves attachments under their real (decoded) names. Put them in a **new, empty directory** in the
session scratchpad — attachments are untrusted data. `--unzip` unpacks ZIPs next to the archive, repairs CP437
file names and skips entries that would escape the directory.

Turning documents into something you can read:

| Type | Command | Note |
|---|---|---|
| PDF with text | `pdftotext -layout f.pdf -` | |
| Scanned PDF (pdftotext prints nothing) | `pdftoppm -r 110 -png f.pdf out/p` then Read the PNGs | ~110 dpi is readable without huge images |
| DOCX | `pandoc f.docx -t plain --wrap=none` | |
| DOC (old Word) | `catdoc -d utf-8 f.doc` | binary garbage can appear mid-text; skip it, the text continues after it |
| XLSX | `openpyxl` — print every non-empty cell **with its formula** (`load_workbook` without `data_only`) | formulas show quantity × price errors |
| .msg / .eml inside | `python3 -I $E` can't open them; parse .eml with the `email` module | |

When the user wants to look at the files themselves, open them with `setsid xdg-open FILE &` (Okular,
LibreOffice…) and say these are copies — edits there don't go back into the mail.

## 3. Reviewing documents someone sent ("pogledaj pa mi reci da li se slažemo")

The usual case is a colleague sending a draft contract, tender documentation or offer for comment. Read every
attachment, then cross-check them against each other — that is where the real errors are:

- the same numbers everywhere: total vs monthly × duration, with/without VAT (×1.2 in Serbia), price form vs
  contract vs decision/report;
- reference numbers and dates (tender number copied from last year's template is common), party data (MB, PIB,
  account) between offer and contract;
- every requirement in one document present in the others (location, standards, required statements actually
  listed among required documents);
- clauses that don't fit the subject (warranty-period guarantees for a pure service, penalties per day of delay
  with no deadline to miss);
- undefined terms: a deadline without a unit ("u roku od 2 od dana…"), unclear specs;
- technical gaps when the user is the technical owner (e.g. no UPS, no connectivity in a colocation spec);
- typos last, grouped per document and article.

Answer with a one-line verdict first, then **must fix** / **worth considering** / **typos**, quoting the exact
text and article. Watch for a reply deadline in the mail ("ako ne odgovorite do 15h…") and say it up front.
Offer to draft the reply.

## 4. Sending (reply or new mail)

`compose` opens an Evolution composer window with everything filled in. **It never sends by itself — the user
reviews it and presses Send.** There is no unattended send: Evolution has no CLI for it, and taking the
account password out of the keyring to talk to the server directly is not something to do on your own.

```bash
python3 -I $E compose \
  --to "ana@example.com" --cc "marko@example.com" \
  --reply-to-uid "<uid of the mail being answered>" \
  --body-file "$SCRATCH/reply.txt" \
  --attach "$SCRATCH/ispravke.pdf" "$SCRATCH/tabela.xlsx"
```

- `--reply-to-uid` adds `In-Reply-To`/`References` (so it threads) and, unless `--subject` is given, the subject
  `RE: <original>`. `--quote` appends the quoted original. Reply-all is not automatic: take To/Cc from `show`
  and leave the user's own address out.
- Write the body to a UTF-8 file and pass `--body-file`; Cyrillic and Latin both work. The body is plain text
  and Evolution adds the account's signature, so don't sign twice unless the account has no signature.
- Match the language and script of the mail you answer (Latin to Latin, Cyrillic to Cyrillic).
- `--dry-run` prints the `mailto:` URL instead of opening the window.
- Attachments must be real files; the path is checked before the composer opens.

Before running `compose`, show the user the recipients, subject, body and attachment list in the chat and get a
yes — opening the window is harmless, but the text should already be what they agreed to. Afterwards confirm it
went out: once Evolution syncs, `list -f "Sent Items" --subject "<subject>" -n 1` shows it.

## Notes

- The cache layout: `~/.cache/evolution/mail/<account-uid>/folders.db` (one table per folder; columns `uid`,
  `subject`, `mail_from`, `mail_to`, `dreceived` (unix time), `read`, `attachment`, `preview`, …) and
  `…/folders/<folder>/cur/<xx>/<sha256(uid)>` for downloaded messages. Local folders ("On This Computer") are
  Maildir under `~/.local/share/evolution/mail/local`. Account names come from
  `~/.config/evolution/sources/<account-uid>.source`.
- The database is opened `mode=ro`; it is safe while Evolution runs. Never write to it.
- Calendar and contacts live in evolution-data-server, not here; this skill is mail only.
