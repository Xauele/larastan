#!/usr/bin/env python3
"""Require semantic RED for four native PHPStan/Laravel regression mutants.

Run PHPStan's real CLI on an isolated fixture: PHPStan's class.nameCase errors
are part of the exact oracle, rather than invisibly suppressed by RuleTestCase.
A crash or parse error is never counted as a killed mutant.
"""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[3]
detector = root / "src/Rules/Octane/ScopedDependencyDetector.php"
binding = root / "src/Rules/Octane/SingletonBindingScopeRule.php"
depth_test = "tests/Rules/SingletonBindingScopeRuleTest.php"


def run(*args):
    return subprocess.run(args, cwd=root, text=True, capture_output=True, check=False)


def cli_result():
    result = run(
        "php", "vendor/bin/phpstan", "analyse",
        "-c", "tests/octane-cli-audit.neon",
        "--error-format=json", "--no-progress", "--memory-limit=3G",
    )
    if result.returncode != 1:
        raise RuntimeError(
            f"PHPStan unexpected exit {result.returncode}: {result.stderr[-1400:]}"
        )
    try:
        json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError("PHPStan did not return parseable JSON") from exc
    with tempfile.TemporaryDirectory() as temp:
        output = Path(temp) / "phpstan.json"
        output.write_text(result.stdout)
        verdict = run(
            sys.executable,
            "tests/Rules/scripts/compare_octane_cli.py",
            str(output),
        )
    return verdict


def ensure_baselines():
    baseline = cli_result()
    if baseline.returncode != 0:
        raise RuntimeError("CLI baseline RED before mutation: " + baseline.stdout[-2800:])
    phpunit = run("php", "vendor/bin/phpunit", depth_test, "--colors=never")
    if phpunit.returncode != 0:
        raise RuntimeError("Depth baseline RED before mutation: " + phpunit.stdout[-2400:])
    print("MUTATION_BASELINE_PASS: real PHPStan CLI and deep-chain PHPUnit")


def kill(name, path, before, after, mode):
    original = path.read_text()
    if original.count(before) != 1:
        raise RuntimeError(
            f"{name}: expected exactly one mutation anchor, got {original.count(before)}"
        )
    try:
        path.write_text(original.replace(before, after, 1))
        syntax = run("php", "-l", str(path))
        if syntax.returncode != 0:
            raise RuntimeError(name + ": syntax invalid (not a valid semantic kill)")
        if mode == "cli":
            verdict = cli_result()
            if verdict.returncode != 1 or '"missed": []' in verdict.stdout:
                raise RuntimeError(
                    name + ": CLI mutation did not produce a missed-diagnostic RED: "
                    + verdict.stdout[-2600:]
                )
        elif mode == "depth":
            verdict = run("php", "vendor/bin/phpunit", depth_test, "--colors=never")
            if verdict.returncode != 1 or "Failed asserting that two strings are identical." not in verdict.stdout:
                raise RuntimeError(name + ": depth mutation not rejected semantically: " + verdict.stdout[-2000:])
        else:
            raise ValueError(mode)
        print("MUTANT_KILLED", name, "oracle", mode)
    finally:
        path.write_text(original)


ensure_baselines()

original_detector = detector.read_text()
original_binding = binding.read_text()
kill(
    "disable-native-attribute-detection", detector,
    "if (strcasecmp($reflectionAttribute->getName(), $attribute) === 0) {",
    "if (false) {", "cli",
)
kill(
    "regress-case-insensitive-native-attribute-policy", detector,
    "strcasecmp($reflectionAttribute->getName(), $attribute) === 0",
    "$reflectionAttribute->getName() === $attribute", "cli",
)
kill(
    "disable-shared-bind-inference", binding,
    "return $shared instanceof Node\\Expr\\ConstFetch\n            && strtolower($shared->name->toString()) === 'true';",
    "return false;", "cli",
)
kill(
    "disable-transitive-graph-traversal", detector,
    "private const MAX_DEPTH = 8;",
    "private const MAX_DEPTH = 1;", "depth",
)
if detector.read_text() != original_detector or binding.read_text() != original_binding:
    raise RuntimeError("Mutated repository files were not restored")
print("MUTATION_GATE_PASS: 4/4 independent semantic regressions detected and restored")
