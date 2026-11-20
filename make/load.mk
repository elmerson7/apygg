# Pruebas de carga con k6 en contenedor (docs/load-testing.md). Scripts en load/.
# Necesitan datos de `make seed-load` (CSV de credenciales) y una API en LOAD_BASE_URL.
#   make load-smoke LOAD_BASE_URL=http://127.0.0.1:8010
#   make load-stack-smoke   # stack temporal propio (<= 2 GB) + siembra pequena + humo + down -v
K6_IMAGE ?= grafana/k6:1.4.0
LOAD_BASE_URL ?= http://127.0.0.1:$(or $(shell grep -m1 '^APP_PORT=' compose.env 2>/dev/null | cut -d= -f2),8010)
export K6_IMAGE LOAD_BASE_URL

.PHONY: load-smoke load-test load-stress load-spike load-soak load-report load-stack-smoke

# Humo: pocos VUs, < 1 min
load-smoke:
	@load/run.sh smoke

# Carga esperada sostenida (LOAD_VUS, LOAD_HOLD...)
load-test:
	@load/run.sh load

# Estres: escalones crecientes hasta degradar
load-stress:
	@load/run.sh stress

# Pico brusco y recuperacion
load-spike:
	@load/run.sh spike

# Resistencia: horas con carga moderada
load-soak:
	@load/run.sh soak

# Tabla resumen de load/results/*.json
load-report:
	@docker run --rm -u $$(id -u):$$(id -g) -v "$(CURDIR)/load":/load:ro python:3.13-slim python -I /load/report.py /load/results

# Stack temporal propio + siembra pequena + humo + down -v (LOAD_PROJECT, LOAD_PORT, LOAD_TENANTS)
load-stack-smoke:
	@load/verify.sh
