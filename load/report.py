#!/usr/bin/env python3
"""Resume load/results/*.json (los deja handleSummary de cada escenario): una fila por ejecución."""
import json
import pathlib
import sys

d = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "load/results")
rows = []
for f in sorted(d.glob("*.json")):
    try:
        b = json.loads(f.read_text())["brief"]
    except (ValueError, KeyError):
        continue
    rows.append(b)
if not rows:
    print("Sin resultados en", d, "- ejecuta make load-smoke (o load-test, ...)")
    sys.exit(0)
print(f"{'escenario':10} {'peticiones':>10} {'req/s':>7} {'errores%':>9} {'checks%':>8} {'p95 ms':>7} {'p99 ms':>7}  umbrales")
for b in rows:
    f = lambda v, k=1: "-" if v is None else f"{v * k:.1f}"
    print(f"{b['script']:10} {b['http_reqs'] or 0:>10} {f(b['rps']):>7} {f(b['error_rate'],100):>9} {f(b['checks_rate'],100):>8} "
          f"{f(b['p95_ms']):>7} {f(b['p99_ms']):>7}  {'OK' if b['thresholds_ok'] else 'FALLAN'}")
