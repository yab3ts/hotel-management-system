#!/usr/bin/env bash
set -euo pipefail

# DNS & Email Authentication Check (#100, #95, M13)
# Accepts custom apex domain via argument or CUSTOM_DOMAIN env var.
DOMAIN="${1:-${CUSTOM_DOMAIN:-}}"
SUBDOMAINS="${2:-cafex roma}"

if [ -z "${DOMAIN}" ] || echo "${DOMAIN}" | grep -qE '(vercel\.app|railway\.app|localhost|yourdomain\.com)$'; then
  echo "INFO (#100): Skipping SPF/DKIM/DMARC probe for preview/platform host (${DOMAIN:-empty})."
  echo "INFO (#100): You do not control apex DNS on Vercel/Railway subdomains."
  echo "INFO (#100): Set repo/env variable CUSTOM_DOMAIN (e.g. tableza.com) once your production apex domain is attached."
  exit 0
fi

FAIL=0
echo "=== DNS & Email Security Probe for ${DOMAIN} ==="

if dig +short TXT "${DOMAIN}" | grep -q "v=spf1"; then
  echo "PASS (#100): SPF record present on ${DOMAIN}"
else
  echo "FAIL (#100): Missing SPF (v=spf1) TXT record on ${DOMAIN}"
  FAIL=1
fi

if dig +short TXT "_dmarc.${DOMAIN}" | grep -qE "p=(reject|quarantine)"; then
  echo "PASS (#100): DMARC policy (reject/quarantine) present on _dmarc.${DOMAIN}"
else
  echo "FAIL (#100): Missing or weak DMARC policy on _dmarc.${DOMAIN}"
  FAIL=1
fi

if dig +short TXT "selector1._domainkey.${DOMAIN}" | grep -q "v=DKIM1"; then
  echo "PASS (#100): DKIM selector1 record present"
else
  echo "FAIL (#100): Missing DKIM record on selector1._domainkey.${DOMAIN}"
  FAIL=1
fi

echo "=== Subdomain Takeover Check (#95, M13) ==="
for sub in ${SUBDOMAINS}; do
  FQDN="${sub}.${DOMAIN}"
  CNAME=$(dig +short CNAME "${FQDN}")
  if [ -n "${CNAME}" ]; then
    if ! dig +short "${CNAME}" | grep -qE '.'; then
      echo "FAIL (#95/M13): Dangling CNAME detected: ${FQDN} -> ${CNAME} (NXDOMAIN)"
      FAIL=1
    else
      echo "PASS (#95/M13): ${FQDN} -> ${CNAME} resolves cleanly"
    fi
  else
    echo "INFO (#95/M13): No CNAME record for ${FQDN}"
  fi
done

exit "${FAIL}"
