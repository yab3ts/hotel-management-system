#!/usr/bin/env bash
set -euo pipefail

# External Infrastructure, Port, TLS & Header Probe (#17, #82, #90, #91, #99)
HOST="${1:-}"
if [ -z "${HOST}" ]; then
  echo "Usage: $0 <hostname>"
  exit 2
fi

FAIL=0
echo "=== Probing ${HOST} ==="

# 1. Database & Admin Ports Closed (#90, #91)
if command -v nmap >/dev/null 2>&1; then
  OPEN_PORTS=$(nmap -Pn -p 22,3306,3389,5432,6379,8080,27017 "${HOST}" | grep -E "^[0-9]+/tcp\s+open" || true)
  if [ -n "${OPEN_PORTS}" ]; then
    echo "FAIL (#90/#91): Sensitive ports exposed:"
    echo "${OPEN_PORTS}"
    FAIL=1
  else
    echo "PASS (#90/#91): DB and admin ports are closed/filtered"
  fi
else
  echo "SKIP (#90/#91): nmap not installed"
fi

# 2. HTTP -> HTTPS Redirect (#17)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 8 "http://${HOST}" || echo "000")
if [ "${HTTP_CODE}" = "301" ] || [ "${HTTP_CODE}" = "308" ]; then
  echo "PASS (#17): HTTP redirects to HTTPS (${HTTP_CODE})"
else
  echo "FAIL (#17): Expected 301/308 redirect from HTTP, got ${HTTP_CODE}"
  FAIL=1
fi

# 3. HSTS + Health Status + Server Information Leak Check (#17, #99)
HEALTH_RESP=$(curl -sI --max-time 8 "https://${HOST}/api/health" || true)
HEALTH_CODE=$(echo "${HEALTH_RESP}" | head -n 1 | awk '{print $2}' || echo "000")

if [ "${HEALTH_CODE}" = "200" ]; then
  echo "PASS (#131): Application health endpoint returned HTTP 200 OK"
elif [ "${HEALTH_CODE}" = "502" ]; then
  echo "WARN (#131): Application health endpoint returned HTTP 502 Bad Gateway (upstream container offline or cold-start)"
else
  echo "INFO (#131): Health endpoint returned HTTP ${HEALTH_CODE}"
fi

if echo "${HEALTH_RESP}" | grep -iq "^strict-transport-security:"; then
  echo "PASS (#17): HSTS header present"
else
  echo "FAIL (#17): Strict-Transport-Security header missing"
  FAIL=1
fi

if echo "${HEALTH_RESP}" | grep -iqE "^x-powered-by:|^server:.*(apache|php|nginx)/[0-9]"; then
  echo "FAIL (#99): Server version leaked in response headers"
  FAIL=1
else
  echo "PASS (#99): No Apache/PHP/Nginx version leaked"
fi

# 4. TLS 1.2+ Verification (#82)
if curl -sI --tlsv1.2 --max-time 8 "https://${HOST}" >/dev/null 2>&1; then
  echo "PASS (#82): TLS 1.2+ handshake succeeded"
else
  echo "FAIL (#82): TLS 1.2+ handshake failed"
  FAIL=1
fi

exit "${FAIL}"
