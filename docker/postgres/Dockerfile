FROM postgres:18-alpine

RUN apk add --no-cache \
    postgis

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
