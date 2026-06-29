#!/usr/bin/env bash
# ponytail: post-edit quality gate for ONE edited PHP file.
# Pint + Rector auto-fix; PHPStan reports (exit 2 feeds errors back to Claude).
set -u

file=$(jq -r '.tool_input.file_path // empty')
case "$file" in
    *.php) ;;
    *) exit 0 ;;
esac

cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0
rel="${file#"$(pwd)/"}"
case "$rel" in
    vendor/*) exit 0 ;;
esac
[ -f "$rel" ] || exit 0
[ -x vendor/bin/phpstan ] || exit 0

vendor/bin/pint -q "$rel" >/dev/null 2>&1
vendor/bin/rector process "$rel" -q >/dev/null 2>&1

# PHPStan only on its analysed roots (phpstan.neon paths); tests/ etc. are not
# analysed at level max and would emit false positives.
case "$rel" in
    app/*|bootstrap/*|config/*|database/*|public/*|routes/*) ;;
    *) exit 0 ;;
esac

out=$(vendor/bin/phpstan analyse "$rel" --no-progress --error-format=raw --memory-limit=1G 2>&1)
if [ $? -ne 0 ]; then
    printf 'PHPStan found issues in %s:\n%s\n' "$rel" "$out" >&2
    exit 2
fi
exit 0
