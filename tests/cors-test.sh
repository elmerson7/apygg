#!/bin/bash

# Script para probar CORS manualmente
# Uso: ./tests/cors-test.sh [URL_API] [ORIGIN]

API_URL="${1:-http://localhost:8010}"
ORIGIN="${2:-http://localhost:5173}"

echo "🧪 Probando CORS en: $API_URL"
echo "📍 Origen: $ORIGIN"
echo ""

# Probar preflight request (OPTIONS)
echo "1️⃣ Preflight Request (OPTIONS):"
curl -i -X OPTIONS "$API_URL/api/health" \
  -H "Origin: $ORIGIN" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization" \
  2>&1 | grep -E "(HTTP|Access-Control|Origin)" || echo "❌ No se recibieron headers CORS"

echo ""
echo ""

# Probar request normal (GET)
echo "2️⃣ Request Normal (GET):"
curl -i -X GET "$API_URL/api/health" \
  -H "Origin: $ORIGIN" \
  -H "Content-Type: application/json" \
  2>&1 | grep -E "(HTTP|Access-Control|Origin)" || echo "❌ No se recibieron headers CORS"

echo ""
echo "✅ Prueba completada"
echo ""
echo "💡 Verifica que los headers Access-Control-Allow-Origin contengan tu origen"
