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

# CORS: un cliente web (SPA) sube con PUT presignado y descarga desde el navegador. Abierto a cualquier origen
# porque es un emulador de desarrollo/pruebas; en producción el CORS se fija en el bucket real.
cors='<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod><AllowedMethod>PUT</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedHeader>*</AllowedHeader><ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>3000</MaxAgeSeconds></CORSRule></CORSConfiguration>'
for bucket in "$S3_BUCKET" "$S3_PRIVATE_BUCKET"; do
    code=$(req PUT "/${bucket}?cors" -H 'Content-Type: application/xml' --data "$cors")
    case "$code" in
        200|204) echo "bucket ${bucket}: CORS aplicado";;
        *) echo "CORS de ${bucket}: fallo HTTP $code" >&2; exit 1;;
    esac
done
