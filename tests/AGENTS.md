# Test Runner Instructions
Instructions for running and interpreting the test suite via tests/run_all.sh. Covers invocation, flags, output format, and exit codes — targeted at AI agents executing or analysing test runs.

## Invocation

```bash
bash tests/run_all.sh [--show-pass] [--show-skip]
```

Run from the project root. The script discovers and executes every `*.php` file in `tests/`.

## Flags

| Flag | Alias | Effect |
|---|---|---|
| `--show-pass` | `--show-success` | Print per-file output for passing tests. Without this flag passing tests produce no output. |
| `--show-skip` | — | Print per-file output for skipped tests. Without this flag skipped tests produce no output. |

By default (no flags) only **failures** are printed. This is the recommended mode for CI and agent use.

## Required test file contract

Every PHP test file in `tests/` must satisfy all of the following:

1. **No syntax/parse errors** — the runner lints each file with `php -l` before execution. A lint failure is a hard failure.
2. **Echo `PASS` or `SKIP`** at least once to stdout during a successful run. Files that exit with code 0 but emit neither are treated as failures.
3. **Exit with code 0** on success. Any non-zero exit code is a failure regardless of output.
4. **No fatal/uncaught output** — the runner scans stdout/stderr for patterns such as `Fatal error`, `Parse error`, `Uncaught Error`, `Uncaught Exception`, `ParseError`, `syntax error`. Their presence forces a failure even when exit code is 0.

## Output format

### Header

```
Tests suite start at <RFC date string>
```

### Per-file failure block

Only printed when a file fails. Omitted entirely for passing and skipped files (unless the corresponding show flag is set).

```
---- /absolute/path/to/test_file.php
FAILED: /absolute/path/to/test_file.php
<captured stdout/stderr of that file, with any PASS lines stripped>
```

For lint (syntax) failures the second line reads:

```
FAILED (syntax): /absolute/path/to/test_file.php
```

### Summary block (always printed)

```
Passed: <N>
Failures: <N>
```

When failures > 0, a list follows immediately:

```
Failed files:
 - /absolute/path/to/test_file.php
 - /absolute/path/to/another_file.php
```

### Terminator (always printed)

```
DONE
```

## Exit codes

| Code | Meaning |
|---|---|
| `0` | All tests passed or skipped; zero failures. |
| `1` | One or more test files failed. |

## Agent guidance

- **Parse failures from the summary block**, not from per-file blocks. The summary is always present and machine-readable.
- The `Failed files:` list gives the exact file paths to investigate.
- To suppress noise and get only actionable output, run without any flags.
- To see full output of every test (e.g. for debugging), run with `--show-pass --show-skip`.
- A file reporting `SKIP` is counted as **passed** in the summary (`Passed: N` includes skips). Skips are not failures.
- Do not infer success from the absence of `FAILED` lines alone — always check the exit code and the `Failures: N` line.
