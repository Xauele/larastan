#!/usr/bin/env python3
"""Exact positive/negative graph diagnostic contract with named bounded gaps."""
import json
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
oracle = json.loads(Path(sys.argv[2]).read_text())
payload = json.loads(path.read_text())
actual = set()
unexpected = []
for name, file_data in payload.get("files", {}).items():
    for item in file_data.get("messages", []):
        message = item.get("message", "")
        hit = re.search(
            r"^Singleton OctaneGeneratedGraphStress\\([A-Za-z0-9_]+) has a constructor dependency on a scoped service:",
            message,
        )
        if hit:
            actual.add(hit.group(1))
        else:
            unexpected.append({"path": name, "message": message})
unexpected += [{"general": item} for item in payload.get("errors", [])]
expected = set(oracle["expected"])
bounded = set(oracle["known_bounded_misses"])
summary = {
    "registered": oracle["total_registrations"],
    "expected": len(expected),
    "actual": len(actual),
    "missed_supported": sorted(expected - actual),
    "unexpected_false_alarms": sorted(actual - expected),
    "known_limit_below_detection": sorted(bounded - actual),
    "other_phpstan_errors": unexpected,
}
print("GRAPH_ORACLE_JSON", json.dumps(summary, sort_keys=True))
if summary["missed_supported"] or summary["unexpected_false_alarms"] or unexpected:
    sys.exit(1)
print("GRAPH_ORACLE_PASS")
