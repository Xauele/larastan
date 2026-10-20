#!/usr/bin/env python3
"""Deterministic, inspectable pathological constructor graphs for Larastan.

Generators are deliberately small and fixed; no dependency on Faker or
nondeterministic source ordering. The ground truth comes from the chosen graph.
"""
import json
import os
from pathlib import Path

SCALE = int(os.environ.get('OCTANE_GRAPH_SCALE', '1'))
if SCALE not in (1, 2, 4, 8):
    raise ValueError('OCTANE_GRAPH_SCALE must be 1, 2, 4 or 8')

repo = Path(__file__).resolve().parents[3]
fixture = repo / "tests/Rules/data/OctaneGeneratedGraphStress.php"
expected_path = repo / "tests/octane-generated-graph-oracle.json"
lines = [
    "<?php", "", "declare(strict_types=1);", "",
    "namespace OctaneGeneratedGraphStress;", "",
    "use Illuminate\\Contracts\\Container\\Container;",
    "use Illuminate\\Http\\Request;", "",
]
registrations = []
expected = set()
known_bound_gaps = set()

def add(name, dependency=None):
    if dependency is None:
        lines.append(f"final class {name} {{}}")
    else:
        lines.append(f"final class {name} {{ public function __construct(public {dependency} $next) {{}} }}")

def add_root(name, dependency, detected):
    add(name, dependency)
    registrations.append(f"    $app->singleton({name}::class);")
    if detected:
        expected.add(name)

for i in range(200 * SCALE):
    add_root(f"Direct{i:04}", "Request", True)
for i in range(160 * SCALE):
    prefix = f"Chain{i:04}"
    for j in reversed(range(1, 4)):
        add(f"{prefix}Node{j}", "Request" if j == 3 else f"{prefix}Node{j+1}")
    add_root(f"{prefix}Root", f"{prefix}Node1", True)
for i in range(200 * SCALE):
    add_root(f"Safe{i:04}", "\\stdClass", False)
for i in range(100 * SCALE):
    prefix = f"Cycle{i:04}"
    add(f"{prefix}A", f"{prefix}B")
    add(f"{prefix}B", f"{prefix}A")
    add_root(f"{prefix}Root", f"{prefix}A", False)
for i in range(30 * SCALE):
    prefix = f"AtLimit{i:04}"
    for j in reversed(range(1, 7)):
        add(f"{prefix}Node{j}", "Request" if j == 6 else f"{prefix}Node{j+1}")
    add_root(f"{prefix}Root", f"{prefix}Node1", True)
for i in range(30 * SCALE):
    prefix = f"BeyondLimit{i:04}"
    for j in reversed(range(1, 8)):
        add(f"{prefix}Node{j}", "Request" if j == 7 else f"{prefix}Node{j+1}")
    add_root(f"{prefix}Root", f"{prefix}Node1", True)

for i in range(65):
    add(f"WideSafe{i:02}")
add("WideScopedLeaf", "Request")
safe_parameters = [f"public WideSafe{i:02} $safe{i:02}" for i in range(65)]
def add_wide(name, params):
    lines.append("final class " + name + " { public function __construct(" + ", ".join(params) + ") {} }")
    registrations.append(f"    $app->singleton({name}::class);")
add_wide("WideFirstRoot", ["public WideScopedLeaf $tail", *safe_parameters])
add_wide("WideLastRoot", [*safe_parameters, "public WideScopedLeaf $tail"])
expected.add("WideFirstRoot")
expected.add("WideLastRoot")

lines.append("")
lines.append("function registerGraph(Container $app): void {")
lines.extend(registrations)
lines.append("}")
fixture.write_text("\n".join(lines) + "\n")
expected_path.write_text(json.dumps({
    "expected": sorted(expected),
    "known_bounded_misses": sorted(known_bound_gaps),
    "total_registrations": len(registrations),
}, indent=2) + "\n")
print("GRAPH_GENERATOR", json.dumps({
    "registrations": len(registrations),
    "source_lines": len(lines),
    "expected_diagnostics": len(expected),
    "known_limited_paths": len(known_bound_gaps),
}, sort_keys=True))
