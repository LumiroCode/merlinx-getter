#!/usr/bin/env bash

script_dir="$(cd "$(dirname "$0")" && pwd)"
project_root="$(cd "$script_dir/.." && pwd)"
tests_dir="$project_root/tests"

show_pass=false
show_skip=false

for arg in "$@"; do
    case "$arg" in
        --show-pass|--show-success) show_pass=true ;;
        --show-skip) show_skip=true ;;
    esac
done

echo "Tests suite start at $(date)"

failed=0
passed=0
failed_files=()

# If there are no matching files, don't iterate over the literal pattern.
shopt -s nullglob

for f in "$tests_dir"/*.php; do
    # Explicitly lint the file first so syntax errors are always counted as failures.
    # (Some environments can emit parse/syntax output while still returning exit code 0
    # for the execution step; linting makes this deterministic.)
    lint_tmp="$(mktemp)"
    php -d display_errors=1 -l "$f" >"$lint_tmp" 2>&1
    lint_status=$?
    if [ "$lint_status" -ne 0 ]; then
        echo "---- $f"
        echo "FAILED (syntax): $f"
        cat "$lint_tmp"
        failed=$((failed+1))
        failed_files+=("$f")
        rm -f "$lint_tmp"
        continue
    fi
    rm -f "$lint_tmp"

    tmp="$(mktemp)"
    php -d display_errors=1 -d error_reporting=E_ALL "$f" >"$tmp" 2>&1
    status=$?

    # Safety net: treat fatal/uncaught output as failure even if exit code is 0.
    if [ "$status" -eq 0 ] && grep -Eqi '(PHP[[:space:]]+)?(Fatal error|Parse error|Catchable fatal error|Compile error)|Uncaught (Error|Exception)|\bParseError\b|\bsyntax error\b' "$tmp"; then
        status=1
    fi

    # Require explicit PASS or SKIP output; otherwise treat as failure even with exit code 0.
    if [ "$status" -eq 0 ] && ! grep -Eq 'PASS|SKIP' "$tmp"; then
        echo "---- $f"
        echo "FAILED: $f"
        echo "test suite did not emit any PASS/SKIP status"
        cat "$tmp"
        failed=$((failed+1))
        failed_files+=("$f")
        rm -f "$tmp"
        continue
    fi

    if [ "$status" -eq 0 ]; then
        if grep -q 'PASS' "$tmp"; then
            result_class="pass"
        else
            result_class="skip"
        fi

        if { [ "$result_class" = "pass" ] && $show_pass; } || { [ "$result_class" = "skip" ] && $show_skip; }; then
            echo "---- $f"
            cat "$tmp"
        fi
        passed=$((passed+1))
    else
        echo "---- $f"
        echo "FAILED: $f"
        # Hide misleading PASS lines that may have been printed before the crash.
        grep -vE '^[[:space:]]*PASS:' "$tmp" || true
        failed=$((failed+1))
        failed_files+=("$f")
    fi

    rm -f "$tmp"
done

echo "Passed: $passed"
echo "Failures: $failed"
if [ "$failed" -gt 0 ]; then
    echo "Failed files:"
    for ff in "${failed_files[@]}"; do
        echo " - $ff"
    done
fi
echo "DONE"
[ "$failed" -eq 0 ] && exit 0 || exit 1
