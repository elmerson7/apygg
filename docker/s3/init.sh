#!/bin/sh
# Crea los buckets de desarrollo en el emulador S3 (idempotente).
# Usa solo curl con firma SigV4: no hace falta instalar un cliente S3.
# Variables: S3_ENDPOINT, S3_ACCESS_KEY, S3_SECRET_KEY, S3_BUCKET, S3_PRIVATE_BUCKET
set -eu

SIGV4="aws:amz:us-east-1:s3"

req() { # req <método> <ruta> [datos...]
    method="$1"; path="$2"; shift 2
    curl -sS -o /dev/null -w '%{http_code}' -X "$method" \
        --user "${S3_ACCESS_KEY}:${S3_SECRET_KEY}" --aws-sigv4 "$SIGV4" \
        "$@" "${S3_ENDPOINT}${path}"
}

crear_bucket() {
    code=$(req PUT "/$1")
    case "$code" in
        200|409) echo "bucket $1: listo ($code)";;
        *) echo "bucket $1: fallo HTTP $code" >&2; exit 1;;
    esac
}

crear_bucket "$S3_BUCKET"
crear_bucket "$S3_PRIVATE_BUCKET"

# Política: lectura anónima SOLO del bucket público. El privado queda sin política.
policy="{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Principal\":{\"AWS\":[\"*\"]},\"Action\":[\"s3:GetObject\"],\"Resource\":[\"arn:aws:s3:::${S3_BUCKET}/*\"]}]}"
code=$(req PUT "/${S3_BUCKET}?policy" -H 'Content-Type: application/json' --data "$policy")
case "$code" in
    200|204) echo "bucket ${S3_BUCKET}: política de lectura pública aplicada";;
    *) echo "política de ${S3_BUCKET}: fallo HTTP $code" >&2; exit 1;;
esac
