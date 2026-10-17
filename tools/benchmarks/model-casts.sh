#!/usr/bin/env bash
# Synthetic comparison of the same PHPStan corpus with casts parsing off/on.
# Captures both cold and warm runs, not a production performance claim.
set -euo pipefail

model_count=180
iterations=3
if (( $# >= 1 )); then model_count=$1; fi
if (( $# >= 2 )); then iterations=$2; fi
if ! [[ $model_count =~ ^[1-9][0-9]*$ && $iterations =~ ^[1-9][0-9]*$ ]]; then
    echo "Usage: bash tools/benchmarks/model-casts.sh [positive-count] [positive-iterations]" >&2
    exit 2
fi

root=$(cd "$(dirname "$0")/../.." && pwd)
cd "$root"
workdir=$(mktemp -d "$root/.casts-bench-XXXXXX")
trap 'rm -rf "$workdir"' EXIT
mkdir -p "$workdir/cases" "$root/.casts-bench-results"

cat > "$workdir/bootstrap.php" <<'PHP'
<?php

declare(strict_types=1);

foreach (glob(__DIR__ . '/cases/*.php') as $path) {
    require_once $path;
}
PHP

for i in $(seq 1 "$model_count"); do
    padded=$(printf '%04d' "$i")
    cat > "$workdir/cases/CastModel$padded.php" <<PHP
<?php

declare(strict_types=1);

namespace CastBenchmark;

use Illuminate\Database\Eloquent\Model;

final class CastModel$padded extends Model
{
    protected \$table = 'model_with_casts';

    protected function casts(): array
    {
        return ['integer' => 'bool', 'string' => 'integer'];
    }
}

function analyze$padded(CastModel$padded \$model): mixed
{
    return [\$model->integer, \$model->string];
}
PHP
done

for mode in off on; do
    if [[ $mode == on ]]; then enabled=true; else enabled=false; fi
    mkdir -p "$workdir/cache-$mode"
    cat > "$workdir/config-$mode.neon" <<NEON
includes:
    - $root/tests/phpstan-tests.neon

parameters:
    level: 6
    parseModelCastsMethod: $enabled
    reportUnmatchedIgnoredErrors: false
    bootstrapFiles:
        - $root/bootstrap.php
        - $root/tests/bootstrap.php
        - $workdir/bootstrap.php
    databaseMigrationsPath:
        - $root/tests/application/database/migrations
    paths:
        - $workdir/cases
    tmpDir: $workdir/cache-$mode
NEON
done

report="$root/.casts-bench-results/measurements.tsv"
printf 'mode\titeration\telapsed_seconds\tmax_rss_kib\n' > "$report"
printf 'Commit: %s\n' "$(git rev-parse HEAD)"
printf 'Models: %s; iterations: %s (first per mode is cold)\n' "$model_count" "$iterations"
php -v | head -1
php vendor/bin/phpstan --version
for iteration in $(seq 1 "$iterations"); do
    if (( iteration % 2 == 1 )); then
        order="off on"
    else
        order="on off"
    fi
    for mode in $order; do
        printf 'Benchmark mode=%s iteration=%s\n' "$mode" "$iteration"
        logfile="$root/.casts-bench-results/$mode-$iteration.log"
        timing="$workdir/timing"
        if ! /usr/bin/time -f '%e %M' -o "$timing" php vendor/bin/phpstan analyse \
            --configuration="$workdir/config-$mode.neon" \
            --memory-limit=1024M --no-progress --error-format=raw > "$logfile" 2>&1; then
            cat "$logfile" >&2
            echo "Benchmark failed for $mode iteration $iteration; no performance claim." >&2
            exit 1
        fi
        read -r elapsed rss < "$timing"
        printf '%s\t%s\t%s\t%s\n' "$mode" "$iteration" "$elapsed" "$rss" >> "$report"
        printf '  %s seconds; peak RSS %s KiB\n' "$elapsed" "$rss"
    done
done
echo "Raw benchmark results:"
cat "$report"
echo "Synthetic CI-runner comparison only; production workloads require separate evidence."
