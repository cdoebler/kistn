#!/bin/sh
# pest --mutate is flaky in this environment: identical invocations sometimes report
# "0 Mutations for 0 Files created" instead of the real ~1300 for this scope (coverage
# collection race, not a code signal). Retrying converts a silent false-pass into either
# a real result or a loud failure, so CI can't flip red/green with no code change.
#
# Calls pest directly: `php artisan test` treats --min as its own coverage option and
# silently drops it, so the mutation score would never be enforced.
#
# MIN_SCORE is a ratchet: baseline 74.55% on 2026-09-15, identical survivor sets across
# repeated runs. Raise it as the score improves; never lower it to make a change pass.
set -u

CLASS_SCOPE="App\\Ecosystems,App\\Services,App\\ValueObjects,App\\Models"
MIN_SCORE=74
MAX_ATTEMPTS=3

i=1
while [ "$i" -le "$MAX_ATTEMPTS" ]; do
    STATUS=0
    OUTPUT=$(vendor/bin/pest --mutate --parallel --covered-only --exclude-testsuite=Browser --exclude-group=network --class="$CLASS_SCOPE" --min="$MIN_SCORE" 2>&1) || STATUS=$?
    echo "$OUTPUT"

    if echo "$OUTPUT" | grep -q "0 Mutations for 0 Files created"; then
        echo ""
        echo "Attempt $i/$MAX_ATTEMPTS: mutation testing reported zero mutations for a known non-empty scope — tool flake, retrying..."
        i=$((i + 1))
        continue
    fi

    exit "$STATUS"
done

echo ""
echo "Mutation testing failed to produce real results after $MAX_ATTEMPTS attempts — failing the build."
exit 1
