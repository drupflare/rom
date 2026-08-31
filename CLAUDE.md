# rom (module machine name `cfw_do_sqlite`)

A Drupal 11.2+ database driver for **Cloudflare Durable Object SQLite**. Published as
`drupflare/rom`; consumed by `drupflare/worker`. Useful to anyone putting Drupal on DO storage,
independent of the wasm work.

## Two Names

The repository and the Composer package are `rom`. The **module machine name, the driver
directory, the PSR-4 namespace root and `extra.installer-name` are all `cfw_do_sqlite`** and were
not renamed with the repo.

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

**This repo's suite is the authority on behaviour.** A change made only in the worker is untested
code on the edge. Run the worker's `bun run assets:driver` after any change here, or the packed
copy goes stale - it has done so twice.

| suite                            | assertions |
| -------------------------------- | ---------- |
| `php tests/run-driver-suite.php` | 204        |
| `php tests/run-installer.php`    | 16         |
| `php tests/pdo-shim.php`         | 61         |

All three take a Drupal root as `$argv[1]` or `DRUPAL_ROOT`. Re-measure the counts before quoting
them.

**There is no `check:sync` in the worker any more**, so nothing compares this repo's files to the
copies under `worker/drupal/` - that check was deleted along with `scripts/check-module-sync.ts`,
and its `REPO_ONLY` set with it. What survives is `worker/tests/node/driver-pack.spec.ts`, which
asserts `assets/driver.json` matches `worker/drupal/` byte for byte. Keeping this repo and that
copy aligned is a release, not a hook.

## The two PDO fetch flags are version-dependent, and that is not a simplification to make

`src/pdo-shim.php` declares `FETCH_CLASSTYPE` and `FETCH_PROPS_LATE` through a `PHP_VERSION_ID`
ternary. **PHP 8.5 repacked them** and nothing else in that file moved: `php_pdo_driver.h` widened
`PDO_FETCH_FLAGS` from `0xFFFF0000` to `0xFFFFFFF0` and moved the flags out of the high half-word,
so `CLASSTYPE` went `0x00040000` -> `1<<7` and `PROPS_LATE` went `0x00100000` -> `1<<8`. Read out
of the headers at 8.3.11, 8.4.1 and 8.5.2, and confirmed by running `tests/pdo-shim.php` in
`php:8.3-cli`, `php:8.4-cli` and `php:8.5-cli`.

Collapsing either to one literal passes on whichever version is in front of you and fails the
other two. `tests/pdo-shim.php` compares both against the running extension, so the boundary is a
claim CI refutes rather than a comment nobody rechecks.

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

## Table prefixes are name-mangled; only a period is refused

This reverses what this file and the README used to say. A non-empty prefix WORKS: the base
`Drupal\Core\Database\Connection::setPrefix()` folds it into the identifier, so `{node}` becomes
`"site1_node"`, and calling the grandparent constructor is what selects that mechanism over core
sqlite's `ATTACH DATABASE` one. "The architecture does not want it" was never an argument - the
goal is Drupal compatibility, so the architecture moves.

- **`Schema::findTables()` is the ONE method that had to be replaced**, and it failed _silently_:
  the core sqlite version matches the expression against the bare `sqlite_master` name, because
  there a prefixed table is in its own attached schema. Here the name carries the prefix, so
  inheriting it returns an empty result rather than an error. Everything else - `getPrefixInfo()`,
  `createTable`, `renameTable`, index create/exists/drop, `getFullQualifiedTableName()` - is
  correct as inherited, because they all put the prefix on the front of the table name.
- **A period is the one character that cannot work.** Core allows `[A-Za-z0-9_.]` because every
  other driver reads a period as a schema selector. `Connection::PREFIX_PATTERN` drops it, the
  constructor refuses it by name, and `Install\Tasks::validateDatabaseSettings()` refuses it at
  the install form so the installer reports instead of fataling.
- Isolation is asserted over **one shared `FakeHost`**. A fixture per connection would make it
  trivially true and prove nothing.

## The replay cache is real but small, and the README says which half it misses

`TransactionBuffer` keeps every per-statement result a replay hands back, and every replay
populates it. Sound because a replay starts from the same committed state and runs `buffer[0..k]`
in order, so result _i_ depends only on the first _i+1_ entries - which never change while
buffered. A savepoint rollback is the only shrink, so `rollbackTo()` drops the discarded tail.

**It removes repeated resolutions, not the term.** `Insert::execute()` asks for `lastInsertId()`
straight after buffering each row, and the newest index is by definition uncached, so the
alternating write-then-read pair is still `N(N+1)` and the suite still asserts **12 for N=3**. Do
not write that the cache fixed the installer; what would fix it is fewer resolutions, not fewer
repeats.

**The installer has now been run and the term is measured rather than predicted.** A `standard`
install through this driver: **41,170 statements, of which 37,814 were replays - 92%** - across 401
host transactions, 394 of them speculative, with a widest transaction of **380 statements**. It
completes, and the site it builds matches a control install through core's sqlite driver (39 tables,
939 rows, one extra `system.schema` row for the driver module) with the front page at HTTP 200. So
the term is dominant AND survivable; the next thing that moves it is Drupal's multi-row
`Insert::execute()`, which discards every `lastInsertId()` but the last and pays a replay for each.

## Rules

- **Refuse with a named reason rather than truncating.** A silently truncated value is
  indistinguishable from correct output until much later, and that is this project's signature
  failure shape.
- **Never widen a limit because a test passes.** These came from a deployed object. Re-measure on a
  deployed worker if you think one is wrong.
- Transactions are a buffer plus atomic replay via `execTxn()`. Commit, speculative read and
  rollback are all verified by the suite; do not change the buffering without re-running all 204.
- **A statement the engine rejects must leave the buffer.** A buffered write reports success before
  it has run, so its refusal only surfaces at a later replay - and if it stays buffered, every later
  replay AND the commit re-run it, so the transaction can never succeed. That is not a corner case:
  it is Drupal's own "write, catch, create the table, carry on" idiom, which `MatcherDumper::dump()`
  runs inside a transaction, and it killed a stock `standard` install at `DELETE FROM router`.
  `Connection::speculate()` bisects to find the culprit, discards it and raises the error once.
  Which statement failed is NOT in the host reply, so bisection is the only way to place it; do not
  replace it with "blame the newest", which is wrong whenever DDL accumulates unresolved.
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

A `LongLineDeclaration` exclusion for `tests/run-driver-suite.php` is intended: a suite of
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
- The driver suite is **204 assertions** and the count is the release note: it went 101 -> 120 ->
  132 -> 147 -> 188 -> 204. Never tag on a lower number; a drop means the suite was weakened.
- **`tests/run-installer.php` is a second lane, 16 assertions, and it is the only one that reaches
  an install-shaped transaction.** The suite drives 5-6 statements per transaction; an install
  drives 380, with DDL and DML interleaved. It installs into a throwaway COPY of the Drupal root
  under the temp directory - a copy and not a symlink farm, because core resolves the application
  root from its own `__DIR__`, so a symlinked `core/` sends every computed path back at the
  original tree and the driver module is never discovered. Its acceptance check is a CONTROL
  install through core's own sqlite driver, compared table by table, not a hard-coded table list.
- **Four cost counters, not one.** `statementCount()` is bridge crossings; `transactionCount()` is
  host BEGINs; `speculativeCount()` is the rolled-back subset; `replayedStatementCount()` is what
  the host executed inside them. The last one is the only one that can see the O(W*R) term, because
  a replay is ONE bridge crossing however many statements run inside it. Each is asserted against
  `FakeHost`'s own count of the same thing, which is the point - a counter asserted against itself
  proves nothing.
- **A buffered insert costs a speculative replay by itself**, for `lastInsertId()`. Measured while
  writing those assertions, after a first draft predicted otherwise: a write-then-read pair is 2
  speculative transactions, so N pairs replay N(N+1) statements rather than N(N+1)/2.
- the Packagist steps are maintainer-only; `^1.0` needs a `v1.0.0` tag before it resolves from a
  path repository.
- **Packagist publishes from the webhook, so `release.yml` has no Packagist step and must not get
  one.** The `POST /api/update-package` nudge was removed on 2026-08-12; a pre-submission 403 came
  back as `curl -fsS` exit 22 and killed the release job for a call that changed nothing. No
  `PACKAGIST_USERNAME` / `PACKAGIST_TOKEN` secret exists or is needed.
- **The nightly lives on `build.yml`, not `release.yml`, and it publishes nothing.** It exists for
  one reason: `composer.lock` is uncommitted and `renovate.json` disables `drupal/core`, so a new
  Drupal 11.x can break this driver with no commit to trigger CI and no renovate PR. The old
  version was gated on "did `master` move in the last 24 hours", which made it a strict subset of
  the push gate - it could only run on commits the push gate had already tested, and skipped the
  idle-tree case that is the entire point. The `nightly-<date>-<sha>` prerelease went with it:
  `dev-master` already resolves to the tip.

## PHP versions: 8.5 works here, and that says nothing about the wasm side

`composer.json` requires `php: ^8.3`, which already permits 8.5. **Verified rather than assumed:** the
204-assertion driver suite passes on **PHP 8.5.7**, and Drupal core requires `>=8.3.0` with no upper
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
