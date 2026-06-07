#!/usr/bin/env python3
"""Compile every .po in this directory to a matching .mo (gettext binary)."""
import array
import os
import re
import struct
import sys


_ESCAPES = {"n": "\n", "t": "\t", "r": "\r", '"': '"', "\\": "\\", "a": "\a",
            "b": "\b", "f": "\f", "v": "\v"}


def unescape(text):
    """Decode .po escape sequences without mangling UTF-8 multibyte chars.

    Known escapes (\\n, \\t, \\", \\\\, ...) are converted; unknown ones such as
    the literal \\d \\e used in PHP date() formats are kept verbatim.
    """
    out = []
    i = 0
    while i < len(text):
        ch = text[i]
        if ch == "\\" and i + 1 < len(text):
            nxt = text[i + 1]
            if nxt in _ESCAPES:
                out.append(_ESCAPES[nxt])
            else:
                out.append("\\" + nxt)
            i += 2
        else:
            out.append(ch)
            i += 1
    return "".join(out)


def parse_po(path):
    """Return {msgid: msgstr} for a .po file, handling plurals and continuations."""
    messages = {}
    msgid = msgid_plural = None
    msgstrs = {}
    current = None  # 'msgid', 'msgid_plural', or ('msgstr', index)

    def flush():
        if msgid is None:
            return
        if msgid_plural is not None:
            key = msgid + "\x00" + msgid_plural
            value = "\x00".join(msgstrs[i] for i in sorted(msgstrs))
        else:
            key = msgid
            value = msgstrs.get(0, "")
        messages[key] = value

    def unquote(line):
        inner = re.sub(r'^\s*"|"\s*$', "", line)
        return unescape(inner)

    with open(path, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.rstrip("\n")
            if not line.strip() or line.lstrip().startswith("#"):
                if not line.strip():
                    flush()
                    msgid = msgid_plural = None
                    msgstrs = {}
                    current = None
                continue
            if line.startswith("msgid_plural"):
                current = "msgid_plural"
                msgid_plural = unquote(line[len("msgid_plural"):])
            elif line.startswith("msgid"):
                flush()
                msgid_plural = None
                msgstrs = {}
                current = "msgid"
                msgid = unquote(line[len("msgid"):])
            elif line.startswith("msgstr["):
                idx = int(line[7:line.index("]")])
                current = ("msgstr", idx)
                msgstrs[idx] = unquote(line[line.index("]") + 1:])
            elif line.startswith("msgstr"):
                current = ("msgstr", 0)
                msgstrs[0] = unquote(line[len("msgstr"):])
            elif line.lstrip().startswith('"'):
                text = unquote(line)
                if current == "msgid":
                    msgid += text
                elif current == "msgid_plural":
                    msgid_plural += text
                elif isinstance(current, tuple):
                    msgstrs[current[1]] += text
    flush()
    return messages


def write_mo(messages, path):
    keys = sorted(messages.keys())
    offsets = []
    ids = b""
    strs = b""
    for key in keys:
        msgstr = messages[key]
        kb = key.encode("utf-8")
        vb = msgstr.encode("utf-8")
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b"\x00"
        strs += vb + b"\x00"

    n = len(keys)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets = []
    voffsets = []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]

    output = struct.pack("Iiiiiii", 0x950412DE, 0, n, 7 * 4,
                         7 * 4 + n * 8, 0, 0)
    output += array.array("i", koffsets).tobytes()
    output += array.array("i", voffsets).tobytes()
    output += ids
    output += strs
    with open(path, "wb") as fh:
        fh.write(output)


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    for name in os.listdir(here):
        if name.endswith(".po"):
            po = os.path.join(here, name)
            mo = po[:-3] + ".mo"
            messages = parse_po(po)
            write_mo(messages, mo)
            print(f"{name} -> {os.path.basename(mo)} ({len(messages)} strings)")


if __name__ == "__main__":
    sys.exit(main())
