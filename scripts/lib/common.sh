#!/usr/bin/env bash
# Funciones comunes de los scripts de proyecto (init, doctor, validate-env, wt-env).
# Se usa con `source`; no ejecuta nada por sí sola.

# Raíz del repo (un nivel sobre scripts/)
repo_root() { cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd; }

# get_kv ARCHIVO CLAVE [DEFECTO] → valor de CLAVE=valor (sin comillas externas)
get_kv() {
    local file="$1" key="$2" default="${3:-}" value=""
    if [[ -f "$file" ]]; then
        value=$(grep -E "^${key}=" "$file" | head -1 | cut -d= -f2- || true)
        value="${value%\"}"; value="${value#\"}"
    fi
    echo "${value:-$default}"
}

# set_kv ARCHIVO CLAVE VALOR → reemplaza la línea CLAVE=... (o la añade al final).
# Usa awk con ENVIRON para no tener que escapar nada del valor.
set_kv() {
    local file="$1" key="$2" value="$3" tmp
    tmp=$(mktemp)
    KEY="$key" VAL="$value" awk '
        BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VAL"]; done = 0 }
        index($0, k "=") == 1 && !done { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$file" > "$tmp"
    cat "$tmp" > "$file"   # cat (no mv) para conservar permisos y dueño
    rm -f "$tmp"
}

# port_listening PUERTO → 0 si algún proceso escucha en ese puerto (TCP)
port_listening() {
    local p="$1"
    if command -v ss >/dev/null 2>&1; then
        [[ -n "$(ss -ltnH "sport = :${p}" 2>/dev/null)" ]]
    else
        (exec 3<>"/dev/tcp/127.0.0.1/${p}") 2>/dev/null
    fi
}

# rand_hex N_BYTES → cadena hex aleatoria (2*N caracteres)
rand_hex() { openssl rand -hex "${1:-16}"; }
