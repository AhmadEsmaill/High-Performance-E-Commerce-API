#!/usr/bin/env bash
# ===========================================================================
# Stress / stability test — proves the system serves >=100 concurrent users
# without crashing (Test A) and without data loss under concurrency (Test B).
# Requires: the docker compose stack running + ApacheBench (ab).
# ===========================================================================
set -u
BASE="http://localhost:8080"
CONC=100                  # concurrent users
A_REQUESTS=5000           # Test A total requests
B_REQUESTS=500            # Test B total concurrent writes
OUT=/tmp/stress; mkdir -p "$OUT"

echo "############################################################"
echo "# SETUP"
echo "############################################################"
EMAIL="stress_$(date +%s)@test.com"
REG=$(curl -s -H "Accept: application/json" -H "Content-Type: application/json" \
  -d "{\"name\":\"Stress\",\"email\":\"$EMAIL\",\"password\":\"password123\",\"password_confirmation\":\"password123\"}" \
  "$BASE/api/auth/register")
TOKEN=$(echo "$REG" | grep -oE '"token":"[^"]+"' | head -1 | cut -d'"' -f4)
echo "auth token: ${TOKEN:0:20}...  (len=${#TOKEN})"

# Ensure a known product (id captured) reset to stock = 0
PID=$(docker compose exec -T app-1 php artisan tinker --execute='$p=App\Models\Product::firstOrCreate(["name"=>"StressTest Widget"],["price"=>9.99,"stock_quantity"=>0]); $p->update(["stock_quantity"=>0]); echo $p->id;' 2>/dev/null | tail -1 | tr -d '[:space:]')
echo "test product id: $PID  (stock reset to 0)"
echo

echo "############################################################"
echo "# TEST A — STABILITY: $CONC concurrent users, $A_REQUESTS requests"
echo "#          GET /api/products   (read path, Redis-cached)"
echo "############################################################"
ab -n "$A_REQUESTS" -c "$CONC" -H "Accept: application/json" "$BASE/api/products" > "$OUT/testA.txt" 2>&1
grep -E "Complete requests|Failed requests|Non-2xx|Requests per second|Time per request:|Transfer rate|^  50%|^  95%|^  99%|^ 100%" "$OUT/testA.txt"
echo

echo "############################################################"
echo "# TEST B — DATA INTEGRITY: $CONC concurrent restock(+1), $B_REQUESTS writes"
echo "#          POST /api/inventory/$PID/restock  (pessimistic lock)"
echo "############################################################"
echo '{"quantity":1}' > "$OUT/body.json"
# -l : accept variable response length. Each restock returns a different
#      stock_quantity, so without -l ApacheBench miscounts these 200-OK
#      responses as "failed" purely due to differing body length.
ab -l -n "$B_REQUESTS" -c "$CONC" -p "$OUT/body.json" -T application/json \
   -H "Accept: application/json" -H "Authorization: Bearer $TOKEN" \
   "$BASE/api/inventory/$PID/restock" > "$OUT/testB.txt" 2>&1
grep -E "Complete requests|Failed requests|Non-2xx|Requests per second|Time per request:|^  95%|^  99%|^ 100%" "$OUT/testB.txt"

FINAL=$(docker compose exec -T app-1 php artisan tinker --execute="echo App\Models\Product::find($PID)->stock_quantity;" 2>/dev/null | tail -1 | tr -d '[:space:]')
echo
echo "--- DATA INTEGRITY CHECK ---"
echo "expected final stock : $B_REQUESTS"
echo "actual   final stock : $FINAL"
if [ "$FINAL" = "$B_REQUESTS" ]; then
  echo "RESULT: PASS  (no lost updates — every concurrent write persisted exactly once)"
else
  echo "RESULT: FAIL  (data loss detected: $((B_REQUESTS - FINAL)) updates lost)"
fi
echo
echo "############################################################"
echo "# CONTAINERS STILL HEALTHY AFTER LOAD?"
echo "############################################################"
docker compose ps --format '{{.Name}}\t{{.Status}}'
