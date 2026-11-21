#!/usr/bin/env bash
# Campaña de rendimiento sobre el stack temporal (docs/performance-baseline.md). Por fases, siempre con candado:
#   LOAD_PROJECT=apygg-carga LOAD_PORT=18010 load/campaign.sh up|seed|run <etiqueta> <escenario> [workers] [direct|pgbouncer]|down
# `run` recrea la API con los workers/pooler pedidos, ejecuta el escenario, muestrea docker stats + conexiones de
# PostgreSQL + memoria de Redis cada 10 s y deja load/results/campaign/<etiqueta>.{json,stats.csv}.
# Cada medición toma /home/ubuntu/dev/apygg-wt/.verify.lock (servidor compartido: nunca compitas con otras verificaciones).
set -uo pipefail
cd "$(dirname "$0")/.."
export LOAD_PROJECT="${LOAD_PROJECT:-apygg-carga}" LOAD_PORT="${LOAD_PORT:-18010}"
LOCK="${LOAD_LOCK:-/home/ubuntu/dev/apygg-wt/.verify.lock}"
OUT=load/results/campaign
mkdir -p "$OUT"

sampler() { # $1 = etiqueta ; corre hasta que se borre el fichero .run
  local f="$OUT/$1.stats.csv"
  echo "ts,app_mem_mib,app_cpu_pct,pg_conns,pg_active,redis_used_mb,workers_rss_mib" > "$f"
  while [ -f "$OUT/$1.run" ]; do
    local st mem cpu conns act rmem rss
    st=$(docker stats --no-stream --format '{{.MemUsage}};{{.CPUPerc}}' "${LOAD_PROJECT}-app" 2>/dev/null)
    mem=$(echo "$st" | sed -E 's/^([0-9.]+)([KMG]i)B.*/\1 \2/' | awk '{v=$1; if($2=="GiB")v*=1024; if($2=="KiB")v/=1024; printf "%.0f",v}')
    cpu=$(echo "$st" | cut -d';' -f2 | tr -d '%')
    conns=$(load/stack.sh sql "select count(*) from pg_stat_activity where datname='load'" 2>/dev/null)
    act=$(load/stack.sh sql "select count(*) from pg_stat_activity where datname='load' and state='active'" 2>/dev/null)
    rmem=$(docker exec "${LOAD_PROJECT}-redis" redis-cli -a load --no-auth-warning info memory 2>/dev/null | awk -F: '/^used_memory:/{printf "%.1f",$2/1048576}')
    rss=$(docker exec "${LOAD_PROJECT}-app" sh -c 'cat /proc/[0-9]*/status 2>/dev/null | awk "/^VmRSS/{s+=\$2} END{printf \"%.0f\", s/1024}"' 2>/dev/null)
    echo "$(date +%s),$mem,$cpu,$conns,$act,$rmem,$rss" >> "$f"
    sleep 10
  done
}

case "${1:-}" in
  up) exec 9>"$LOCK"; flock 9; load/stack.sh up && load/stack.sh migrate ;;
  seed) shift; exec 9>"$LOCK"; flock 9; s=$(date +%s); load/stack.sh seed "$@"; echo "siembra: $(( $(date +%s) - s )) s" ;;
  run)
    label="$2"; scenario="$3"; workers="${4:-2}"; mode="${5:-direct}"
    export LOAD_WORKERS="$workers"
    exec 9>"$LOCK"; flock 9
    load/stack.sh app "$mode" || exit 1
    sleep 5
    touch "$OUT/$label.run"; sampler "$label" & spid=$!
    start=$(date +%s)
    LOAD_BASE_URL="http://127.0.0.1:${LOAD_PORT}" load/run.sh "$scenario" > "$OUT/$label.log" 2>&1; rc=$?
    echo "rc=$rc duración=$(( $(date +%s) - start ))s workers=$workers modo=$mode" >> "$OUT/$label.log"
    rm -f "$OUT/$label.run"; wait "$spid" 2>/dev/null
    [ -f "load/results/$scenario.json" ] && cp "load/results/$scenario.json" "$OUT/$label.json"
    echo "$label rc=$rc"
    ;;
  down) load/stack.sh down ;;
  *) echo "uso: load/campaign.sh up|seed [opciones]|run <etiqueta> <escenario> [workers] [direct|pgbouncer]|down" >&2; exit 2 ;;
esac
