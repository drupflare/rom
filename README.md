# 💾 rom

> Drupal 11 database driver for Durable Object SQLite

[![Build](https://github.com/drupflare/rom/actions/workflows/build.yml/badge.svg)](https://github.com/drupflare/rom/actions/workflows/build.yml)
[![Coverage](https://github.com/drupflare/rom/actions/workflows/coverage.yml/badge.svg)](https://github.com/drupflare/rom/actions/workflows/coverage.yml)
[![Prettier](https://github.com/drupflare/rom/actions/workflows/prettier.yml/badge.svg)](https://github.com/drupflare/rom/actions/workflows/prettier.yml)
[![Packagist](https://img.shields.io/packagist/v/drupflare/rom)](https://packagist.org/packages/drupflare/rom)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Drupal 11 talking to `ctx.storage.sql` — the SQLite database inside a Cloudflare
Durable Object.** Drupal's query builders, schema handling and condition compiler are
used unchanged; what is replaced is everything that assumed PDO and a file on disk.

`composer require drupflare/rom` installs it; the module machine name and the driver
directory are both `cfw_do_sqlite`, which is the name `settings.php` refers to. Those two
names differ on purpose: the package is named for the repository, the driver for the value
Drupal loads it by.

> [!IMPORTANT]
> **Drupal 11.2 or newer.** The statement class extends `Drupal\Core\Database\Statement\StatementBase`
> and returns a `PrefetchedResult` with a `FetchAs` fetch mode. All three of those classes first
> exist in **11.2.0**.

> [!CAUTION]
> **This is not a general-purpose SQLite driver.** It targets one engine, reached through
> one bridge: `ctx.storage.sql` inside a Durable Object, called through host functions the
> Worker installs. There is no PDO, no file path, no connection string and no second
> database to attach. On a normal host it cannot connect at all — `Install\Tasks` reports
> the driver uninstallable when the bridge is absent. For SQLite on a filesystem, use
> Drupal core's own `sqlite` driver, which this one extends. The
> [platform limits](#-platform-limits-you-cannot-configure-away) below are not tunables;
> they are properties of the engine this driver is for.

---

## 📋 Table of Contents

- [Why a New Driver](#-why-a-new-driver)
- [How It Works](#-how-it-works)
- [The Host Contract](#-the-host-contract)
- [Installation](#-installation)
- [Working Across the Repositories Before Publication](#-working-across-the-repositories-before-publication)
- [Platform Limits You Cannot Configure Away](#-platform-limits-you-cannot-configure-away)
- [What the Design Does Not Cover](#-what-the-design-does-not-cover)
- [SQL Function and Collation Audit](#-sql-function-and-collation-audit)
- [Cost](#-cost)
- [Files](#-files)
- [Formatting and Coding Standards](#-formatting-and-coding-standards)
- [Testing](#-testing)
- [CI](#-ci)
- [What Remains](#-what-remains)
- [Related Repositories](#-related-repositories)
- [License](#-license)

---

## 🎯 Why a New Driver

A Durable Object's SQLite looks like the obvious home for a small Drupal site: it is
strongly consistent, it lives in the same isolate as the code, and reads are synchronous
so PHP's blocking database calls compose with it. Then you try Drupal's own SQLite driver
and it cannot connect, for three reasons that are not configuration:

1. **There is no PDO.** The core sqlite `Connection` types its client as `\PDO` and
   attaches one database file per prefix. There is no driver to find and no file to
   attach.
2. **There are no user-defined functions or collations.** The core driver registers
   `GREATEST`, `LEAST`, `RAND`, `IF`, `LENGTH`, `MD5`, `SUBSTRING_INDEX`, `REGEXP` and a
   `NOCASE_UTF8` collation through PDO. Without collations, **every `CREATE TABLE` core
   emits fails** — measured, `no such collation sequence: NOCASE_UTF8:
SQLITE_ERROR_MISSING_COLLSEQ`.
3. **`BEGIN` is refused outright**, with the runtime telling you to use
   `state.storage.transactionSync()` instead. `SAVEPOINT`, `COMMIT` and `ROLLBACK` are the
   same family, so Drupal's begin-commit API has nothing to map onto.

Point 3 is the interesting one, because `transactionSync(cb)` is callback-scoped and
driven from JavaScript while Drupal's API is begin-then-commit. Those do not compose, and
without a suspension mechanism PHP cannot call into a callback-scoped API and resume. So
the transaction scope has to be **inverted or deferred**. This driver defers.

---

## 🔧 How It Works

Writes are withheld and replayed. Reads go straight to the host unless they would have to
see a withheld write.

```txt
startTransaction()   -> open a buffer
  write              -> append to the buffer, return no rows and no row count
  read, clean table  -> straight to the host
  read, dirty table  -> replay the buffer + the read in one transactionSync,
                        capture the rows, roll the whole thing back
  savepoint          -> record the buffer length
  rollback to it     -> truncate the buffer to that length
rollBack()           -> discard the buffer; this cannot fail
commit()             -> replay the buffer in one transactionSync
```

A read is "clean" when none of the tables it references has a buffered write.
`SqlAnalyzer` answers that, and it **over-approximates in every uncertain direction**: an
unclassifiable statement, an unpinnable write target or a `RENAME` marks everything dirty.
The cost of over-approximating is a read resolved the expensive way. The cost of
under-approximating is data that is wrong without saying so.

DDL additionally dirties a pseudo-table `sqlite_master`, so `tableExists()`,
`findTables()` and `PRAGMA table_info()` collide with a buffered `CREATE`/`DROP` and get
resolved through the replay instead of reading stale schema. Tested: inside a transaction
`tableExists('late')` is TRUE for a table the host has never seen, and FALSE again after
the rollback.

**A read inside a transaction is the hard case, not the commit.** Committing is replaying
a list. The trap is code that writes a row and reads it back before committing, and the
driver answers those by replaying the buffer inside a transaction it then rolls back — and
refuses rather than guessing when it cannot.

### How it extends core without inheriting PDO

The constructor calls the **grandparent** `Drupal\Core\Database\Connection::__construct()`
directly, skipping the sqlite constructor's `\PDO` type and its per-prefix attach. That is
legal PHP in object context and was verified before being relied on; with an empty prefix
it is exactly what the sqlite constructor would have done. `__destruct()` is overridden the
same way so the sqlite destructor never tries to `unlink()` a database file after a
`DROP TABLE`.

`getAttachedDatabases()` returns a synthetic `['main' => 'main']`, which is not cosmetic:
it is what makes the inherited `Schema::findTables()` work unchanged while the real
`$attachedDatabases` property stays empty, so the destructor's prune loop is never entered.

Everything else is inherited. `Select`, `Insert`, `Truncate`, the condition compiler, the
type map and the table-rebuild dance all come from
`Drupal\sqlite\Driver\Database\sqlite`, because the engine underneath genuinely is SQLite.
Two exceptions: `Schema` overrides one method to substitute the collation, and `Upsert` is
overridden because of the bound-parameter ceiling below.

---

## 🔗 The Host Contract

Two functions installed on the PHP module by the Worker, both reached through
`vrzno_env()`. `CfwSqlClient` is the only class that talks to either, standing exactly
where `\PDO` stands.

| Name         | Purpose                                                                    | Absent means                         |
| ------------ | -------------------------------------------------------------------------- | ------------------------------------ |
| `cfwSqlExec` | one statement, returning rows and counters                                 | the driver is not installable at all |
| `cfwSqlTxn`  | a list of statements inside one `transactionSync()`, plus an optional read | the degraded mode below              |

`cfwSqlTxn` request and reply:

```json
{
	"statements": [{ "sql": "...", "params": {} }],
	"commit": true,
	"read": { "sql": "...", "params": {} }
}
```

```json
{
	"ok": true,
	"results": [
		{ "rows": [], "rowsRead": 0, "rowsWritten": 1, "lastInsertRowid": 7, "changes": 1 }
	],
	"readResult": null
}
```

Everything runs inside one `ctx.storage.transactionSync()`. When `commit` is false the
host runs the statements and then throws a private sentinel so the runtime rolls back —
that is the measured rollback mechanism, used deliberately — and still returns `results`,
which is what makes the speculative row count and insert id work. `read` is evaluated
after the statements, inside the same transaction, and is only meaningful with
`commit: false`. Failure is `{"ok": false, "error": "<sqlite message>"}` with the whole
transaction rolled back.

### Without `cfwSqlTxn`

The driver probes for it by name and degrades. `supportsAtomicCommit()` reports which mode
the connection is in.

| Operation                                | Behaviour                                                        |
| ---------------------------------------- | ---------------------------------------------------------------- |
| Rollback                                 | still exactly correct - nothing was ever written                 |
| Commit                                   | replays statement by statement, **not atomic**                   |
| Read of a clean table in a transaction   | works                                                            |
| Read of a dirty table in a transaction   | throws `UncommittedStateException`                               |
| `lastInsertId()` with a buffered insert  | throws                                                           |
| `rowCount()` of a buffered write         | throws                                                           |
| `$connection->insert()` in a transaction | **throws**, because `Insert::execute()` returns `lastInsertId()` |

The last row matters most, and it was found by running the suite rather than by reading
the code: without `cfwSqlTxn` the insert query builder cannot be used inside a transaction
at all, and since Drupal's own multi-row `Insert::execute()` opens a transaction, any
multi-row insert fails too.

---

## 📦 Installation

```sh
composer require drupflare/rom
```

`composer/installers` places the module at `web/modules/contrib/cfw_do_sqlite`, not
`.../rom`, because `composer.json` sets `extra.installer-name` — the package is named
for the repository and the directory is named for the module machine name, which is what
Drupal discovers it by.

Then point the site at the driver from `settings.php`:

```php
$databases['default']['default'] = [
	'driver' => 'cfw_do_sqlite',
	'namespace' => 'Drupal\\cfw_do_sqlite\\Driver\\Database\\cfw_do_sqlite',
	'autoload' => 'modules/contrib/cfw_do_sqlite/src/Driver/Database/cfw_do_sqlite/',
	'prefix' => '',
];

// NOT optional: this Connection extends the core sqlite one, and replacing the
// default connection removes the only entry that would have registered its namespace
$class_loader->addPsr4(
	'Drupal\\sqlite\\Driver\\Database\\sqlite\\',
	$app_root . '/core/modules/sqlite/src/Driver/Database/sqlite/',
);
```

Append rather than substitute: a later assignment wins, and `settings.php` is required from
inside `Settings::initialize()` where `$app_root`, `$site_path` and `$class_loader` are all
in scope.

There are no credentials and no host. The Durable Object's identity **is** the address, so
`database` is a label recorded for reference and routes nothing. A non-empty `prefix`
throws at construction, because the core driver implements prefixes with
`ATTACH DATABASE` and there is no second database to attach — one Durable Object per site
is the intended shape. That also means Drupal's own test runner cannot use this driver.

---

## 🔀 Working Across the Repositories Before Publication

Until the first Packagist release, and any time you want to edit the driver and the site
together, point Composer at a local checkout. Composer's **path repository** does that, and
it symlinks by default, so edits in the checkout are live in the site with no reinstall.

Clone the repositories as siblings:

```txt
drupflare/
  rom/           this repo
  drupflare/     mail, HTTP, images and logging over Workers bindings
  php-workerd/   the PHP wasm build
  worker/        the site that consumes all three
```

> [!NOTE]
> This repository's default branch is `master`, so its untagged path-repository version is
> `dev-master`. The measured table below was taken against the `drupflare/drupflare`
> sibling while that one was on `main`; the quoted output is left verbatim rather than
> edited, because the behaviour it demonstrates is the constraint syntax, not the branch
> name.

Then, in the consuming project's `composer.json`:

```json
{
	"repositories": [
		{ "type": "path", "url": "../drupal-do", "options": { "symlink": true } },
		{ "type": "path", "url": "../drupflare", "options": { "symlink": true } }
	],
	"require": {
		"drupflare/rom": "*@dev",
		"drupflare/drupflare": "*@dev"
	}
}
```

**The constraint is the part that catches people, so it is worth being exact.** A path
repository takes its version from the checkout's git refs, so an untagged checkout is
`dev-main` — and both of the obvious constraints are refused. Measured, on the sibling
repository:

| Constraint | Result against an untagged checkout                                        |
| ---------- | -------------------------------------------------------------------------- |
| `^1.0`     | `found drupflare/drupflare[dev-main] but it does not match the constraint` |
| `*`        | `found ... [dev-main] but it does not match your minimum-stability`        |
| `*@dev`    | resolves: `Locking drupflare/drupflare (dev-main <sha>)`                   |

So use `*@dev` while the checkout is untagged (or set `"minimum-stability": "dev"`, which
loosens every other package too and is the worse trade). **Tag the checkout `v1.0.0` and
`^1.0` starts working immediately**, with no edit to the consumer — which is the reason
[`PUBLISHING.md`](PUBLISHING.md) tags before it submits.

`"options": { "symlink": true }` is Composer's default and means edits in the checkout are
live in the site. Pass `"symlink": false` to get a copy instead, which is what
`drupflare/worker` does: it packs the driver from its own `drupal/` tree rather than from
`vendor/`, so a symlink would buy nothing and a copy makes the installed state explicit.

To track the branch rather than the working tree, use a VCS repository instead — it clones
from GitHub, so it needs a push rather than a save:

```json
{
	"repositories": [{ "type": "vcs", "url": "https://github.com/drupflare/rom" }],
	"require": { "drupflare/rom": "dev-master" }
}
```

**After publication both blocks come out** and `composer require drupflare/rom:^1.0`
is the whole story. The package name, the autoload map and the install path are identical
in all three cases, so the `settings.php` snippet above never changes.

See [`PUBLISHING.md`](PUBLISHING.md) for the ordered steps to that first release.

---

## 🧱 Platform Limits You Cannot Configure Away

Properties of Durable Object SQLite, every one measured on deployed infrastructure rather
than read from documentation. They belong in release notes, not in support tickets.

| Limit                                      | Measured behaviour                                                                                 |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| **100 bound parameters per statement**     | 100 binds succeed, 101 throws `too many SQL variables: SQLITE_ERROR`. Bisected directly            |
| **50-byte `LIKE`/`GLOB` patterns**         | exactly 50; SQLite's own default is 50,000, so the runtime lowers it                               |
| **No user-defined functions**              | `MD5()`, `SUBSTRING_INDEX()` and `REGEXP` fail loudly at runtime                                   |
| **No user-defined collations**             | `NOCASE_UTF8` becomes builtin `NOCASE`, which folds **ASCII only**                                 |
| **No `REGEXP`**                            | `no such function: REGEXP`, so Views regex filters do not work                                     |
| **Reading an integer above 2^53 is lossy** | written `9007199254740993`, read back `9007199254740992`. Writing is exact                         |
| **`CREATE TEMPORARY TABLE` is refused**    | `not authorized: SQLITE_AUTH`, so `queryTemporary()` cannot work                                   |
| **`sqlite_version()` is refused**          | `not authorized to use function: sqlite_version`                                                   |
| **`GLOB ... ESCAPE` is refused**           | `wrong number of arguments to function GLOB()`                                                     |
| **A JS BigInt cannot be bound**            | `Cannot convert a BigInt value to a number`; the host converts to a decimal string instead         |
| **`BEGIN` as SQL is refused**              | the runtime names `transactionSync()` in the error; the driver never sends transaction-control SQL |

Four of those need the consequence spelled out.

**Case-insensitive matching is ASCII-only.** Measured: `'Hello World'` matches
`'hello world'`; `'Ünicode'` does **not** match `'ünicode'`. So case-insensitive comparison
of non-ASCII text is case-**sensitive** here, and it affects username and email uniqueness,
taxonomy term matching and `LIKE`. The fix lives in the runtime's SQLite build, not in a
driver.

**The 100-parameter ceiling is the cache write path, not a corner case.**
`DatabaseBackend::setMultiple()` upserts in chunks of `MAX_ITEMS_PER_CACHE_SET = 100` rows
over 7 columns, which is **700 placeholders** — core's own chunking is already 7x over. A
cold `cache_discovery` write (82 entries, 574 placeholders) made every render fail with a 500. `Upsert` therefore re-batches by **placeholder count** rather than row count, because
the limit counts parameters and a row's width is not fixed, and wraps multiple batches in
one transaction so a half-applied cache set is never observable. `Insert` is still
inherited and is safe: it executes one statement per row.

**The 50-byte pattern cap binds plain `LIKE` too.** `Connection::MAX_LIKE_PATTERN_BYTES`
refuses over-length patterns on the **translated** form, which is what protects a Views
"contains" filter. A plain `LIKE` with a bound pattern is invisible to the driver and
fails in the engine. Note also that `likeToGlob()` triples an asterisk, so 20 asterisks
become a 60-byte GLOB pattern and are refused — the refusal reports the translated length
for exactly that reason.

**Wide-integer reads cannot be fixed at driver level.** `ctx.storage.sql` hands INTEGER
columns back as JS doubles, so precision is gone before anything in PHP can see it; the
codec then carries the wrong number faithfully. Storage is exact — `CAST(col AS TEXT)`
returns all the digits — so a fix would mean selecting wide columns as text, which needs
schema knowledge the driver does not have at query time. Drupal core never stores integers
that wide; the exposure is contrib holding 64-bit ids.

---

## 🚫 What the Design Does Not Cover

In descending order of how likely you are to hit it.

1. **A read that joins buffered rows against committed ones is only correct through the
   replay.** With `cfwSqlTxn` present it is correct, because the join runs inside a real
   transaction with the writes applied. Without it, the read is refused. There is no third
   behaviour, deliberately.
2. **Cost is quadratic in the worst case.** Each dirty read replays the whole buffer, so a
   transaction with W writes and R dirty reads executes O(W\*R) statements inside the
   Durable Object. See [Cost](#-cost) for what that actually measured.
3. **A non-deterministic write replays differently.** `random()`, `CURRENT_TIMESTAMP` or an
   implicit rowid can take one value during a speculative read and another during the
   commit replay. Drupal supplies timestamps from PHP rather than SQL, so the exposure is
   narrow — and a rowid from `lastInsertId()` matches the committed one only because the
   Durable Object gate serialises events so no other writer can advance the sequence in
   between. If that gate is ever removed, this breaks.
4. **A savepoint is a buffer index, not a database savepoint.** Rolling back to one
   truncates the list, and releasing one releases every savepoint after it, matching
   SQLite. For the way Drupal uses savepoints — nested `Transaction` objects — the list is
   the whole state.
5. **A failed commit leaves the Drupal transaction stack dirty.** The replay throws,
   `commitClientTransaction()` sets `CommitFailed` and rethrows so the real SQLite message
   survives, and the stack item is never voided, so its destructor throws
   `TransactionOutOfOrderException`. That is core's behaviour for any driver whose client
   `commit()` throws, PDO included, so it is not introduced here — but a mid-replay failure
   is messy on the way out.
6. **A statement withheld before an exception stays withheld.** If `Insert::execute()`
   buffers its INSERT and then throws from `lastInsertId()`, the INSERT is still in the
   buffer; a caller that swallows the exception and commits anyway lands a row with an id
   it never learned. Tested, and documented rather than papered over: the write genuinely
   was issued inside the transaction.
7. **`voidClientTransaction()` commits the buffer.** Drupal calls it when it believes the
   database committed behind its back. Nothing was sent, so the faithful equivalent is to
   commit; dropping the buffer would silently lose writes Drupal considers durable.
   `supportsTransactionalDDL()` is TRUE, so this should be unreachable.
8. **Cross-event transactions are not attempted and cannot be.** Durable Object SQLite
   commits its implicit transaction at the end of **each event**, so a `BEGIN` in one
   request is already committed before a `ROLLBACK` arrives in the next. Drupal has no API
   for a transaction that outlives a request, so nothing is lost.

---

## 🔍 SQL Function and Collation Audit

What the core sqlite driver registers through `PDO::sqliteCreateFunction()` and
`sqliteCreateCollation()`, none of which exists on `ctx.storage.sql`. "Drupal uses it" is
from grepping non-test `core/lib` and `core/modules` for the function inside SQL strings.

| Core registers      | Drupal uses it in SQL                                          | Builtin equivalent            | This driver                     |
| ------------------- | -------------------------------------------------------------- | ----------------------------- | ------------------------------- |
| `GREATEST()`        | **yes** - comment views plugins (3), `history` NodeNewComments | `max(a,b,...)`, variadic      | **rewritten to `max`**          |
| `LEAST()`           | **yes** - same family                                          | `min(a,b,...)`, variadic      | **rewritten to `min`**          |
| `RAND()`            | **yes** - `Select::orderRandom()`                              | `random()`                    | **rewritten to `random`**       |
| `IF()`              | not found in core SQL                                          | `iif()`, since 3.32           | **rewritten to `iif`**          |
| `SUBSTRING()`       | **yes** - `CommentStorage` (4), comment + views sorts          | builtin, alias of `substr`    | passes through                  |
| `LENGTH()`          | **yes** - `CommentStorage`                                     | builtin, **but counts chars** | passes through; see below       |
| `CONCAT()`          | not found in core SQL                                          | builtin since 3.44            | passes through                  |
| `CONCAT_WS()`       | **yes** - views `Combine` filter                               | builtin since 3.44            | passes through                  |
| `POW()`             | not found in core SQL                                          | builtin with math functions   | passes through                  |
| `EXP()`             | **yes** - `search_node` relevance scoring                      | builtin with math functions   | passes through                  |
| `MD5()`             | not found in core SQL                                          | **none**                      | fails loudly at runtime         |
| `SUBSTRING_INDEX()` | not found in core SQL                                          | **none**                      | fails loudly at runtime         |
| `REGEXP`            | **yes** - views `StringFilter`, `NumericFilter`, `Combine`     | **none**                      | fails loudly at runtime         |
| `GLOB()` override   | **yes, indirectly** - `LIKE BINARY` from entity queries        | builtin, **wrong wildcards**  | **`LIKE BINARY` is translated** |
| `NOCASE_UTF8`       | **yes** - every non-binary VARCHAR/TEXT column                 | `NOCASE`, ASCII only          | **substituted, ASCII-only**     |

The rewrite is four names, applied only outside string literals, comments and quoted
identifiers, using the same literal-aware scanner the table analysis uses.
`SELECT 'GREATEST('` survives it, tested end to end. **Functions with no exact builtin are
deliberately absent from the map**: mapping something onto a function that behaves
differently is how you get quiet wrongness.

`LENGTH()` changes meaning. Core overrides it with PHP's `strlen()`, so it counts bytes;
SQLite's builtin counts characters on TEXT. `CommentStorage` uses it on thread strings like
`01.02/`, which are ASCII, so the two agree there. Any other caller comparing lengths of
multibyte text gets a different answer than on MySQL, and it is not fixable without user
functions.

`LIKE BINARY` works, and the "there is no seam" claim this design once made was wrong.
`Condition::compile()` emits `field OPERATOR prefix placeholder postfix`, so a marker
placed in the operator's `prefix` lands immediately before the placeholder and identifies
which bound argument is the pattern. `Connection::translateLikeBinary()` rewrites that
argument with `SqlAnalyzer::likeToGlob()` before anything else sees the statement and
strips the marker; core's `ESCAPE '\'` postfix is dropped because builtin `GLOB` refuses a
third argument. **9,000 differential cases agree with core's own
`sqlFunctionLikeBinary()`**, with a control proving the untranslated form disagrees — so
case-sensitive `STARTS_WITH` / `CONTAINS` / `ENDS_WITH` are available. Those 9,000 cases
all used patterns of at most 5 characters, so nothing in the differential suite covers a
long pattern; the 50-byte refusal is what protects those, not the differential agreement.

The engine floor is established by **feature probe**, not by asking, because
`sqlite_version()` is refused. It reports **3.46.0**, proven by `unhex()` which landed in
3.46. That matters more than it looks: Drupal 11.4.5 gates installation on **3.45**, and
`concat` — the obvious probe — only proves 3.44 and would have **failed** the gate.
`engineVersionIsFloor()` reports that the number is a floor rather than a reported version,
so anything displaying it can say so.

---

## 📊 Cost

Measured, not derived. Earlier versions of this design reasoned from a 0.0125 ms
per-bridge-call figure and had never exercised the transaction machinery with a write.

One **warm node save** through Drupal's entity API:

|                                                                  | value   |
| ---------------------------------------------------------------- | ------- |
| transactions opened                                              | **10**  |
| of which speculative (replay + read + rollback, `commit: false`) | **9**   |
| statements executed inside replays                               | **54**  |
| total host statements for the save                               | **59**  |
| in-PHP save cost                                                 | 9-10 ms |

The first save on a fresh kernel is 18 transactions / 137 replayed statements / 152 total.

So **54 of 59 statement executions in a node save are replays**, and 9 of the 10
transactions are the read-your-own-uncommitted-write path. The O(W\*R) term is real and
dominant in statement count, but the buffers are small — 5.4 statements per transaction —
so it has not become a cost problem. **The number to watch is statements-per-transaction,
not transactions.**

For rendering, the driver costs essentially nothing: a full render measured **34 ms**
against **33.8 ms** for the same render on the old MEMFS/PDO path, which retires the worry
that a bridged driver would be a regression. The cache ladder around it was 1 ms / 26 ms /
34 ms / 81 ms.

The known future cost is the installer: hundreds of rows per transaction is where O(W\*R)
first hurts, and the fix is a per-transaction replay cache keyed on buffer length. The
counters to prove it works already exist.

---

## 📁 Files

| File                            | Lines | What it is                                                                       |
| ------------------------------- | ----- | -------------------------------------------------------------------------------- |
| `Connection.php`                | 945   | extends the core sqlite `Connection`; owns the write buffer and `runStatement()` |
| `SqlAnalyzer.php`               | 488   | classifies statements, names their tables, renames four functions                |
| `CfwSqlClient.php`              | 452   | stands where `\PDO` stands; the only class that talks to the host                |
| `TransactionBuffer.php`         | 286   | the withheld writes, the savepoint marks, the dirty-table set                    |
| `Statement.php`                 | 206   | extends `StatementBase`; one host reply becomes one `PrefetchedResult`           |
| `TransactionManager.php`        | 137   | maps begin/commit/rollback/savepoints onto the buffer, emitting no SQL           |
| `Install/Tasks.php`             | 144   | installability is "the host installed the bridge", not "PDO has a driver"        |
| `Upsert.php`                    | 121   | re-batches by placeholder count under the 100-parameter ceiling                  |
| `ExceptionHandler.php`          | 99    | maps SQLite constraint messages onto `IntegrityConstraintViolationException`     |
| `SqlErrorException.php`         | 55    | carries the engine message; there is no SQLSTATE to classify by                  |
| `Schema.php`                    | 45    | one override: `NOCASE_UTF8` becomes builtin `NOCASE`                             |
| `UncommittedStateException.php` | 21    | a query would have to observe buffered state and cannot                          |
| `HostBridgeException.php`       | 16    | the bridge is missing or returned something unusable                             |

---

## 🎨 Formatting and Coding Standards

**Prettier owns layout. phpcs owns meaning. PHPStan owns types.** Every language in the
repository, PHP included, is laid out by Prettier at **tabs rendered 4 wide, `printWidth`
100, LF, UTF-8** — the house style recorded in `.editorconfig` and `.prettierrc`.

```bash
bun run prettier:check # layout, every language including PHP
bun run lint:php       # phpcs: docs, naming, API misuse
bun run analyze        # phpstan, level 5, --memory-limit=1G
```

`@prettier/plugin-php` and the stock `Drupal` standard cannot both be right about layout,
because the standard hard-codes 2 spaces and Drupal's own brace placement. phpcs loses that
argument, and `phpcs.xml.dist` names each sniff it gives up with the measurement that found
it: with the tree formatted at `useTabs`, phpcs reported **5,275 violations across exactly 9
sniff codes and nothing else**, every one pure whitespace or brace position. Excluding those
nine leaves phpcs checking docblocks, naming, arrays, line length and every Drupal and
DrupalPractice API-misuse rule.

Two further deviations are deliberate and also carry their reason inline: the line limit is
raised from 80 to 100 to match `printWidth`, and the two sniffs that demand capitalised `//`
comments ending in a full stop are excluded to match this project's comment style. Every
`Drupal.Commenting.DocComment.*` sniff stays on, because a `/** */` block is real
documentation.

`phpstan.neon` pins **level 5** over `src/`, matching the reference Drupal module in this
workspace. It must be run with `--memory-limit=1G`: the 128M default OOMs partway through
and reports a small error count that reads like a pass.

## 🧪 Testing

```sh
php tests/lint.php                                  # 17 files, needs nothing but PHP
php tests/run-driver-suite.php /path/to/drupal-11.2 # 132 assertions
php tests/coverage.php /path/to/drupal-11.2         # the same suite, under a coverage driver
```

`tests/coverage.php` wraps the suite rather than replacing it: it starts a coverage driver,
requires `run-driver-suite.php`, and writes `coverage/rom.clover.xml` plus a text summary
from a shutdown handler, because the suite ends in `exit()`. Only `src/` is measured. It
exits **2 without running anything** when the Drupal root or the coverage driver is missing,
so a CI job cannot upload a report it never measured. Current line coverage of `src/` is
**67.16%** (548 of 816 lines).

The suite needs a Drupal 11 checkout with `vendor/` installed and PHP with `pdo_sqlite`. It
reads the Drupal tree, writes nothing, and the database is `sqlite::memory:`. It can be run
from any working directory, because it resolves its own PSR-4 map from `__DIR__`.

**The root has to be a release-tarball layout**, with `vendor/` and `core/` as siblings —
which is what `.github/workflows/build.yml` fetches. A `drupal/recommended-project` install
puts core under `web/` and `vendor/` at the project root, so no single path satisfies both
checks and the suite exits 2 with "Pass a Drupal 11 root with `vendor/` installed". Point it
at a tarball root instead.

| Lane                         | Where it runs             | Count   | Proves                                              |
| ---------------------------- | ------------------------- | ------- | --------------------------------------------------- |
| `tests/run-driver-suite.php` | any PHP with `pdo_sqlite` | **132** | the PHP half, against a stand-in host               |
| `/driver` route              | a live Durable Object     | **32**  | the platform half, against real `ctx.storage.sql`   |
| a rendered front page        | a live Durable Object     | -       | 12,304 bytes, `x-drupal-cache: MISS`, 81 statements |

**What the stand-in host can and cannot prove.** Underneath it is PDO SQLite speaking the
same JSON contract as the Worker's `do-sqlite.js`, so it proves the write buffering, the
read/write overlap analysis, the savepoint truncation, the function substitution, the codec
normalisation and the integration with Drupal's own query builders and schema handling. It
proves **nothing** about the runtime: whether `PRAGMA table_info` is allowed, whether the
engine has `concat()`, or how any of it behaves across events.

That gap has bitten, which is why the fixture now enforces the platform's limits itself.
Local PDO allows 32,766 bound parameters and the host allows 100, and that difference hid
a live cache-write defect behind a green suite. `FakeHost::MAX_PLACEHOLDERS` closes it.

Running the two halves together settled four platform refusals a stand-in could never show,
and exposed three real bugs in this driver, all fixed:

1. **`version()` threw on every call**, because it was `SELECT sqlite_version()`. It now
   catches the refusal and probes for a floor.
2. **`queryTemporary()` surfaced a raw `SQLITE_AUTH`.** It now throws a message naming the
   cause. Auditing core found **zero callers** outside the interface declaration and the
   three driver implementations, and the class still inherits
   `SupportsTemporaryTablesInterface` from the core sqlite driver so an `instanceof` check
   cannot be made to fail — throwing loudly is the only way a caller learns.
3. **A wide integer could not be written at all**, because the codec produces a JS BigInt
   and `sql.exec()` refuses one. The host now converts to a decimal string and SQLite
   applies the column's INTEGER affinity, verified by `typeof(col)` returning `integer`.

---

## 🔄 CI

Default branch is `master`, and every workflow filters on it so a push and its pull request
do not both fire.

| Workflow       | Trigger                   | What it does                                                                                                                                                               | Secrets                                 |
| -------------- | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------- |
| `build.yml`    | push / PR / manual        | `build`: `composer validate --strict`, `php -l`, phpcs and **PHPStan** on 8.3 and 8.4. `e2e` needs `build`, so a PHPStan error blocks the 132-assertion suite from running | none                                    |
| `coverage.yml` | push / PR / manual        | the same suite under pcov, uploading `coverage/rom.clover.xml` to Codecov and posting the summary on the PR                                                                | `CODECOV_TOKEN`                         |
| `prettier.yml` | push / PR                 | `bun run prettier:check`                                                                                                                                                   | none                                    |
| `release.yml`  | manual / nightly schedule | manual: tag, GitHub release and changelog. Nightly: the full gate, then a commit-hash prerelease and a Packagist refresh so `dev-master` points at that commit             | `PACKAGIST_USERNAME`, `PACKAGIST_TOKEN` |

The suite runs with `opcache.enable_cli=0`, matching how the sibling repo runs it, so a
cached class cannot mask an inheritance change.

**The nightly is a build of the tip, not a version.** Its tag is
`nightly-<date>-<short-sha>`, which is deliberately not a valid Composer version, so
Packagist ignores it and `composer require drupflare/rom` is unaffected. To install exactly
that code, require `dev-master`. The nightly skips itself when there were no commits in the
last 24 hours, and it runs lint, phpcs, PHPStan and the driver suite before it publishes
anything: a nightly that ships a red tip is worse than no nightly.

`release.yml`'s Packagist steps are skipped when the two secrets are absent, because
Packagist installs its own push hook when GitHub is connected and the API call is only the
fallback for an account without one.

---

## 🚧 What Remains

In priority order.

1. **Install Drupal onto it.** The real test: hundreds of statements, DDL and DML
   interleaved, most of it inside transactions. Expect the O(W\*R) replay cost to be the
   first thing that hurts and the fix to be a per-transaction replay cache keyed on buffer
   length. Note that the shipping path avoids the installer entirely — a pre-built site is
   replayed instead — so this is about making the driver generally usable, not about
   unblocking the Worker.
2. **Decide what to do about `REGEXP`.** It is reachable from Views and from entity
   queries. The options are a documented functional limitation or moving those comparisons
   into PHP. Neither is a driver fix.
3. **Wire `statementCount()` into the observability story.** With speculative replays, the
   number of host statements diverges from the number of Drupal queries, and the gap is the
   interesting signal.
4. **Decide whether prefixes ever need to work.** A non-empty prefix throws at
   construction, which also means Drupal's own test runner cannot use this driver. One
   Durable Object per site makes that the right trade, but it is a decision rather than an
   oversight.
5. **The suspension hazard.** A suspension point must never land inside a transaction
   replay. It is currently unreachable — the whole save is one synchronous `php._run()` —
   so this is a standing constraint on any future JSPI build rather than a live bug.

---

## 🔗 Related Repositories

| Repository                                                      | What it is                                                                                                         |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| [`drupflare/worker`](https://github.com/drupflare/worker)       | the site: the Worker, the Durable Object, and the `cfwSqlExec` / `cfwSqlTxn` host implementation                   |
| [`drupflare/drupflare`](https://github.com/drupflare/drupflare) | `composer require drupflare/drupflare` - mail, HTTP, images, logging, and other capabilities over Workers bindings |
| [`drupflare/phasm`](https://github.com/drupflare/phasm)         | the PHP-to-WebAssembly build that produces the interpreter this driver runs inside                                 |

This driver does **not** require `drupflare/drupflare`, and that module does not require
this driver — they share no class and no service. They are listed in each other's `suggest`
because a Worker deployment normally wants both, which is what `suggest` is for.

---

## 📄 License

MIT (c) Gregory Mitchell 2026. See [LICENSE](LICENSE).
