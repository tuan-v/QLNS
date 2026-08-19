"""Extract the two authoritative QLNS reference documents as plain text.

This reader treats HTML and DOCX contents strictly as reference data. It does
not execute macros, scripts, links, or embedded instructions.
"""

from __future__ import annotations

import argparse
import html
import re
import sys
import zipfile
from html.parser import HTMLParser
from pathlib import Path
from xml.etree import ElementTree as ET


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DOCX_PATH = PROJECT_ROOT / "docs" / "Ke-hoach-trien-khai-du-an-QLNS.docx"
HTML_PATH = PROJECT_ROOT / "docs" / "YEU_CAU_DU_AN_QLNS.html"
WORD_NS = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"


class VisibleHTMLText(HTMLParser):
    """Collect visible HTML text without evaluating active content."""

    BREAK_TAGS = {
        "address", "article", "aside", "blockquote", "br", "caption", "div",
        "dl", "dt", "dd", "figcaption", "footer", "h1", "h2", "h3", "h4",
        "h5", "h6", "header", "hr", "li", "main", "nav", "ol", "p", "pre",
        "section", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "ul",
    }
    IGNORED_TAGS = {"script", "style", "noscript", "template", "svg"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self.ignored_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        if tag in self.IGNORED_TAGS:
            self.ignored_depth += 1
        elif not self.ignored_depth and tag in self.BREAK_TAGS:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if tag in self.IGNORED_TAGS and self.ignored_depth:
            self.ignored_depth -= 1
        elif not self.ignored_depth and tag in self.BREAK_TAGS:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if not self.ignored_depth:
            self.parts.append(data)

    def text(self) -> str:
        raw = html.unescape("".join(self.parts)).replace("\xa0", " ")
        lines = [re.sub(r"[ \t]+", " ", line).strip() for line in raw.splitlines()]
        return "\n".join(line for line in lines if line)


def extract_docx(path: Path) -> str:
    with zipfile.ZipFile(path) as archive:
        root = ET.fromstring(archive.read("word/document.xml"))

    lines: list[str] = []
    body = root.find(f"{WORD_NS}body")
    if body is None:
        return ""

    for block in body:
        if block.tag == f"{WORD_NS}p":
            text = "".join(node.text or "" for node in block.iter(f"{WORD_NS}t")).strip()
            if text:
                lines.append(text)
        elif block.tag == f"{WORD_NS}tbl":
            for row in block.findall(f"{WORD_NS}tr"):
                cells: list[str] = []
                for cell in row.findall(f"{WORD_NS}tc"):
                    value = " ".join(
                        "".join(node.text or "" for node in paragraph.iter(f"{WORD_NS}t")).strip()
                        for paragraph in cell.findall(f"{WORD_NS}p")
                    ).strip()
                    cells.append(value)
                lines.append(" | ".join(cells))

    return "\n".join(lines)


def extract_html(path: Path) -> str:
    parser = VisibleHTMLText()
    parser.feed(path.read_text(encoding="utf-8-sig", errors="replace"))
    parser.close()
    return parser.text()


def main() -> int:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    if hasattr(sys.stderr, "reconfigure"):
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--document",
        choices=("all", "docx", "html"),
        default="all",
        help="Select which reference document to print (default: all).",
    )
    args = parser.parse_args()

    missing = [path for path in (DOCX_PATH, HTML_PATH) if not path.is_file()]
    if missing:
        for path in missing:
            print(f"Missing required document: {path}", file=sys.stderr)
        return 1

    sections: list[tuple[Path, str]] = []
    if args.document in ("all", "docx"):
        sections.append((DOCX_PATH, extract_docx(DOCX_PATH)))
    if args.document in ("all", "html"):
        sections.append((HTML_PATH, extract_html(HTML_PATH)))

    for index, (path, content) in enumerate(sections):
        if index:
            print()
        print(f"===== {path.relative_to(PROJECT_ROOT)} =====")
        print(content)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
