#!/usr/bin/env bash
set -euo pipefail

# Pre-commit Secret Guard (#102)
# Install locally via: cp scripts/security/pre-commit-gitleaks.sh .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit

if command -v gitleaks >/dev/null 2>&1; then
  exec gitleaks protect --staged --verbose
fi

# Portable fallback pattern scanner when gitleaks binary is absent
STAGED_DIFF=$(git diff --cached --unified=0)
PATTERN='(AKIA[0-9A-Z]{16}|-----BEGIN (RSA|EC|OPENSSH) PRIVATE KEY-----|sk_live_[0-9a-zA-Z]{24,}|ghp_[0-9a-zA-Z]{36}|xox[baprs]-[0-9A-Za-z-]{10,})'

if echo "${STAGED_DIFF}" | grep -qE "^\+.*${PATTERN}"; then
  echo "BLOCKED (#102): Potential hardcoded secret detected in staged changes!"
  exit 1
fi

exit 0
