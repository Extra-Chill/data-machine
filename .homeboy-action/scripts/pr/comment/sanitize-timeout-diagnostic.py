#!/usr/bin/env python3

"""Produce a bounded, publication-safe timeout diagnostic."""

import re
import sys


# Read at most this much from the pipe. The caller also limits jq output before
# this process starts, so a pathological stderr tail never becomes an unbounded
# in-memory publication path.
MAX_INPUT = 16 * 1024
text = sys.stdin.buffer.read(MAX_INPUT).decode("utf-8", "replace")

# Header values can contain credentials in formats that are not token-shaped.
text = re.sub(
    r"(?im)^\s*(authorization|proxy-authorization|cookie|set-cookie)\s*:\s*.*(?:\n[ \t]+.*)*",
    lambda match: f"{match.group(1)}: [REDACTED]",
    text,
)
text = re.sub(
    r"-----BEGIN [^-\r\n]*PRIVATE KEY-----[\s\S]*?(?:-----END [^-\r\n]*PRIVATE KEY-----|$)",
    "[REDACTED PRIVATE KEY]",
    text,
)
text = re.sub(
    r"(?i)\b(?:authorization|proxy-authorization)\s*:\s*(?:[a-z-]+\s+)?[^\s,;]+",
    "authorization: [REDACTED]",
    text,
)
text = re.sub(r"(?i)([a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@", r"\1[REDACTED]@", text)

# Preserve the option name so a reader can identify the failing invocation,
# while removing values in every safely recognizable secret-flag spelling.
secret_value = r"(?:\"[^\"]*\"|'[^']*'|[^\s]+)"
text = re.sub(
    rf"(?i)(--(?=[a-z0-9_-]*(?:token|password|secret|api[_-]?key|private[_-]?key|credential))[a-z0-9][a-z0-9_-]*)(?:\s*=\s*|\s+){secret_value}",
    lambda match: f"{match.group(1)} [REDACTED]",
    text,
)
text = re.sub(
    rf"(?<![a-z0-9_-])(-[tTkK])(?:\s*=\s*|\s+){secret_value}",
    lambda match: f"{match.group(1)} [REDACTED]",
    text,
)
text = re.sub(
    r"(?i)\b((?:api[_-]?key|token|secret|password|private[_-]?key|credential))\s*[:=]\s*(?:\r?\n\s*)?(?:\"[^\"]*\"|'[^']*'|[^\s,;]+)",
    lambda match: f"{match.group(1)}=[REDACTED]",
    text,
)
text = re.sub(
    r"(?i)\b([a-z_][a-z0-9_]*(?:token|secret|password|api[_-]?key|private[_-]?key|credential)[a-z0-9_]*)\s*[:=]\s*(?:\r?\n\s*)?(?:\"[^\"]*\"|'[^']*'|[^\s,;]+)",
    lambda match: f"{match.group(1)}=[REDACTED]",
    text,
)
text = re.sub(r"\b(?:gh[pousr]_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+|AKIA[0-9A-Z]{16})\b", "[REDACTED]", text)
text = re.sub(r"\b(?:xox[baprs]-[A-Za-z0-9-]+|sk-[A-Za-z0-9]{16,})\b", "[REDACTED]", text)

# A single line prevents Markdown/header injection and keeps the visible check
# diagnostic bounded even when a command emitted an unbounded stderr tail.
text = " ".join(text.split())
print(text[:480])
