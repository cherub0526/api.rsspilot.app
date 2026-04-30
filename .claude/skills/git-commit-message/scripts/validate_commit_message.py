#!/usr/bin/env python3
"""
Validate a git commit message against this project's conventions.

Usage:
    python3 validate_commit_message.py <message-file>
    python3 validate_commit_message.py -            # read from stdin

Exit code:
    0 — all checks pass
    1 — validation failures (printed to stderr)
    2 — invocation / IO error
"""
from __future__ import annotations

import argparse
import re
import sys
import unicodedata
from pathlib import Path

ALLOWED_TYPES = {
    "feat", "fix", "docs", "style", "refactor",
    "perf", "test", "chore", "revert",
}
SUBJECT_MAX_COLS = 50
BODY_MAX_COLS = 72
HEADER_RE = re.compile(
    r"^(?P<type>[a-z]+)(?:\((?P<scope>[^)]+)\))?: (?P<subject>.+)$"
)
REVERT_HEADER_RE = re.compile(
    r"^revert: [a-z]+(?:\([^)]+\))?: .+ \(回覆版本：[0-9a-f]{4,40}\)$"
)
TRAILING_PUNCT = ".。!?！？"
SENSITIVE_HINTS = re.compile(
    r"(?i)(password|secret|token|api[_-]?key|private[_-]?key|-----BEGIN )"
)


def display_width(s: str) -> int:
    """Count East-Asian display width. Full-width CJK chars count as 2."""
    total = 0
    for ch in s:
        if unicodedata.east_asian_width(ch) in ("F", "W"):
            total += 2
        elif unicodedata.category(ch).startswith("C"):
            total += 0
        else:
            total += 1
    return total


def read_source(path: str) -> str:
    if path == "-":
        return sys.stdin.read()
    return Path(path).read_text(encoding="utf-8")


def validate(message: str) -> list[str]:
    errors: list[str] = []
    lines = message.rstrip("\n").splitlines()

    if not lines:
        return ["empty commit message"]

    header = lines[0]

    if header.startswith("revert:"):
        if not REVERT_HEADER_RE.match(header):
            errors.append(
                "revert header must match: "
                "`revert: type(scope): subject (回覆版本：<hash>)`"
            )
    else:
        m = HEADER_RE.match(header)
        if not m:
            errors.append(
                f"header must match `<type>(<scope>): <subject>`; got: {header!r}"
            )
        else:
            t = m.group("type")
            subject = m.group("subject")
            if t not in ALLOWED_TYPES:
                errors.append(
                    f"type {t!r} not in whitelist: {sorted(ALLOWED_TYPES)}"
                )
            if subject and subject[-1] in TRAILING_PUNCT:
                errors.append(
                    f"subject must not end with punctuation ({subject[-1]!r})"
                )
            w = display_width(header)
            if w > SUBJECT_MAX_COLS:
                errors.append(
                    f"subject line is {w} display cols > {SUBJECT_MAX_COLS}"
                )

    if len(lines) >= 2 and lines[1].strip() != "":
        errors.append("line 2 must be empty (separator between header and body)")

    for idx, line in enumerate(lines[2:], start=3):
        w = display_width(line)
        if w > BODY_MAX_COLS:
            errors.append(
                f"line {idx} is {w} display cols > {BODY_MAX_COLS}: {line!r}"
            )

    has_coauthor = any(
        "Co-Authored-By:" in line for line in lines[-5:]
    )
    if not has_coauthor:
        errors.append(
            "footer missing `Co-Authored-By:` trailer in last 5 lines"
        )

    for line in lines:
        if SENSITIVE_HINTS.search(line):
            errors.append(
                f"possible secret in commit message: {line!r}"
            )
            break

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "path",
        help="path to a file containing the commit message, or '-' for stdin",
    )
    args = parser.parse_args()

    try:
        message = read_source(args.path)
    except OSError as e:
        print(f"error reading {args.path}: {e}", file=sys.stderr)
        return 2

    errors = validate(message)
    if errors:
        print("FAIL", file=sys.stderr)
        for e in errors:
            print(f"  - {e}", file=sys.stderr)
        return 1

    print("PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())
