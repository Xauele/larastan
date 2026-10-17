#!/usr/bin/env python3
"""Kill known semantic mutants; validate that regression tests are sensitive.

Mutations happen only inside the ephemeral CI checkout and are restored in
finally blocks. Syntax errors or PHP crashes do NOT count as a killed mutant.
"""
from pathlib import Path
import subprocess
import sys

root = Path(__file__).resolve().parents[3]
detector = root / "src/Rules/Octane/ScopedDependencyDetector.php"
binding = root / "src/Rules/Octane/SingletonBindingScopeRule.php"
attr_test = "tests/Rules/OctaneAdversarialAttributeCaseRuleTest.php"
bind_test = "tests/Rules/OctaneAdversarialBindingRuleTest.php"
depth_test = "tests/Rules/SingletonBindingScopeRuleTest.php"


def run(*args):
    return subprocess.run(args, cwd=root, text=True, capture_output=True, check=False)


def ensure_baseline(test_file):
    r = run("php", "vendor/bin/phpunit", test_file, "--colors=never")
    if r.returncode != 0:
        print("BASELINE_FAILED", test_file, r.stdout[-3000:], r.stderr[-1000:])
        raise RuntimeError("baseline test is not green; cannot perform mutation testing")


def kill(name, path, before, after, test_file):
    original = path.read_text()
    if original.count(before) != 1:
        raise RuntimeError(f"mutation {name}: expected source anchor appears {original.count(before)} times")
    try:
        path.write_text(original.replace(before, after, 1))
        syntax = run("php", "-l", str(path))
        if syntax.returncode != 0:
            raise RuntimeError(f"mutation {name}: syntax invalid; not a valid kill")
        r = run("php", "vendor/bin/phpunit", test_file, "--colors=never")
        mismatch = "Failed asserting that two strings are identical." in r.stdout
        if r.returncode != 1 or not mismatch:
            print("MUTANT_SURVIVED_OR_INVALID", name, "exit", r.returncode, r.stdout[-3500:], r.stderr[-1500:])
            raise RuntimeError(f"mutation {name}: sensitive semantic assertion did not reject mutant")
        print("MUTANT_KILLED", name, "by", test_file)
    finally:
        path.write_text(original)


ensure_baseline(attr_test)
ensure_baseline(bind_test)
ensure_baseline(depth_test)

a = detector.read_text()
b = binding.read_text()
kill(
    "disable-native-attribute-detection",
    detector,
    "return $class->getNativeReflection()->getAttributes($attribute) !== [];",
    "return false;",
    attr_test,
)
kill(
    "regress-to-case-sensitive-attribute-names",
    detector,
    "return $class->getNativeReflection()->getAttributes($attribute) !== [];",
    """foreach ($class->getNativeReflection()->getAttributes() as $item) {
            if ($item->getName() === $attribute) {
                return true;
            }
        }

        return false;""",
    attr_test,
)
kill(
    "disable-shared-bind-discovery",
    binding,
    """return $shared instanceof Node\\Expr\\ConstFetch
            && strtolower($shared->name->toString()) === 'true';""",
    "return false;",
    bind_test,
)
kill(
    "truncate-all-transitive-dependency-chains",
    detector,
    "private const MAX_DEPTH = 8;",
    "private const MAX_DEPTH = 1;",
    depth_test,
)
assert detector.read_text() == a and binding.read_text() == b
print("MUTATION_GATE_PASS: 4/4 intentional semantic regressions rejected; all files restored")
