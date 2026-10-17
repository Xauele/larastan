#!/usr/bin/env python3
import json
import re
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text())
attr_roots = {
    "MixedCaseSingleton",
    "StandardSingletonMixedCaseScope",
    "MixedCaseSingletonStandardScope",
    "BaselineSingleton",
}
bindings = {
    "SharedViaPositionalBind",
    "SharedViaNamedBind",
    "SharedImplementation",
    "BaselineExplicitSingleton",
    "SharedViaBindIf",
}
seen = []
other = []
case_warnings = set()
expected_case_warnings = {
    (9, r"Class Illuminate\Container\Attributes\Scoped referenced with incorrect case: Illuminate\Container\Attributes\sCoPeD."),
    (10, r"Class Illuminate\Container\Attributes\Singleton referenced with incorrect case: Illuminate\Container\Attributes\sInGlEtOn."),
}
for file_data in payload.get("files", {}).values():
    for error in file_data.get("messages", []):
        msg = error.get("message", "")
        match = re.match(r"^Singleton OctaneAdversarialNativeCases\\([A-Za-z0-9_]+) has a constructor dependency on a scoped service:", msg)
        if match:
            seen.append(match.group(1))
        elif (error.get("line"), error.get("message")) in expected_case_warnings and error.get("identifier") == "class.nameCase":
            case_warnings.add((error.get("line"), error.get("message")))
        else:
            other.append(error)
other.extend(payload.get("errors", []))
expected = attr_roots | bindings
observed = set(seen)
out = {
  "expected": sorted(expected),
  "observed": sorted(observed),
  "missed": sorted(expected - observed),
  "unexpected": sorted(observed - expected),
  "duplicate": sorted({i for i in seen if seen.count(i) > 1}),
  "case_warnings_confirmed": len(case_warnings),
  "missing_expected_case_warnings": sorted([str(x) for x in expected_case_warnings - case_warnings]),
  "other": other,
}
print("CLI_DIFFERENTIAL", json.dumps(out, sort_keys=True))
if out["missed"] or out["unexpected"] or out["duplicate"] or out["missing_expected_case_warnings"] or out["other"]:
    sys.exit(1)
print("CLI_DIFFERENTIAL_PASS")
