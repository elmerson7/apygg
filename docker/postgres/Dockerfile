FROM postgres:18-alpine

RUN apk add --no-cache \
    postgis

# El paquete postgis de apk deja sus archivos bajo /usr/{share,lib}/postgresqlNN, pero el postgres de
# la imagen base (compilado en /usr/local) solo busca extensiones en /usr/local: sin estos enlaces
# `CREATE EXTENSION postgis` falla ("extension postgis is not available") y la BD no arranca
# en un volumen nuevo (make setup).
RUN set -e; \
    ext="$(ls -d /usr/share/postgresql[0-9]*/extension | head -1)"; \
    lib="$(ls -d /usr/lib/postgresql[0-9]* | head -1)"; \
    ln -s "$ext"/postgis* "$ext"/address_standardizer* /usr/local/share/postgresql/extension/; \
    ln -s "$lib"/postgis*.so "$lib"/address_standardizer*.so /usr/local/lib/postgresql/

RUN LLVM_VER="$(ls -d /usr/lib/llvm* 2>/dev/null | head -1 | sed 's|.*llvm||')" \
    && [ -n "$LLVM_VER" ] || LLVM_VER="$(pg_config --configure 2>/dev/null | grep -oP 'CLANG=clang-\K[0-9]+')" \
    && apk add --no-cache --virtual .pgvector-build-deps \
        build-base \
        "clang${LLVM_VER}" \
        "llvm${LLVM_VER}-dev" \
        git \
    && git clone --branch v0.8.1 https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && cd /tmp/pgvector \
    && make \
    && make install \
    && cd / \
    && rm -rf /tmp/pgvector \
    && apk del .pgvector-build-deps

COPY docker/postgres/init-extensions.sql /docker-entrypoint-initdb.d/
