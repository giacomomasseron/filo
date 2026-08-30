# filo

Zero-extension PHP call tracer. Userland instrumentation via a `file://`
stream wrapper + AST rewriting (nikic/php-parser). Original files are
never modified; instrumented copies live only in a throwaway cache.

## Install

```bash
composer require --dev giacomomasseron/filo
```

That's it — `bootstrap.php` is registered via Composer's `autoload.files`,
so it runs the moment `vendor/autoload.php` is loaded. It does nothing
unless explicitly enabled.

## Run

There is nothing to run. The tracer hooks in automatically on every
request once enabled — it works the same under Herd, Valet, nginx+FPM,
Apache, `artisan serve`, or plain CLI scripts.

**Enable it:** create an empty `.filo-on` file in your project root
(from your IDE file tree is fine). Browse your app as usual; every
request writes a JSON trace to `.filo/traces/` inside your project
(add `.filo/` to your `.gitignore`). Delete the file to stop tracing.
The check is per-request, so toggling is instant — no restarts.

**Alternative** (CI, docker-compose, one-off CLI runs): set the env var
`FILO_ENABLED=1`. A Laravel `.env` entry does *not* work — it loads
after the tracer bootstraps. Use the marker file for that workflow.

**One caveat:** if opcache is enabled in your dev setup, disable it
while tracing (`opcache.enable=0` in your php.ini / Herd PHP settings) —
cached opcodes bypass the tracer, and worse, opcache may keep serving
*instrumented* code after you toggle tracing off, since the file on disk
never changed. CLI is unaffected by default (`opcache.enable_cli` is
off out of the box).

## Configuration (env vars)

| Var                 | Default               | Meaning                                   |
|---------------------|-----------------------|-------------------------------------------|
| `FILO_ENABLED`    | `0`                   | Master switch                             |
| `FILO_CACHE_DIR`  | `<tmp>/filo-cache`  | Instrumented-file cache                   |
| `FILO_EXCLUDE`    | `vendor`              | Comma-separated path substrings to skip   |

## Trace format (v1)

```jsonc
{
  "version": 1,
  "duration": 12345678,          // ns
  "context": { "method": "GET", "uri": "/orders" },
  "events": [
    { "i": 0, "p": -1, "fn": "App\\Http\\Kernel::handle",
      "file": "/app/app/Http/Kernel.php", "line": 41,
      "s": 1200, "e": 8400300, "m": 2097152 }
  ]
}
```

`p` is the parent event id (`-1` = root). `s`/`e` are start/end offsets
in ns from request start. Self-time of a frame = `(e - s) - Σ children`.

## Breakpoints

Function-entry breakpoints, controlled by files — no daemon, no IDE
protocol. Works alongside tracing.

```bash
vendor/bin/filo break "App\\Services\\OrderService::listForUser"
```

Then trigger the code path (browse the page, run the command). The
request **freezes** at that function's entry. From another terminal:

```bash
vendor/bin/filo pending          # see paused requests
vendor/bin/filo show <id>        # inspect arguments, $this, locals
vendor/bin/filo continue <id>    # release it (or: continue --all)
```

Rules of engagement:

- Entry breakpoints only: you see the arguments (and `$this` for
  instance methods) as the function begins. No stepping, no eval —
  that's Xdebug territory, deliberately.
- Each breakpoint pauses **once per request** (so a breakpoint inside
  a loop doesn't pause 500 times).
- A paused request auto-continues after `FILO_BREAK_TIMEOUT` seconds
  (default 120) — a forgotten breakpoint can never hang a request
  forever.
- Pause time is **excluded from trace timings**: while you inspect,
  the timeline clock stops, so breakpoints don't pollute your
  flamegraph.
- Breakpoints live in `.filo/breakpoints.json` in the project root;
  paused-request snapshots in `<output>/breaks/`. The web UI reads and
  writes the same files — the CLI and UI are interchangeable.

## Web viewer

```bash
vendor/bin/filo serve        # http://127.0.0.1:8090
```

A zero-dependency local viewer (PHP built-in server, single file):
trace list, call tree with self-times, top-functions table, and live
paused-request panel with continue buttons. Localhost-only by design —
traces contain paths and variable values; never expose the port.

The built-in page is a functional placeholder. To install a designed UI
(e.g. exported from Claude Design), copy its files into `server/ui/`
(entry point `index.html`, assets flat in the same directory). When that
directory exists it is served instead of the placeholder — no code
changes. The UI must call the JSON API with relative paths
(`/api/traces`, `/api/breaks`, `/api/breakpoints`; contract documented
at the top of `server/index.php`). Delete `server/ui/` to fall back.

## Known limitations (v1, by design)

- **Opcache must be off while tracing** (see Run section) — cached
  opcodes bypass the wrapper.
- Files loaded before `vendor/autoload.php` (the front controller) are
  not instrumented. Use `auto_prepend_file` pointing at
  `vendor/giacomomasseron/filo/bootstrap.php` for full coverage.
- Native functions, `eval`'d code and arrow functions show up as
  self-time of their caller.
- Line numbers inside instrumented files drift (standard pretty
  printer); trace line numbers are correct — they're baked in from the
  original AST.
- Long-running runtimes: call `\Filo\Collector::cycle($dir)` per
  request instead of relying on shutdown flush.
