#!/bin/sh

set -eu

python3 << 'PY'
from pathlib import Path
import re

docs = Path("docs")

for html in docs.rglob("*.html"):
    depth = len(html.relative_to(docs).parts) - 1
    prefix = "../" * depth

    text = html.read_text()
    text = re.sub(
        r'^[ \t]*<link rel="(?:shortcut )?icon"[^>]*>\s*\n?',
        "",
        text,
        flags=re.MULTILINE,
    )

    text = re.sub(
        r'^[ \t]*<link rel="apple-touch-icon"[^>]*>\s*\n?',
        "",
        text,
        flags=re.MULTILINE,
    )

    icons = (
        f'\t\t<link rel="icon" type="image/png" href="{prefix}favicon.png"/>\n'
        f'\t\t<link rel="shortcut icon" href="{prefix}favicon.ico"/>\n'
        f'\t\t<link rel="apple-touch-icon" href="{prefix}favicon.png"/>\n'
    )

    text = text.replace("</head>", f"{icons}\t</head>", 1)

    html.write_text(text)
PY
