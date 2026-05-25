FROM postgres:18-alpine

RUN apk add --no-cache \
    postgis

RUN apk add --no-cache --virtual .pgvector-build-deps \
    build-base \
    clang19 \
    llvm19-dev \
    git \
    && git clone --branch v0.8.1 https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && cd /tmp/pgvector \
    && make \
    && make install \
    && cd / \
    && rm -rf /tmp/pgvector \
    && apk del .pgvector-build-deps

COPY docker/postgres/init-extensions.sql /docker-entrypoint-initdb.d/
