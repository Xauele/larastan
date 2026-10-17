# Native model casts inference benchmark (fork-only)

The benchmark is designed to expose a large unexpected overhead before proposing
`parseModelCastsMethod: true` as Larastan's default. It is **not** a release
performance guarantee, and does not execute analysed model methods.

## Reproduce

With Composer dependencies installed in the fork:

```bash
bash tools/benchmarks/model-casts.sh 180 3
```

The script generates the same 180 Eloquent model classes for both configurations,
using the same installed PHP, Laravel, PHPStan and source revision. It runs PHPStan
with source parsing disabled and enabled, alternating execution order, with
separate caches. The first iteration is cold per mode; the next two are warm.
It records elapsed wall-clock seconds and peak resident memory in KiB.
On GitHub Actions the raw TSV and logs are uploaded as a run artifact.

## Recorded initial GitHub Actions result

- Fork PR #3 candidate: `557be8d29ce3801194288dbea95ffd6b84140bdf`.
- Run ID: `38979985250`; pull-request test checkout: `7a515f34f756d2f9070a48eac6b7246a00202e57`.
- PHP 8.4, PHPStan 2.3.1, Laravel 13 compatible dependency selection.
- 180 generated Eloquent models, three iterations.

| Mode | Cold seconds / RSS KiB | Warm 1 seconds / RSS KiB | Warm 2 seconds / RSS KiB |
| --- | --- | --- | --- |
| Off | 3.50 / 242508 | 1.06 / 166328 | 1.05 / 166484 |
| On | 3.59 / 242200 | 1.09 / 166180 | 1.06 / 165796 |

These are raw measurements from **one** hosted runner. A few hundredths of a
second is noise, not statistically significant evidence of equal performance.
The workflow originally used a hidden artifact directory without enabling
hidden-file upload; the raw data remain in the cited job log. Subsequent runs
upload the TSV directly.

## Release gate not yet satisfied

Before upstream default-on adoption, independently benchmark at least one
representative Laravel application with hundreds of *real* models, relationships,
casts, and a representative PHPStan baseline. Compare cold and warm performance,
resident memory, diagnostic counts and any newly reachable code. Repeat with a
fixed dependency lock and hardware, and inspect performance outliers and
backwards compatibility. Do not claim improved speed or zero regressions from
this synthetic measurement.
