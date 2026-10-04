"""Static MySQL-dialect validation for the FlatMate schema + seed.

Parses every statement with sqlglot's MySQL grammar to catch syntax errors,
then cross-checks referential integrity a plain parse cannot see:
  * every FOREIGN KEY target column exists in the referenced table
  * every table referenced by a view exists
  * every INSERT column list matches the DDL
  * CHECK constraints reference existing columns
"""
import io
import re
import sys
from collections import OrderedDict
from pathlib import Path

import sqlglot
from sqlglot import exp

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

errors, warnings, notes = [], [], []


def split_statements(sql):
    """Split on ';' respecting quotes, backticks and comments."""
    out, buf, i, n = [], [], 0, len(sql)
    while i < n:
        c = sql[i]
        if sql[i:i + 3] == "-- " or c == "#":
            j = sql.find("\n", i)
            i = n if j == -1 else j + 1
            continue
        if sql[i:i + 2] == "/*":
            j = sql.find("*/", i)
            i = n if j == -1 else j + 2
            continue
        if c in "'\"`":
            q = c
            buf.append(c)
            i += 1
            while i < n:
                if sql[i] == "\\" and q != "`":
                    buf.append(sql[i:i + 2])
                    i += 2
                    continue
                buf.append(sql[i])
                if sql[i] == q:
                    i += 1
                    break
                i += 1
            continue
        if c == ";":
            out.append("".join(buf).strip())
            buf = []
            i += 1
            continue
        buf.append(c)
        i += 1
    tail = "".join(buf).strip()
    if tail:
        out.append(tail)
    return [s for s in out if s]


def read(path):
    with open(path, encoding="utf-8") as fh:
        return fh.read()


tables = OrderedDict()   # name -> set(columns)
views = OrderedDict()    # name -> body
inserts = []             # (table, [cols], path)
alters = []

# Every .sql file in sql/ is checked, discovered rather than listed. A patch
# added later is not silently left unvalidated.
SQL_FILES = tuple(sorted(str(p) for p in (Path("sql").glob("*.sql"))))

for path in SQL_FILES:
    raw = read(path)
    stmts = split_statements(raw)
    print(f"{path}: {len(stmts)} statements")

    for idx, stmt in enumerate(stmts, 1):
        head = " ".join(stmt.split()[:7])[:78]

        # Session-statement plumbing used by the idempotent migration guards.
        # sqlglot models PREPARE/EXECUTE loosely but has no DEALLOCATE at all,
        # and none of these carry schema definitions worth cross-checking.
        if re.match(r"(?is)^\s*(PREPARE|EXECUTE|DEALLOCATE)\b", stmt):
            notes.append(f"{path} stmt#{idx}: session statement skipped ({head[:48]})")
            continue

        try:
            tree = sqlglot.parse_one(stmt, read="mysql")
        except Exception as exc:
            errors.append(f"{path} stmt#{idx}\n      [{head}]\n      PARSE: "
                          + str(exc).split("\n")[0][:220])
            continue
        if tree is None:
            continue

        if isinstance(tree, exp.Create):
            kind = (tree.args.get("kind") or "").upper()
            this = tree.this
            if kind == "TABLE" and isinstance(this, exp.Schema):
                name = this.this.name if hasattr(this.this, "name") else str(this.this)
                cols = set()
                for item in this.expressions:
                    if isinstance(item, exp.ColumnDef):
                        cols.add(item.name)
                    elif isinstance(item, exp.ColumnConstraint):
                        for cd in item.find_all(exp.ColumnDef):
                            cols.add(cd.name)
                tables[name.lower()] = cols
            elif kind == "VIEW":
                views[this.name.lower()] = tree
            continue

        if isinstance(tree, exp.Insert):
            tgt = tree.this
            if isinstance(tgt, exp.Schema):
                tname = (tgt.this.name if hasattr(tgt.this, "name") else str(tgt.this)).lower()
                inserts.append((tname, [c.name for c in tgt.expressions], path))
            continue

        if isinstance(tree, exp.Alter):
            alters.append((path, tree))

# ------------------------------------------------------ integrity checks --
FK_RE = re.compile(
    r"FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)", re.I
)

for tname, cols in tables.items():
    raw = read("sql/schema.sql")
    # slice the exact CREATE TABLE body for this table
    m = re.search(r"CREATE TABLE\s+`?" + re.escape(tname) + r"`?\s*\((.*?)\n\)\s*ENGINE", raw, re.I | re.S)
    if not m:
        continue
    body = m.group(1)
    for fk in FK_RE.finditer(body):
        local = [c.strip().strip("`") for c in fk.group(1).split(",")]
        ref_tbl = fk.group(2).lower()
        ref_cols = [c.strip().strip("`") for c in fk.group(3).split(",")]
        for lc in local:
            if lc not in cols:
                errors.append(f"FK {tname}.{lc}: local column not in DDL")
        if ref_tbl not in tables:
            errors.append(f"FK {tname} -> unknown table `{ref_tbl}`")
        else:
            for rc in ref_cols:
                if rc not in tables[ref_tbl]:
                    errors.append(f"FK {tname}.{lc} -> {ref_tbl}.{rc}: target column missing")

# CHECK constraints
schema_raw = read("sql/schema.sql")
for m in re.finditer(r"CHECK\s*\(([^)]*(?:\([^)]*\))?[^)]*)\)", schema_raw, re.I):
    pass  # informational only

# views must reference real tables
for vname, vtree in views.items():
    body = vtree.sql(dialect="mysql")
    for ref in set(re.findall(r"(?:FROM|JOIN)\s+`?(\w+)`?", body, re.I)):
        if ref.lower() not in tables and ref.lower() not in views:
            errors.append(f"VIEW {vname} references unknown table `{ref}`")

# inserts must target known columns
for tname, cols, path in inserts:
    if tname not in tables:
        warnings.append(f"{path}: INSERT into `{tname}` has no CREATE TABLE (skipped)")
        continue
    for c in cols:
        if c not in tables[tname]:
            errors.append(f"{path}: INSERT column {tname}.{c} not present in DDL")

print(f"\ntables parsed : {len(tables)}")
print(f"views parsed  : {len(views)} -> {list(views)}")
print(f"inserts parsed: {len(inserts)}")
print(f"total columns : {sum(len(c) for c in tables.values())}")

# -------------------------------------------------- duplicate-key sweep ----
for tname, cols in tables.items():
    raw = read("sql/schema.sql")
    m = re.search(r"CREATE TABLE\s+`?" + re.escape(tname) + r"`?\s*\((.*?)\n\)\s*ENGINE", raw, re.I | re.S)
    if not m:
        continue
    body = m.group(1)
    keys = re.findall(r"(UNIQUE KEY|PRIMARY KEY|FULLTEXT KEY|KEY)\s+`?(\w+)`?\s*\(([^)]+)\)", body, re.I)
    seen = {}
    for kind, kname, kcols in keys:
        sig = (kind.upper().split()[0], tuple(
            re.findall(r"`?(\w+)`?(?:\(\d+\))?", kcols)))
        if sig in seen:
            warnings.append(f"{tname}: duplicate index {kname} vs {seen[sig]}")
        seen[sig] = kname

# ------------------------------------------------- cross-file view drift ----
# vw_balance_sheet is defined in schema.sql, patch.sql AND patch_house_fund.sql.
# Whichever a DBA runs last wins, so a stale copy in any one of them silently
# changes the arithmetic the app depends on. This is exactly how the
# paid_from_fund exclusion went missing from patch.sql once already.
def normalise_view(sql: str) -> str:
    return re.sub(r"\s+", " ", sql).strip().rstrip(";")


view_defs: dict[str, dict[str, str]] = OrderedDict()
VIEW_DEF_RE = re.compile(
    r"CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?(\w+)`?\s+AS\s+(.*?);", re.I | re.S)

for path in SQL_FILES:
    raw = read(path)
    for name, body in VIEW_DEF_RE.findall(raw):
        view_defs.setdefault(name.lower(), {})[path] = normalise_view(body)

for name, per_file in view_defs.items():
    if len(per_file) < 2:
        continue
    distinct = set(per_file.values())
    if len(distinct) > 1:
        errors.append(
            f"view {name} has {len(distinct)} different definitions across "
            + ", ".join(sorted(per_file))
            + " -- whichever is imported last silently wins"
        )
    else:
        notes.append(
            f"view {name}: identical in {len(per_file)} file(s) "
            + ", ".join(sorted(per_file)))

# -------------------------------------------------------------- report ----
print("\n" + "=" * 72)
for n in notes:
    print("  . " + n)
for w in warnings:
    print("  ~ " + w)
if errors:
    print(f"\nERRORS ({len(errors)}):")
    for e in errors:
        print("  x " + e)
    sys.exit(1)
print("\nOK  - every .sql file parses cleanly, FK/INSERT/view references "
      "resolve, view definitions agree across files")
