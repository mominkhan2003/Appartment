#!/usr/bin/env python3
"""
Check that the browser-side renderers keep their DOM hooks.

Two mistakes are easy to make in the classic-script layer and produce a runtime
TypeError instead of a visible bug at author time:

  1. Writing to a hook with outerHTML. outerHTML REPLACES the element, so the
     hook disappears from the document. The write works once and every later
     call queries null. dashboard.js did exactly this with [data-meal-day], and
     the dashboard threw on its second load.

  2. Writing to a data-* hook that no page actually renders. The write silently
     does nothing at best, and throws at worst.

Both are static properties of the source, so they are checked here rather than
discovered in a browser.

    python tests/check_dom_hooks.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS_DIR = ROOT / "assets" / "js"

# Which page each script is loaded by. Used to confirm a hook exists in the
# markup that page actually renders.
PAGE_FOR_SCRIPT = {
    "dashboard.js": "index.php",
    "expenses.js": "expenses.php",
    "chores.js": "chores.php",
    "meals.js": "meals.php",
    "notices.js": "notices.php",
    "residents.js": "residents.php",
    "roles.js": "roles.php",
    "profile.js": "me.php",
    "data_reset.js": "data_reset.php",
    "diagnostics.js": "diagnostics.php",
}

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

    for path in sorted(JS_DIR.glob("*.js")):
        js = path.read_text(encoding="utf-8", errors="replace")
        name = path.name

        for lineno, snippet in destructive_writes(js):
            problems.append(
                f"{name}:{lineno}  {snippet}  -- replaces the element, so the "
                f"hook is gone for the next call. Use .innerHTML instead."
            )

        page = PAGE_FOR_SCRIPT.get(name)
        if page is None:
            continue
        page_path = ROOT / page
        if not page_path.is_file():
            continue
        markup = page_path.read_text(encoding="utf-8", errors="replace")

        for hook in sorted(hooks_written_to(js)):
            if not re.search(rf"data-{re.escape(hook)}\b", markup):
                problems.append(
                    f"{name}  writes to [data-{hook}] but {page} never "
                    f"renders it."
                )

    if problems:
        print(f"{len(problems)} DOM hook problem(s):\n")
        for p in problems:
            print(f"  {p}")
        print("\nFAIL - fix the hooks above")
        return 1

    print("OK - every data-* hook is written with innerHTML/textContent "
          "and exists in its page")
    return 0


if __name__ == "__main__":
    sys.exit(main())
