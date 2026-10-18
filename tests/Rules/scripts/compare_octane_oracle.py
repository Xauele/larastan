#!/usr/bin/env python3
"""Independent, narrow oracle comparing PHPStan diagnostics to Laravel runtime probes.

Do not turn missed warnings into success by ignoring the exit status.
Classify coverage gaps separately from policy disagreements.
"""
import json
import re
from collections import Counter
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text())
laravel_major = int(sys.argv[2].lstrip("^").split(".")[0])
reported = set()
other = []
unsupported_attributes = []
for file_name, details in payload.get("files", {}).items():
    for m in details.get("messages", []):
        message = m.get("message", "")
        match = re.search(r"^Singleton OctaneExtendedLifetimeCases\\([A-Za-z0-9_]+) has a constructor dependency on a scoped service:", message)
        if match:
            reported.add(match.group(1))
        elif laravel_major == 11 and message in {
            "Attribute class Illuminate\\Container\\Attributes\\Scoped does not exist.",
            "Attribute class Illuminate\\Container\\Attributes\\Singleton does not exist.",
        } and file_name.endswith("/OctaneExtendedLifetimeCases.php"):
            # Verify exactly which unsupported native attributes PHPStan reports;
            # Laravel 11 cannot provide these runtime semantics.
            unsupported_attributes.append(message)
        else:
            other.append({"file": file_name, "message": message})
other.extend({"general": m} for m in payload.get("errors", []))
if laravel_major == 11:
    expected_unsupported = Counter({
        "Attribute class Illuminate\\Container\\Attributes\\Scoped does not exist.": 2,
        "Attribute class Illuminate\\Container\\Attributes\\Singleton does not exist.": 2,
    })
    assert Counter(unsupported_attributes) == expected_unsupported, (
        "Laravel 11 fixture native attribute diagnostics changed: "
        + json.dumps(unsupported_attributes)
    )
assert not other, "Unexpected baseline analyzer errors: " + json.dumps(other, indent=2)

# These are independently backed by real Container + Request boundary tests.
definitely_captive = {
    "ProviderSharedOwner",
    "OptionalBoundConsumer",
    "InterfaceOnlyOwner",
    "BaselineNativeSingleton",
}
# BaselineNativeSingleton is also registered explicitly through singleton()
# on Laravel 11: this is a real captive lifetime even without the attribute.
if laravel_major >= 12:
    definitely_captive.add("AttributeOwnerOverriddenToTransient")
definitely_not_captive_under_selected_binding = {
    "OwnerWithExplicitlySharedDependency",
    "BaselineTransient",
}
if laravel_major == 11:
    definitely_not_captive_under_selected_binding.update({
        "AttributeOwnerOverriddenToTransient",
        "ConstructorOnlyOwner",
    })
# Expected by the documented conservative rule, even though it never
# stores the request and therefore cannot leak it through a property.
policy_only_potential = {"ConstructorOnlyOwner"} if laravel_major >= 12 else set()

result = {
    "reported": sorted(reported),
    "expected_unavailable_native_attributes": sorted(unsupported_attributes),
    "missed_true_captures": sorted(definitely_captive - reported),
    "binding_override_warnings": sorted(definitely_not_captive_under_selected_binding & reported),
    "warnings_on_constructor_only_nonretention": sorted(policy_only_potential & reported),
    "passing_positive_controls": sorted(reported & {"BaselineNativeSingleton"}),
    "passing_negative_controls": sorted({"BaselineTransient"} - reported),
}
print("ORACLE_JSON", json.dumps(result, sort_keys=True))
if result["missed_true_captures"]:
    print("GATE_FAIL: real long-lived captures missed by static analysis", file=sys.stderr)
    sys.exit(1)
if result["binding_override_warnings"]:
    print("GATE_FAIL: selected runtime binding does not have assumed lifetime", file=sys.stderr)
    sys.exit(1)
print("GATE_PASS: complete for this explicitly modeled slice")
