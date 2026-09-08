<?php

declare(strict_types=1);

/*
 * Filo local viewer server. Run via:  vendor/bin/filo serve
 * (which does: php -S 127.0.0.1:8090 <this file>)
 *
 * Localhost-only by design: traces contain file paths, function names
 * and — with breakpoints — variable values. Do not expose this port.
 *
 * ── HTTP API (contract for the designed UI) ───────────────────────────
 *  GET  /api/traces                     -> [full trace JSON + {name}], newest first, at most
 *                                          TRACE_LIST_LIMIT entries (the UI renders straight
 *                                          from `events`, so summaries aren't enough)
 *                                          tests/<Class__method>.json artifacts are listed
 *                                          with the "tests/" prefix in name
 *  GET  /api/traces/{name}              -> one full trace JSON (schema: README "Trace format")
 *  GET  /api/breaks                     -> [{id, fn, file, line, ts, uri, vars}]
 *  POST /api/breaks/{id}/continue       -> release one paused request
 *  POST /api/breaks/continue-all        -> release all
 *  GET  /api/breakpoints                -> [{id, fn, enabled}] (or {id, file, line, enabled})
 *  PUT  /api/breakpoints                -> replace list; body is the same bare array
 *                                          (a legacy {"breakpoints": [...]} wrapper and
 *                                          plain "Class::method" strings are accepted too)
 *
 * breakpoints.json stores the object form. Only `fn` breakpoints can fire
 * (Debugger matches __METHOD__); file:line entries are kept for the UI
 * but never trigger — see Debugger.php.
 *  GET  /                               -> built-in minimal UI (replaceable)
 *
 * Mutating endpoints (POST/PUT) REQUIRE the header `X-Filo: 1`. A custom
 * header forces a CORS preflight, which this server never answers, so a
 * third-party page in the developer's browser can't release pauses or
 * rewrite breakpoints via a simple cross-origin request.
 */

require_once dirname(__DIR__) . '/src/Tracer.php';
$root      = \Filo\Tracer::findProjectRoot();
$outputDir = \Filo\Tracer::outputDir($root);
$breaksDir = $outputDir . '/breaks';
$bpFile    = $root . '/.filo/breakpoints.json';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$json = static function (mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

// CSRF guard for anything that changes state (see header comment).
if (str_starts_with($path, '/api/') && $method !== 'GET' && ($_SERVER['HTTP_X_FILO'] ?? '') !== '1') {
    $json(['error' => 'missing X-Filo header'], 403);
}

// ── /api/traces ───────────────────────────────────────────────────────
const TRACE_LIST_LIMIT = 50;

if ($path === '/api/traces' && $method === 'GET') {
    // Newest first by mtime: a name sort would rank every tests/ artifact
    // (Class__method.json) above the Ymd-His request traces and, past the
    // limit, hide the request traces entirely. Name desc breaks ties.
    $dir   = rtrim($outputDir, '/');
    $files = array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/tests/*.json') ?: []);
    $mtime = [];
    foreach ($files as $f) {
        $mtime[$f] = @filemtime($f) ?: 0;
    }
    usort($files, static fn (string $a, string $b): int => [$mtime[$b], $b] <=> [$mtime[$a], $a]);

    $out = [];
    foreach (array_slice($files, 0, TRACE_LIST_LIMIT) as $f) {
        $t = json_decode((string) file_get_contents($f), true);
        if (!is_array($t) || !isset($t['events'])) {
            continue;
        }
        $t['name'] = substr($f, strlen($dir) + 1); // "x.json" or "tests/x.json"
        $out[]     = $t;
    }
    $json($out);
}

if (preg_match('#^/api/traces/((?:tests/)?[A-Za-z0-9._-]+\.json)$#', $path, $m) && $method === 'GET') {
    $file = rtrim($outputDir, '/') . '/' . $m[1]; // regex forbids traversal
    is_file($file) || $json(['error' => 'not found'], 404);
    header('Content-Type: application/json');
    readfile($file);
    exit;
}

// ── /api/breaks ───────────────────────────────────────────────────────
if ($path === '/api/breaks' && $method === 'GET') {
    $out = [];
    foreach (glob($breaksDir . '/*.json') ?: [] as $f) {
        $s = json_decode((string) file_get_contents($f), true);
        if (is_array($s) && isset($s['id'])) {
            $out[] = $s;
        }
    }
    $json($out);
}

if ($path === '/api/breaks/continue-all' && $method === 'POST') {
    is_dir($breaksDir) || @mkdir($breaksDir, 0777, true);
    file_put_contents($breaksDir . '/continue-all', uniqid('', true)); // token, see Debugger::pause
    $json(['ok' => true]);
}

if (preg_match('#^/api/breaks/([A-Za-z0-9-]+)/continue$#', $path, $m) && $method === 'POST') {
    is_dir($breaksDir) || @mkdir($breaksDir, 0777, true);
    touch($breaksDir . '/' . $m[1] . '.continue');
    $json(['ok' => true]);
}

// ── /api/breakpoints ──────────────────────────────────────────────────
/** Accepts a string ("App\\Foo::bar") or an object; returns the canonical object or null. */
$normalizeBp = static function (mixed $item): ?array {
    if (is_string($item)) {
        $item = ['fn' => $item];
    }
    if (!is_array($item)) {
        return null;
    }
    $fn   = isset($item['fn']) && is_string($item['fn']) ? trim($item['fn']) : '';
    $file = isset($item['file']) && is_string($item['file']) ? trim($item['file']) : '';
    $line = isset($item['line']) && is_numeric($item['line']) ? (int) $item['line'] : 0;
    if ($fn === '' && ($file === '' || $line <= 0)) {
        return null;
    }
    $out = ['id' => isset($item['id']) && is_string($item['id']) && $item['id'] !== ''
        ? $item['id']
        : 'bp_' . substr(md5($fn !== '' ? $fn : $file . ':' . $line), 0, 8)];
    if ($fn !== '') {
        $out['fn'] = $fn;
    } else {
        $out['file'] = $file;
        $out['line'] = $line;
    }
    $out['enabled'] = !array_key_exists('enabled', $item) || (bool) $item['enabled'];

    return $out;
};

$readBps = static function () use ($bpFile, $normalizeBp): array {
    $cfg = is_file($bpFile) ? json_decode((string) file_get_contents($bpFile), true) : null;
    $raw = is_array($cfg) ? ($cfg['breakpoints'] ?? $cfg) : [];

    return array_values(array_filter(array_map($normalizeBp, (array) $raw)));
};

if ($path === '/api/breakpoints' && $method === 'GET') {
    $json($readBps());
}

if ($path === '/api/breakpoints' && $method === 'PUT') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $raw  = is_array($body) ? ($body['breakpoints'] ?? $body) : [];
    $list = array_values(array_filter(array_map($normalizeBp, (array) $raw)));
    is_dir(dirname($bpFile)) || @mkdir(dirname($bpFile), 0777, true);
    file_put_contents($bpFile, json_encode(['breakpoints' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $json($list);
}

if (str_starts_with($path, '/api/')) {
    $json(['error' => 'unknown endpoint'], 404);
}

// ── designed UI (drop-in) ─────────────────────────────────────────────
// If server/ui/ exists (e.g. an exported Claude Design build), serve it
// and skip the placeholder below. Explicit whitelist + basename() only:
// with a php -S router script, `return false` would resolve against the
// project root and expose source files — never do that here.
$uiDir = __DIR__ . '/ui';
if (is_dir($uiDir)) {
    $mime = [
        'html' => 'text/html; charset=utf-8',
        'js'   => 'application/javascript',
        'css'  => 'text/css',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'ico'  => 'image/x-icon',
        'woff2'=> 'font/woff2',
        'json' => 'application/json',
        'map'  => 'application/json',
    ];

    $file = $path === '/' ? 'index.html' : basename($path); // flat dir, no traversal
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (isset($mime[$ext]) && is_file($uiDir . '/' . $file)) {
        header('Content-Type: ' . $mime[$ext]);
        readfile($uiDir . '/' . $file);
        exit;
    }

    if ($path === '/' || $ext === '') { // SPA route fallback
        header('Content-Type: text/html; charset=utf-8');
        readfile($uiDir . '/index.html');
        exit;
    }

    http_response_code(404);
    exit;
}

// ── built-in minimal UI ───────────────────────────────────────────────
// Functional placeholder: the designed UI (see docs/ui-brief) replaces
// this page but keeps every /api/* endpoint above unchanged.
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>filo</title>
<style>
 body{font:14px/1.5 ui-monospace,Menlo,monospace;background:#111;color:#ddd;margin:2rem auto;max-width:70rem;padding:0 1rem}
 h1{font-size:1.1rem}a{color:#7ab7ff}table{border-collapse:collapse;width:100%}
 td,th{padding:.3rem .6rem;border-bottom:1px solid #2a2a2a;text-align:left;vertical-align:top}
 button{background:#233;border:1px solid #466;color:#cde;cursor:pointer;padding:.2rem .6rem}
 details{margin-left:1rem}summary{cursor:pointer}
 .self{color:#f0a35e}.dim{color:#777}pre{background:#181818;padding:.6rem;overflow:auto}
</style></head><body>
<h1>filo <span class="dim">— follow the thread</span></h1>
<section id="breaks"></section>
<section id="traces"></section>
<section id="detail"></section>
<script>
const $=(s)=>document.querySelector(s), api=(u,o)=>fetch(u,o).then(r=>r.json());
const ms=(ns)=>(ns/1e6).toFixed(2)+' ms';

async function loadBreaks(){
  const b=await api('/api/breaks');
  $('#breaks').innerHTML = b.length===0 ? '' :
    '<h2>⏸ paused requests</h2>'+b.map(s=>`<div><b>${s.fn}</b> <span class="dim">${s.file}:${s.line}</span>
      <button onclick="fetch('/api/breaks/${s.id}/continue',{method:'POST',headers:{'X-Filo':'1'}}).then(loadBreaks)">continue</button>
      <pre>${JSON.stringify(s.vars,null,1)}</pre></div>`).join('');
}
async function loadTraces(){
  const t=await api('/api/traces');
  $('#traces').innerHTML='<h2>traces</h2><table>'+t.map(x=>
    `<tr><td><a href="#" onclick="openTrace('${x.name}');return false">${x.name}</a></td>
     <td>${(x.context.method||'CLI')} ${(x.context.uri|| (x.context.argv||[]).join(' ')||'')}</td>
     <td>${ms(x.duration)}</td><td class="dim">${x.events_count} ev${x.capped?' ⚠ capped':''}</td></tr>`).join('')+'</table>';
}
async function openTrace(name){
  const t=await api('/api/traces/'+name);
  const kids={}; t.events.forEach(e=>{(kids[e.p]??=[]).push(e)});
  const selfOf=e=>(e.e-e.s)-(kids[e.i]||[]).reduce((a,c)=>a+(c.e-c.s),0);
  const node=e=>{const c=kids[e.i]||[];const inner=c.map(node).join('');
    const row=`<b>${e.fn}</b> ${ms(e.e-e.s)} <span class="self">self ${ms(selfOf(e))}</span> <span class="dim">${e.file}:${e.line}</span>`;
    return c.length?`<details open><summary>${row}</summary>${inner}</details>`:`<div style="margin-left:1rem">${row}</div>`;};
  const agg={}; t.events.forEach(e=>{const a=agg[e.fn]??={n:0,self:0};a.n++;a.self+=selfOf(e)});
  const top=Object.entries(agg).sort((a,b)=>b[1].self-a[1].self).slice(0,15);
  $('#detail').innerHTML=`<h2>${name} <span class="dim">${ms(t.duration)}</span></h2>
    <h3>top self-time</h3><table>${top.map(([f,a])=>`<tr><td>${f}</td><td>×${a.n}</td><td class="self">${ms(a.self)}</td></tr>`).join('')}</table>
    <h3>call tree</h3>`+(kids[-1]||[]).map(node).join('');
}
loadBreaks(); loadTraces(); setInterval(loadBreaks, 2000);
</script></body></html>
