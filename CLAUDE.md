# rom (module machine name `cfw_do_sqlite`)

A Drupal 11.2+ database driver for **Cloudflare Durable Object SQLite**. Published as
`drupflare/rom`; consumed by `drupflare/worker`. Useful to anyone putting Drupal on DO storage,
independent of the wasm work.

## Two names, on purpose

The repository and the Composer package are `rom`. The **module machine name, the driver
directory, the PSR-4 namespace root and `extra.installer-name` are all `cfw_do_sqlite`** and were
deliberately not renamed with the repo.

A Drupal database driver's directory name IS the `driver` value in `settings.php`, and the
namespace is derived from it. `worker/src/site-do.ts` writes all three:

```php
'driver' => 'cfw_do_sqlite',
'namespace' => 'Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite',
'autoload' => 'modules/custom/cfw_do_sqlite/src/Driver/Database/cfw_do_sqlite/',
```

So renaming it is a bootstrap-breaking change to every existing site's settings, to the worker's
override, to `worker/scripts/gen-driver-assets.ts` and to the packed `assets/driver.json`. Do not
rename it as tidying. If it is ever renamed it is a major version and both repos move together.

## This module exists TWICE and the second copy is what executes

`drupflare/worker` keeps its own copy under `drupal/cfw_do_sqlite/`, because **Composer never runs
on the edge**. The worker packs it into `assets/driver.json`, which the Durable Object mounts.

**This repo's suite is the authority on behaviour**: `DRUPAL_ROOT=<worker>/drupal-src php
tests/run-driver-suite.php` - 132 assertions. A change made only in the worker is untested code on
the edge. The worker's `bun run check:sync` covers both copies plus the packed artifact; run its
`bun run assets:driver` after any change here.

`tests/run-driver-suite.php` and `tests/fixtures/FakeHost.php` must stay **byte-identical** to the
worker's copies - `check-module-sync.ts` deliberately keeps them off its repo-only list. Repo-only
files there are `composer.json`, `package.json`, `phpstan.neon`, `codecov.yml`, `renovate.json`,
`tests/lint.php` and the docs; `tests/coverage.php` is NOT on that list yet and needs adding.

## Drupal 11.2 is a measured floor

`Statement.php` uses `Drupal\Core\Database\Statement\StatementBase`, `PrefetchedResult` and
`FetchAs`. All three first exist in **11.2.0** as the replacements for the
`StatementPrefetchIterator` API deprecated there. Verified: those three files 404 on
`git.drupalcode.org` at tags `10.3.0`, `11.0.0` and `11.1.0` and exist at `11.2.0`. So `^11` would
be dishonest, and `^10.3 || ^11` impossible. `composer.json` and `cfw_do_sqlite.info.yml` both say
`^11.2`.

## The platform limits the driver exists to survive

| limit                          | value                | what it broke                           |
| ------------------------------ | -------------------- | --------------------------------------- |
| bound parameters per statement | **100**              | the cache write path                    |
| LIKE / GLOB pattern            | **50 bytes**         | binds **plain `LIKE`**, not just `GLOB` |
| bytes per record               | **2,199,995**        | large cache entries                     |
| statement text                 | **100,000 chars**    | a base64 codec blew it                  |
| integer reads                  | **lossy above 2^53** | cannot be fixed in the driver           |

**"There is no smaller unit than a row" is false** - three 520 KB rows each overran the record cap
and looked indivisible; SQLite builds a value across statements with `col = col || ?`. That was
this project's own unverified claim.

The integer limit is the one that **cannot** be fixed here. Document it, refuse loudly, do not
paper over it.

## Rules

- **Refuse with a named reason rather than truncating.** A silently truncated value is
  indistinguishable from correct output until much later, and that is this project's signature
  failure shape.
- **Never widen a limit because a test passes.** These came from a deployed object. Re-measure on a
  deployed worker if you think one is wrong.
- Transactions are a buffer plus atomic replay via `execTxn()`. Commit, speculative read and
  rollback are all verified by the suite; do not change the buffering without re-running all 132.
- Never call `Database::startLog()` when benchmarking - it changes what you are measuring.

## Formatting: prettier owns layout, phpcs owns meaning, phpstan owns types

The house style is **tabs rendered 4 wide, 100-char lines, LF, UTF-8**, for every language
including PHP, enforced by `@prettier/plugin-php`. It is NOT Drupal's 2-space standard, and a
previous session got this backwards by reformatting the PHP to satisfy `drupal/coder`.

**When phpcs disagrees with the house style, phpcs loses.** `phpcs.xml.dist` excludes the nine
sniff codes that are pure whitespace or brace position, with the measurement inline: at `useTabs`
the tree reported 5,275 violations across exactly those nine and nothing else. Everything semantic
stays on. Constants are lowercase `true`/`false`/`null`.

`Drupal.Arrays.Array.ArrayIndentation` IS excluded now. An older note here said not to, and that
note was written while the tree was 2-space formatted, where the sniff could fire for a real
reason. Under tabs it asserts "parent indent + 2 spaces" against a file with no indent spaces, so
it fires on every array element regardless of content and carries no signal.

A `LongLineDeclaration` exclusion for `tests/run-driver-suite.php` is deliberate: a suite of
one-assertion-per-line reads worse with expected values split across lines.

**A malformed `phpcs.xml.dist` fails silently and reports a fake pass.** Verify a ruleset change by
loading it (`php -r '$d=new DOMDocument(); var_dump($d->load("phpcs.xml.dist"));'`); `--` inside an
XML comment is invalid.

## Conventions

- Never silence PHPStan with an ignore, baseline, `assert()`, inline `@var`, cast, or widened type.
  Run it with `--memory-limit=1G`; 128M OOMs and reports a fake low count.
- `phpstan.neon` is level 5 over `src/` only. `tests/` is out of scope because the two errors it
  raises are a constant-pin assertion PHPStan reads as always-true and the `global $fail` counter
  it cannot track - and both files must stay byte-identical to the worker's copies, so they cannot
  be restructured here alone. Level 6 needs 42 `missingType.iterableValue` plus 2
  `missingType.return` annotations.
- Comments: lowercase, terse, one line, no trailing period, only where the WHY is non-obvious.
- Default branch is `master`. Every workflow filters on it so a push and its PR do not double-fire.
- `PUBLISHING.md` has the Packagist steps; `^1.0` needs a `v1.0.0` tag before it resolves from a
  path repository.

## PHP versions: 8.5 works here, and that says nothing about the wasm side

`composer.json` requires `php: ^8.3`, which already permits 8.5. **Verified rather than assumed:** the
132-assertion driver suite passes on **PHP 8.5.7**, and Drupal core requires `>=8.3.0` with no upper
bound. CI matrices in `build.yml` cover `8.3`, `8.4`, `8.5`.

That was cheap because this module is a database driver: pure PHP, one extension (`ext-json`), no
plugins, no `Fiber`, no reliance on engine internals. A newer PHP is a matrix entry.

**Do not generalise it.** Getting PHP 8.5 running as _wasm_ (checklist item B5, in `phasm`) is a
different and much harder problem: the toolchain is pinned to a vendored `php8.3-src` tree, the
VM-interrupt patch edits `Zend/zend_execute.c` on the VM hot path and may not apply, and on measured
extrapolation an 8.5 build lands 22-79 KB OVER the 3 MB gzipped bundle ceiling. rom passing on 8.5 is
not evidence for any of that.

The floor is the real constraint here, not the ceiling: `drupal/core: ^11.2`, because
`StatementBase`, `PrefetchedResult` and `FetchAs` do not exist before 11.2.0 (probed at four tags:
404 at 10.3.0/11.0.0/11.1.0, 200 at 11.2.0).
