#!/usr/bin/env bash
set -euo pipefail

git config --local user.email "action@github.com"
git config --local user.name "GitHub Action"

tmpdir="$(mktemp -d)"
cp -R docs/. "$tmpdir/"

# a first deploy has no gh-pages to branch from, so start an orphan rather than force-pushing
if git fetch origin gh-pages 2> /dev/null; then
	git branch --no-track gh-pages origin/gh-pages 2> /dev/null || true
	git switch -f gh-pages
else
	git switch --orphan gh-pages
fi

find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +

cp -R "$tmpdir"/. .
rm -rf "$tmpdir"
git add -A

if git diff --cached --quiet; then
	echo "No documentation changes to deploy."
	exit 0
fi

git commit -m "Update PHPDoc ($1)"
# the branch was built on origin/gh-pages, so this fast-forwards and never needs -f
git push origin gh-pages
