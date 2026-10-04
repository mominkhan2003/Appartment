#!/usr/bin/env python3
"""
Static checks for the browser layer.

Three mistakes are easy to make in the classic-script layer and each one shows
up as a silent failure or a console error rather than at author time:

  1. Writing to a hook with outerHTML. outerHTML REPLACES the element, so the
     hook disappears from the document. The write works once and every later
     call queries null. dashboard.js did exactly this with [data-meal-day], and
     the dashboard threw on its second load.

  2. Writing to a data-* hook that the page never renders. The write does
     nothing at best, and throws at worst.

  3. Inlining JavaScript in a page file and calling $(...). $ is scoped to
     each module's IIFE; only API, Fmt, esc, on and App are shared, because
     those are declared at the top level of api.js/app.js. reports.php called
     $('#summary') from an inline <script>, so the API call succeeded and then
     the render threw ReferenceError, leaving the page on its skeletons with
     the error visible only in the console.

The script-to-page map is derived from the pages' own $pageScripts declarations
rather than hand-listed, and an unregistered page module is reported as a
failure. A guard that quietly skips files is worse than no guard at all: an
earlier draft of this checker listed reports.js nowhere and therefore passed
clean while that exact bug was still present.

    python tests/check_dom_hooks.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS_DIR = ROOT / "assets" / "js"
INCLUDES_DIR = ROOT / "includes"

PAGE_SCRIPTS_RE = re.compile(r"\$pageScripts\s*=\s*\[(.*?)\]", re.S)
SCRIPT_NAME_RE = re.compile(r"""['"]([^'"]+\.js)['"]""")
# <script src="<?= e(asset('js/foo.js')) ?>"></script> inside the shared
# layout, which loads the same scripts on every page.
SHARED_SCRIPT_RE = re.compile(r"""asset\(\s*['"]js/([^'"]+\.js)['"]\s*\)""")

# Properties that replace the element instead of filling it.
DESTRUCTIVE = ("outerHTML", "replaceWith", "remove")

# Properties that write into an element the page must therefore provide.
WRITES = ("innerHTML", "textContent", "innerText", "value")

HOOK_RE = re.compile(r"""\$\(\s*['"]\[data-([a-z0-9-]+)\]""")
WRITE_RE = re.compile(
    r"""\$\(\s*['"]\[data-([a-z0-9-]+)\][^)]*\)"""
    r"""\s*(?:\?)?\s*\.\s*(\w+)"""
)


def hooks_written_to(js: str) -> set[str]:
    """Hook names a script writes into, via $(`[data-x]`).prop = ... ."""
    return {m.group(1) for m in WRITE_RE.finditer(js) if m.group(2) in WRITES}


INLINE_SCRIPT_RE = re.compile(
    r"<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>", re.S | re.I
)
PHP_TAG_RE = re.compile(r"<\?.*?\?>|<\?=.*?\?>", re.S)

# Helpers that are deliberately scoped to a module's IIFE. Only API, Fmt, esc,
# on and App are shared, because they are declared at the top level of
# api.js/app.js. A page that inlines JavaScript and reaches for $(...) therefore
# calls an undefined name: reports.php did exactly this, the ReferenceError was
# thrown after the API call had already succeeded, and the page sat on its
# loading skeletons forever with the error only visible in the console.
MODULE_SCOPED_RE = re.compile(r"\$\$?\s*\(")


def page_script_map() -> dict[str, str]:
    """script filename -> page that loads it, read from $pageScripts.

    Derived from the pages themselves, so a page module is covered the moment
    it is registered and never silently skipped.
    """
    mapping: dict[str, str] = {}
    for page in sorted(ROOT.glob("*.php")):
        text = page.read_text(encoding="utf-8", errors="replace")
        for block in PAGE_SCRIPTS_RE.findall(text):
            for name in SCRIPT_NAME_RE.findall(block):
                mapping[Path(name).name] = page.name
    return mapping


def shared_scripts() -> set[str]:
    """Scripts the shared layout loads on every page (api, app, diagnostics)."""
    names: set[str] = set()
    for part in sorted(INCLUDES_DIR.glob("*.php")):
        names.update(SHARED_SCRIPT_RE.findall(part.read_text(encoding="utf-8")))
    return names


def unregistered_scripts(mapping: dict[str, str]) -> list[str]:
    """Page modules on disk that no page loads, so nothing checks them."""
    on_disk = {p.name for p in JS_DIR.glob("*.js")}
    return sorted(on_disk - set(mapping) - shared_scripts())


def inline_js_problems(page: str) -> list[str]:
    """Inline <script> blocks in a page that use module-scoped helpers."""
    path = ROOT / page
    if not path.is_file():
        return []
    text = PHP_TAG_RE.sub("", path.read_text(encoding="utf-8", errors="replace"))
    hits: list[str] = []
    for block in INLINE_SCRIPT_RE.findall(text):
        if not block.strip():
            continue
        if MODULE_SCOPED_RE.search(block):
            hits.append(
                f"{page}  inline <script> calls $() -- that helper is "
                f"module-scoped and undefined on the page, so the render throws "
                f"ReferenceError. Move the script into "
                f"assets/js/{page[:-4]}.js and register it via $pageScripts."
            )
    return hits


def destructive_writes(js: str) -> list[tuple[int, str]]:
    """Lines that remove or replace the element behind a $('[data-x]') hook."""
    hits: list[tuple[int, str]] = []
    for i, line in enumerate(js.splitlines(), start=1):
        for m in re.finditer(
            r"""\$\(\s*['"]\[data-([a-z0-9-]+)\][^)]*\)"""
            r"""\s*(?:\?)?\s*\.\s*(\w+)""",
            line,
        ):
            if m.group(2) in DESTRUCTIVE:
                hits.append((i, m.group(0)))
    return hits


def main() -> int:
    problems: list[str] = []
    mapping = page_script_map()

    for name in unregistered_scripts(mapping):
        problems.append(
            f"assets/js/{name}  is not in any page's $pageScripts, so this "
            f"checker cannot verify it. Register it or delete it."
        )

    for path in sorted(JS_DIR.glob("*.js")):
        js = path.read_text(encoding="utf-8", errors="replace")
        name = path.name

        for lineno, snippet in destructive_writes(js):
            problems.append(
                f"{name}:{lineno}  {snippet}  -- replaces the element, so the "
                f"hook is gone for the next call. Use .innerHTML instead."
            )

        page = mapping.get(name)
        if page is None:
            continue
        page_path = ROOT / page
        if not page_path.is_file():
            problems.append(f"{name}  is registered by missing page {page}")
            continue
        markup = page_path.read_text(encoding="utf-8", errors="replace")

        for hook in sorted(hooks_written_to(js)):
            if not re.search(rf"data-{re.escape(hook)}\b", markup):
                problems.append(
                    f"{name}  writes to [data-{hook}] but {page} never "
                    f"renders it."
                )

    for page in sorted(set(mapping.values())):
        problems.extend(inline_js_problems(page))

    if problems:
        print(f"{len(problems)} browser-layer problem(s):\n")
        for p in problems:
            print(f"  {p}")
        print("\nFAIL - fix the items above")
        return 1

    print(f"OK - {len(mapping)} page module(s) registered, no destructive hook "
          f"writes, every data-* hook exists in its page, no inline JS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
