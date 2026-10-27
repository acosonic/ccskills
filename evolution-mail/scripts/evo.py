#!/usr/bin/env python3
"""Read mail from GNOME Evolution's local cache and open pre-filled composers.

Read-only towards Evolution: the summary databases are opened with mode=ro and
message files are only read. Sending always goes through Evolution's own
composer (mailto:), where the user presses Send.

Run with `python3 -I evo.py <command> ...`. Commands:
  accounts                         mail accounts and their cache directories
  folders  [-a ACC]                folders with message counts
  list     [-a ACC] [-f FOLDER] [--from X] [--subject X] [--since YYYY-MM-DD]
           [--attach] [--unread] [-n N]
  show     UID [-a ACC] [-f FOLDER] [--html] [--max N]
  extract  UID OUTDIR [-a ACC] [-f FOLDER] [--unzip]
  path     UID [-a ACC] [-f FOLDER]
  compose  --to A[,B] [--cc ..] [--bcc ..] [--subject S] [--body-file F | --body S]
           [--attach FILE ...] [--reply-to-uid UID [-a ACC] [-f FOLDER]] [--dry-run]
"""
import argparse
import configparser
import datetime as dt
import email
import glob
import hashlib
import os
import pathlib
import sqlite3
import subprocess
import sys
import urllib.parse
import zipfile
from email import policy

CACHE = os.path.expanduser("~/.cache/evolution/mail")
LOCAL = os.path.expanduser("~/.local/share/evolution/mail")
SOURCES = os.path.expanduser("~/.config/evolution/sources")

# CamelMessageFlags bits
F_ANSWERED, F_DELETED, F_DRAFT, F_FLAGGED, F_SEEN, F_ATTACH = 1, 2, 4, 8, 16, 32


def source_name(uid):
    p = os.path.join(SOURCES, uid + ".source")
    if not os.path.exists(p):
        return "", ""
    cp = configparser.ConfigParser(interpolation=None, strict=False)
    try:
        cp.read(p, encoding="utf-8")
        d = cp["Data Source"]
        return d.get("DisplayName", ""), d.get("BackendName", "")
    except Exception:
        return "", ""


def accounts():
    out = []
    for base in (CACHE, LOCAL):
        for db in sorted(glob.glob(os.path.join(base, "*", "folders.db"))):
            d = os.path.dirname(db)
            uid = os.path.basename(d)
            if uid in ("trash", "vfolder", "rss"):
                continue
            name, backend = source_name(uid)
            out.append({"uid": uid, "name": name or uid, "backend": backend, "dir": d, "db": db})
    return out


def pick_account(sel):
    accs = accounts()
    if not accs:
        sys.exit("No Evolution mail cache found under ~/.cache/evolution/mail")
    if not sel:
        # default: the first non-local account that has messages
        for a in accs:
            if a["uid"] != "local":
                return a
        return accs[0]
    for a in accs:
        if sel in (a["uid"], a["name"]) or sel.lower() in a["name"].lower():
            return a
    sys.exit(f"Account not found: {sel}. Try: accounts")


def connect(acc):
    return sqlite3.connect(f"file:{acc['db']}?mode=ro", uri=True)


def folder_tables(con):
    names = [r[0] for r in con.execute("select name from sqlite_master where type='table'")]
    skip = {"folders", "keys"}
    return [n for n in names if n not in skip and not n.endswith("_version")
            and not n.endswith("_bodystructure") and not n.startswith("sqlite_")]


def resolve_folder(con, folder):
    tabs = folder_tables(con)
    if folder in tabs:
        return folder
    low = [t for t in tabs if t.lower() == folder.lower()]
    if low:
        return low[0]
    sys.exit(f"Folder not found: {folder}. Try: folders")


def fmt_time(t):
    try:
        return dt.datetime.fromtimestamp(int(t)).strftime("%Y-%m-%d %H:%M")
    except Exception:
        return "?"


def message_file(acc, folder, uid):
    """Cached RFC822 file of a message, or None if Evolution has not downloaded it."""
    h = hashlib.sha256(uid.encode()).hexdigest()
    fdir = os.path.join(acc["dir"], "folders", folder)
    hits = glob.glob(os.path.join(glob.escape(fdir), "cur", "*", h))
    if not hits:  # other backends lay folders out differently
        for root, _dirs, files in os.walk(acc["dir"]):
            if h in files:
                hits.append(os.path.join(root, h))
                break
    if not hits and acc["uid"] == "local":  # local Maildir: file name starts with the uid
        hits = glob.glob(os.path.join(glob.escape(fdir), "*", glob.escape(uid) + "*"))
    return hits[0] if hits else None


def find_uid(acc, uid, folder):
    con = connect(acc)
    tabs = [resolve_folder(con, folder)] if folder else folder_tables(con)
    for t in tabs:
        try:
            if con.execute(f'select 1 from "{t}" where uid=?', (uid,)).fetchone():
                return t
        except sqlite3.Error:
            pass
    sys.exit(f"UID not found in {acc['name']}")


def load(acc, uid, folder):
    folder = find_uid(acc, uid, folder)
    path = message_file(acc, folder, uid)
    if not path:
        sys.exit("NOT CACHED: Evolution has only the header of this message. Ask the user to open it "
                 "in Evolution (one click is enough), then retry.")
    with open(path, "rb") as fh:
        return folder, path, email.message_from_binary_file(fh, policy=policy.default)


# ---------- commands ----------

def cmd_accounts(a):
    for x in accounts():
        print(f"{x['name']}\t{x['backend']}\t{x['uid']}\t{x['dir']}")


def cmd_folders(a):
    acc = pick_account(a.account)
    con = connect(acc)
    for t in sorted(folder_tables(con)):
        try:
            n, unread = con.execute(f'select count(*), sum(read=0) from "{t}"').fetchone()
        except sqlite3.Error:
            continue
        print(f"{n:7d}  {unread or 0:5d} unread  {t}")


def cmd_list(a):
    acc = pick_account(a.account)
    con = connect(acc)
    t = resolve_folder(con, a.folder)
    where, args = [], []
    if a.sender:
        where.append("mail_from like ?"); args.append(f"%{a.sender}%")
    if a.subject:
        where.append("subject like ?"); args.append(f"%{a.subject}%")
    if a.since:
        where.append("dreceived >= ?"); args.append(int(dt.datetime.fromisoformat(a.since).timestamp()))
    if a.attach:
        where.append("(attachment=1 or (flags & 32)>0)")
    if a.unread:
        where.append("read=0")
    sql = f'select uid, dreceived, mail_from, subject, attachment, read, preview from "{t}"'
    if where:
        sql += " where " + " and ".join(where)
    sql += " order by dreceived desc limit ?"
    args.append(a.n)
    for uid, rec, frm, subj, att, read, prev in con.execute(sql, args):
        cached = "C" if message_file(acc, t, uid) else "-"
        flags = ("A" if att else " ") + (" " if read else "U") + cached
        print(f"{fmt_time(rec)}  {flags}  {(frm or '')[:45]:45}  {subj or ''}")
        print(f"    uid={uid}")
        if a.preview and prev:
            print("    " + " ".join(prev.split())[:200])
    print(f"\n[folder: {t}]  flags: A=attachment U=unread C=full message cached (- = header only)",
          )


def body_text(m, html=False):
    part = m.get_body(("html", "plain") if html else ("plain", "html"))
    if not part:
        return ""
    txt = part.get_content()
    if part.get_content_type() == "text/html" and not html:
        import html as h, re
        txt = re.sub(r"(?is)<(script|style).*?</\1>", "", txt)
        txt = re.sub(r"(?i)<br\s*/?>|</p>|</div>|</tr>", "\n", txt)
        txt = h.unescape(re.sub(r"<[^>]+>", "", txt))
        txt = re.sub(r"\n\s*\n\s*\n+", "\n\n", txt)
    return txt


def cmd_show(a):
    acc = pick_account(a.account)
    folder, path, m = load(acc, a.uid, a.folder)
    for k in ("From", "To", "Cc", "Date", "Subject", "Message-ID"):
        if m[k]:
            print(f"{k}: {m[k]}")
    atts = [(p.get_filename(), p.get_content_type()) for p in m.iter_attachments() if p.get_filename()]
    for fn, ct in atts:
        print(f"Attachment: {fn} ({ct})")
    print(f"Folder: {folder}\nFile: {path}\n")
    print(body_text(m, a.html)[: a.max])


def safe_name(fn):
    fn = os.path.basename(fn.replace("\\", "/")).strip() or "attachment"
    return fn.replace("\x00", "")


def cmd_extract(a):
    acc = pick_account(a.account)
    _f, _p, m = load(acc, a.uid, a.folder)
    os.makedirs(a.outdir, exist_ok=True)
    for p in m.iter_attachments():
        fn = p.get_filename()
        if not fn:
            continue
        dest = os.path.join(a.outdir, safe_name(fn))
        data = p.get_payload(decode=True) or b""
        with open(dest, "wb") as fh:
            fh.write(data)
        print(f"{dest}\t{p.get_content_type()}\t{len(data)} B")
        if a.unzip and zipfile.is_zipfile(dest):
            zdir = os.path.splitext(dest)[0]
            os.makedirs(zdir, exist_ok=True)
            with zipfile.ZipFile(dest) as z:
                for info in z.infolist():
                    name = info.filename
                    if not info.flag_bits & 0x800:  # not flagged UTF-8: try cp437 -> utf-8 repair
                        try:
                            name = name.encode("cp437").decode("utf-8")
                        except Exception:
                            pass
                    target = os.path.realpath(os.path.join(zdir, name))
                    if not target.startswith(os.path.realpath(zdir) + os.sep):
                        continue  # zip-slip
                    if info.is_dir():
                        os.makedirs(target, exist_ok=True)
                        continue
                    os.makedirs(os.path.dirname(target), exist_ok=True)
                    with z.open(info) as src, open(target, "wb") as dst:
                        dst.write(src.read())
                    print(f"  {target}")


def cmd_path(a):
    acc = pick_account(a.account)
    folder = find_uid(acc, a.uid, a.folder)
    p = message_file(acc, folder, a.uid)
    print(p or "NOT CACHED")


def cmd_compose(a):
    params = []
    for key in ("cc", "bcc"):
        v = getattr(a, key)
        if v:
            params.append((key, v))
    subject = a.subject or ""
    body = a.body or ""
    if a.body_file:
        with open(a.body_file, encoding="utf-8") as fh:
            body = fh.read()
    if a.reply_to_uid:
        acc = pick_account(a.account)
        _f, _p, orig = load(acc, a.reply_to_uid, a.folder)
        mid = orig["Message-ID"]
        if mid:
            refs = ((orig["References"] or "") + " " + mid).strip()
            params += [("In-Reply-To", mid), ("References", refs)]
        if not subject:
            s = str(orig["Subject"] or "")
            subject = s if s.lower().startswith(("re:", "odg:")) else "RE: " + s
        if a.quote:
            q = "\n".join("> " + l for l in body_text(orig).splitlines())
            body += f"\n\n{orig['Date']}, {orig['From']}:\n{q}\n"
    if subject:
        params.append(("subject", subject))
    if body:
        params.append(("body", body.replace("\r\n", "\n").replace("\n", "\r\n")))
    for f in a.attach or []:
        f = os.path.abspath(f)
        if not os.path.isfile(f):
            sys.exit(f"Attachment not found: {f}")
        params.append(("attach", pathlib.Path(f).as_uri()))
    to = ",".join(x.strip() for x in a.to.split(",")) if a.to else ""
    url = "mailto:" + urllib.parse.quote(to, safe="@,") + "?" + "&".join(
        f"{k}={urllib.parse.quote(v, safe='')}" for k, v in params)
    if a.dry_run:
        print(url)
        return
    subprocess.Popen(["evolution", url], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                     start_new_session=True)
    print("Composer opened in Evolution. The user reviews it and presses Send.")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = ap.add_subparsers(dest="cmd", required=True)

    def common(p, folder_default=None):
        p.add_argument("-a", "--account", help="account display name, part of it, or uid")
        p.add_argument("-f", "--folder", default=folder_default, help="folder full name, e.g. Inbox or Inbox/Sub")

    sp.add_parser("accounts")
    common(sp.add_parser("folders"))
    p = sp.add_parser("list"); common(p, "Inbox")
    p.add_argument("--from", dest="sender"); p.add_argument("--subject"); p.add_argument("--since")
    p.add_argument("--attach", action="store_true"); p.add_argument("--unread", action="store_true")
    p.add_argument("--preview", action="store_true"); p.add_argument("-n", type=int, default=20)
    p = sp.add_parser("show"); common(p); p.add_argument("uid")
    p.add_argument("--html", action="store_true"); p.add_argument("--max", type=int, default=20000)
    p = sp.add_parser("extract"); common(p); p.add_argument("uid"); p.add_argument("outdir")
    p.add_argument("--unzip", action="store_true")
    p = sp.add_parser("path"); common(p); p.add_argument("uid")
    p = sp.add_parser("compose"); common(p)
    p.add_argument("--to", default=""); p.add_argument("--cc"); p.add_argument("--bcc")
    p.add_argument("--subject"); p.add_argument("--body"); p.add_argument("--body-file")
    p.add_argument("--attach", nargs="*"); p.add_argument("--reply-to-uid")
    p.add_argument("--quote", action="store_true", help="append the original message quoted")
    p.add_argument("--dry-run", action="store_true", help="print the mailto URL only")
    a = ap.parse_args()
    globals()["cmd_" + a.cmd](a)


if __name__ == "__main__":
    main()
