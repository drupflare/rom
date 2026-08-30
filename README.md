# 💾 rom

> Drupal 11 database driver for Durable Object SQLite

[![Build](https://github.com/drupflare/rom/actions/workflows/build.yml/badge.svg)](https://github.com/drupflare/rom/actions/workflows/build.yml)
[![Coverage](https://github.com/drupflare/rom/actions/workflows/coverage.yml/badge.svg)](https://github.com/drupflare/rom/actions/workflows/coverage.yml)
[![Prettier](https://github.com/drupflare/rom/actions/workflows/prettier.yml/badge.svg)](https://github.com/drupflare/rom/actions/workflows/prettier.yml)
[![Packagist](https://img.shields.io/packagist/v/drupflare/rom)](https://packagist.org/packages/drupflare/rom)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

rom connects Drupal 11 to `ctx.storage.sql`, the SQLite database inside a Cloudflare Durable
Object. Drupal's query builders, schema handling and condition compiler are used unchanged; the
driver replaces everything that assumed PDO and a file on disk.

The package is `drupflare/rom`; the driver directory and module machine name are both
`cfw_do_sqlite`, which is the value `settings.php` refers to.

> [!IMPORTANT]
> **Requires Drupal 11.2 or newer.** The statement class extends `StatementBase` and returns a
> `PrefetchedResult` with a `FetchAs` fetch mode; all three first exist in 11.2.0.

> [!CAUTION]
> **This is not a general-purpose SQLite driver.** It targets one engine reached through one
> bridge: `ctx.storage.sql`, called through host functions the Worker installs. There is no PDO,
> no file path, no connection string and no second database to attach. On a normal host it cannot
> connect; `Install\Tasks` reports the driver uninstallable when the bridge is absent. For SQLite
> on a filesystem, use Drupal core's `sqlite` driver, which this one extends.
> [Platform Limits](#-platform-limits) are properties of the engine, not tunables.

---

## 📋 Table of Contents

- [Why a New Driver](#-why-a-new-driver)
- [How It Works](#-how-it-works)
- [Host Contract](#-host-contract)
- [Installation](#-installation)
- [Table Prefixes](#-table-prefixes)
- [Partitioned Rowids](#-partitioned-rowids)
- [Platform Limits](#-platform-limits)
- [Design Limits](#-design-limits)
- [SQL Function and Collation Audit](#-sql-function-and-collation-audit)
- [Cost](#-cost)
- [Related Repositories](#-related-repositories)
- [Contributing](#-contributing)
- [License](#-license)

---

## 🎯 Why a New Driver

A Durable Object's SQLite suits a small Drupal site: it is strongly consistent, it lives in the
same isolate as the code, and reads are synchronous, so PHP's blocking database calls compose with
it. Drupal's own SQLite driver still cannot connect to it, for three reasons that are not
configuration:

1. **There is no PDO.** The core sqlite `Connection` types its client as `\PDO` and
   attaches one database file per prefix. There is no driver to find and no file to
   attach.
2. **There are no user-defined functions or collations.** The core driver registers
   `GREATEST`, `LEAST`, `RAND`, `IF`, `LENGTH`, `MD5`, `SUBSTRING_INDEX`, `REGEXP` and a
   `NOCASE_UTF8` collation through PDO. Without collations every `CREATE TABLE` core emits fails
   with `no such collation sequence: NOCASE_UTF8: SQLITE_ERROR_MISSING_COLLSEQ`.
3. **`BEGIN` is refused outright**, with the runtime telling you to use
   `state.storage.transactionSync()` instead. `SAVEPOINT`, `COMMIT` and `ROLLBACK` are the
   same family, so Drupal's begin-commit API has nothing to map onto.

The third has no workaround. `transactionSync(cb)` is callback-scoped and driven from JavaScript,
while Drupal's API is begin-then-commit; without a suspension mechanism PHP cannot call into a
callback-scoped API and resume. The transaction scope therefore has to be inverted or deferred.
rom defers.

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

A read is clean when none of the tables it references has a buffered write. `SqlAnalyzer` decides
this and over-approximates in every uncertain direction: an unclassifiable statement, an unpinnable
write target or a `RENAME` marks everything dirty. Over-approximating costs a read resolved the
expensive way; under-approximating returns wrong data silently.

DDL additionally dirties a pseudo-table `sqlite_master`, so `tableExists()`,
`findTables()` and `PRAGMA table_info()` collide with a buffered `CREATE`/`DROP` and get
resolved through the replay instead of reading stale schema. Tested: inside a transaction
`tableExists('late')` is TRUE for a table the host has never seen, and FALSE again after
the rollback.

Reads inside a transaction are the hard case, not commits. Committing is replaying a list. Code
that writes a row and reads it back before committing is resolved by replaying the buffer inside a
transaction that is then rolled back; where that is not possible, the driver refuses rather than
guessing.

Every speculative replay returns one result per statement it ran, not just the one asked about, and
`TransactionBuffer` caches them. A replay always starts from the same committed state and runs
`buffer[0..k]` in order, so the result of statement _i_ depends only on the buffer's first _i+1_
entries, and those entries do not change while buffered. Rolling back to a savepoint truncates the
buffer, so `rollbackTo()` drops the cached answers for the discarded tail; otherwise a fresh
statement written at a discarded index would inherit the discarded one's row count.

The cache removes repeated resolutions: a second `lastInsertId()` for the same buffered insert, and
a deferred `Statement::rowCount()` for any write a later dirty read has already replayed past. A
dirty read replays the whole buffer, so it resolves every outstanding id and row count as a side
effect. It does not remove the first resolution of a newly buffered statement, and it does not
remove a dirty read, which must be evaluated inside a transaction with the buffer applied. The
`N(N+1)` figure in [Cost](#-cost) for alternating write-then-read pairs is unchanged.

A buffered write reports success before it runs, so a statement SQLite would refuse sits in the
buffer until a later replay trips over it. Left there it is fatal twice over: every subsequent
replay re-runs it, and so does the commit, so the transaction can never succeed even after the
reason for the refusal is gone.

It's the exact shape of Drupal core's own lazy-table idiom (write, catch the failure, create the
table, carry on), which `Core\Routing\MatcherDumper::dump()` runs **inside a transaction**. A stock `standard`
install died there: `DELETE FROM router` was buffered instead of failing, so `ensureTableExists()` never
fired, the `router` table was never created, and the buffered delete then poisoned every replay and the commit.

`Connection::speculate()` restores the real semantics: on a real connection that statement
would have failed where it was issued and left nothing behind, so the driver finds it,
discards it, and raises its error once.

The host reports one error for the whole replay and never says which statement raised it. The
position is recovered by bisection: the shortest prefix that still fails. Three things bound the
search.

- A prefix an earlier replay already resolved is skipped. The committed state cannot change
  while the buffer is open, so a replay of the same prefix is deterministic and a prefix
  that has succeeded once cannot be the culprit.
- Every probe is a speculative replay and is **counted as one**, so the counters do not hide
  the repair. Measured: four buffered writes with one bad third costs four host
  transactions to place it, and the suite asserts that number.
- When the trailing read is what SQLite refused, the buffer replays cleanly and **nothing is
  discarded**. That control is asserted too; without it the repair would also pass on a
  driver that threw a statement away whenever anything went wrong.

The slot is marked rather than removed, so a buffer index already handed to a `Statement`
still names the same statement, and a replay maps its results back by position. Attribution is
the limit: the failure is delivered to whichever caller triggered the replay, which is the failing
statement only when its own id or row count was requested. Anything other than a missing table (a
constraint violation, for instance) surfaces at the replay or the commit rather than at
`execute()`. Both are consequences of deferring writes.

A suspension point cannot land inside a replay: the whole save is one synchronous `php._run()`,
and the host brackets its bridge calls with `zend_wasm_slice_mask(1)`/`(0)` from `phasm`'s VM
interrupt patch. This is a standing constraint on any future JSPI build: one that can suspend
mid-`transactionSync()` would break the atomicity this driver depends on.

The constructor calls the **grandparent** `Drupal\Core\Database\Connection::__construct()`
directly, skipping the sqlite constructor's `\PDO` type and its per-prefix attach. That is
legal PHP in object context and was verified before being relied on. `__destruct()` is
overridden the same way so the sqlite destructor never tries to `unlink()` a database file
after a `DROP TABLE`.

The table prefix comes from the grandparent.
`Drupal\Core\Database\Connection::setPrefix()` folds the prefix into the identifier, so
`{node}` resolves to `"myprefix_node"` in the one database this Durable Object owns. The
sqlite constructor would instead have ATTACHed a second database and emitted
`"prefix"."table"`, which has no analogue here. See
[Table Prefixes](#-table-prefixes).

`getAttachedDatabases()` returns a synthetic `['main' => 'main']`. `Schema::findTables()` iterates
it while the real `$attachedDatabases` property stays empty, so the destructor's prune loop is
never entered.

Everything else is inherited. `Select`, `Insert`, `Truncate`, the condition compiler, the
type map and the table-rebuild dance all come from
`Drupal\sqlite\Driver\Database\sqlite`, because the engine underneath genuinely is SQLite.
Two exceptions: `Schema` overrides one method to substitute the collation and one to fix
`findTables()` under a prefix, and `Upsert` is overridden because of the bound-parameter
ceiling below.

---

## 🔗 Host Contract

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
host runs the statements and then throws a private sentinel so the runtime rolls back, and still
returns `results`. That is what makes the speculative row count and insert id work. `read` is evaluated
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
`.../rom`, because `composer.json` sets `extra.installer-name`. The package is named
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
`database` is a label recorded for reference and routes nothing. `prefix` is honoured, with
one character's worth of exception; see the next section. `lane` and `lanes` are optional and
default to 0; see [Partitioned Rowids](#-partitioned-rowids).

---

## 🔖 Table Prefixes

`'prefix' => 'site1_'` works. `{node}` becomes `"site1_node"`, and two connections to the
same Durable Object with different prefixes do not see each other's tables — asserted, over
one shared host, because a fixture per connection would make isolation trivially true and
prove nothing.

**The mechanism is not core sqlite's.** That driver implements a prefix with
`ATTACH DATABASE`: it attaches one file per prefix, appends a `.`, and emits
`"prefix"."table"`. `ctx.storage.sql` is one database per Durable Object with no `ATTACH`
and no files, so there is nothing to attach and no schema to name. What does have an
analogue is the mechanism in the **base** `Connection`, which every non-sqlite driver
already uses: `setPrefix()` folds the prefix into the identifier itself. This driver calls
the grandparent constructor, so that is the implementation it gets — not as a workaround,
but as the one of core's two prefix mechanisms that this engine can support.

| Piece                                        | Under a prefix                                                              |
| -------------------------------------------- | --------------------------------------------------------------------------- |
| `{curly}` placeholders                       | inherited; `prefixTables()` already mangles                                 |
| `Select` / `Insert` / `Update` / `Upsert`    | inherited; they only ever emit `{table}`                                    |
| `Schema::createTable` / `renameTable` / drop | inherited; `getPrefixInfo()` puts the prefix on the front, which is correct |
| index create / exists / drop                 | inherited; index names are already `table_key`, so they mangle with it      |
| `getFullQualifiedTableName()`                | inherited from core sqlite, which returns `prefix . table`                  |
| `Schema::findTables()`                       | **replaced.** See below                                                     |

`findTables()` is overridden. The core sqlite version matches `$table_expression` against the bare
name in `sqlite_master`, since a prefixed table there lives in its own attached schema. Here the
name in `sqlite_master` is the prefixed name, so the inherited method returns an empty result under
a non-empty prefix rather than an error. The override applies the prefix to the pattern and strips
it from the results.

The `LIKE` narrows and PHP confirms the match, since an underscore in a prefix is a `LIKE` wildcard
and can only over-select. The pattern is length-checked against `MAX_LIKE_PATTERN_BYTES`: the
prefix counts towards the platform's 50-byte ceiling, so a long prefix can push a short expression
over on its own.

A period in a prefix is refused at construction. Core validates a prefix as `[A-Za-z0-9_.]`,
where the period is a schema selector: a database in MySQL, a schema in PostgreSQL, an attached
file in core sqlite. None of those exist here.
`Install\Tasks::validateDatabaseSettings()` rejects it at the install form as well, so the
installer reports the problem instead of fataling.

---

## 🧮 Partitioned Rowids

`'lane' => 2, 'lanes' => 3` makes this connection mint only rowids congruent to 2 modulo 4.
Both default to 0, which mints every id and is the arithmetic every other section describes.

It is for a host that runs several Durable Objects behind one site where only one of them
commits. A secondary runs the write, reads through its own buffer, rolls back, and hands the
statement list to the primary to replay. `lastInsertId()` has already gone into a redirect by
then, and the primary's SQLite assigns by its own maximum, so the two disagree and nothing
errors.

A non-zero `lane` changes two things:

- `lastInsertId()` for a buffered insert answers with the next id in this connection's residue
  class rather than the next id, so no two connections in the pool can name the same one.
- A plain `INSERT INTO t (cols) VALUES (...)` is rewritten to name the table's integer primary
  key and carry that value, so the id the caller was told is the id that gets committed.

The value is spliced in as a decimal literal, so the ordinals of the existing placeholders do
not move. Statements the driver does not parse as a plain single-row insert are left alone. The
sequence gains gaps, which Drupal tolerates because an entity id is opaque.

This is `auto_increment_offset` and `auto_increment_increment` from multi-primary MySQL.

---

## 🧱 Platform Limits

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
for exactly that reason. `Schema::findTables()` is the one place the driver builds a `LIKE`
pattern itself, and it checks the same ceiling, because a table prefix is prepended to the
caller's expression and counts towards those 50 bytes.

**Wide-integer reads cannot be fixed at driver level.** `ctx.storage.sql` hands INTEGER
columns back as JS doubles, so precision is gone before anything in PHP can see it; the
codec then carries the wrong number faithfully. Storage is exact — `CAST(col AS TEXT)`
returns all the digits — so a fix would mean selecting wide columns as text, which needs
schema knowledge the driver does not have at query time. Drupal core never stores integers
that wide; the exposure is contrib holding 64-bit ids.

---

## 🚫 Design Limits

In descending order of how likely you are to hit it.

1. **A read that joins buffered rows against committed ones is only correct through the
   replay.** With `cfwSqlTxn` present it is correct, because the join runs inside a real
   transaction with the writes applied. Without it, the read is refused. There is no third
   behaviour.
2. **Cost is quadratic in the worst case.** Each dirty read replays the whole buffer, so a
   transaction with W writes and R dirty reads executes O(W\*R) statements inside the
   Durable Object. See [Cost](#-cost) for what that actually measured.
3. **A non-deterministic write replays differently.** `random()`, `CURRENT_TIMESTAMP` or an
   implicit rowid can take one value during a speculative read and another during the
   commit replay. Drupal supplies timestamps from PHP rather than SQL, so the exposure is
   narrow — and a rowid from `lastInsertId()` matches the committed one only because the
   Durable Object gate serialises events so no other writer can advance the sequence in
   between. If that gate is ever removed, this breaks. The replay cache _freezes_ the first
   value a statement produced instead of letting a later replay produce a different one,
   which makes the driver's own answers self-consistent; it does not make them match a
   commit replay that rolls the dice again.
4. **A savepoint is a buffer index, not a database savepoint.** Rolling back to one
   truncates the list, and releasing one releases every savepoint after it, matching
   SQLite. For the way Drupal uses savepoints — nested `Transaction` objects — the list is
   the whole state. It is also the only way the buffer shrinks, and the only thing that
   invalidates a cached replay result.
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
`SELECT 'GREATEST('` survives it. Functions with no exact builtin are absent
from the map; mapping one onto a function that behaves differently produces silent wrong answers.

`LENGTH()` changes meaning. Core overrides it with PHP's `strlen()`, so it counts bytes;
SQLite's builtin counts characters on TEXT. `CommentStorage` uses it on thread strings like
`01.02/`, which are ASCII, so the two agree there. Any other caller comparing lengths of
multibyte text gets a different answer than on MySQL, and it is not fixable without user
functions.

`LIKE BINARY` is supported. `Condition::compile()` emits
`field OPERATOR prefix placeholder postfix`, so a marker placed in the operator's `prefix` lands
immediately before the placeholder and identifies which bound argument is the pattern.
`Connection::translateLikeBinary()` rewrites that argument with `SqlAnalyzer::likeToGlob()` before
anything else sees the statement and strips the marker. Core's `ESCAPE '\'` postfix is dropped,
since builtin `GLOB` refuses a third argument.

**9,000 differential cases agree with core's own `sqlFunctionLikeBinary()`**, with a control
proving the untranslated form disagrees, so case-sensitive `STARTS_WITH` / `CONTAINS` /
`ENDS_WITH` are available. Every case used a pattern of at most 5 characters, so long patterns are
covered by the 50-byte refusal rather than by the differential suite.

The engine floor is established by **feature probe**, not by asking, because
`sqlite_version()` is refused. It reports 3.46.0, proven by `unhex()` which landed in
3.46. That matters more than it looks: Drupal 11.4.5 gates installation on 3.45, and
`concat` — the obvious probe — only proves 3.44 and would have **failed** the gate.
`engineVersionIsFloor()` reports that the number is a floor rather than a reported version,
so anything displaying it can say so.

---

## 📊 Cost

One warm node save through Drupal's entity API:

|                                                                  | value   |
| ---------------------------------------------------------------- | ------- |
| transactions opened                                              | **10**  |
| of which speculative (replay + read + rollback, `commit: false`) | **9**   |
| statements executed inside replays                               | **54**  |
| total host statements for the save                               | **59**  |
| in-PHP save cost                                                 | 9-10 ms |

The first save on a fresh kernel is 18 transactions / 137 replayed statements / 152 total.

54 of 59 statement executions in a node save are replays, and 9 of the 10 transactions are the
read-your-own-uncommitted-write path. The O(W\*R) term dominates statement count, but the buffers
are small at 5.4 statements per transaction. Statements-per-transaction is the figure that predicts
cost, not transaction count.

Rendering is unaffected: a full render measures 34 ms against 33.8 ms for the same render
on the MEMFS/PDO path. The cache ladder around it is 1 ms / 26 ms / 34 ms / 81 ms.

### Installers

`tests/run-installer.php` drives `install_drupal()` through this driver and installs the whole
`standard` profile:

|                                                 | value                        |
| ----------------------------------------------- | ---------------------------- |
| statements the host executed                    | **41,170**                   |
| of which single-statement bridge calls          | **2,220**                    |
| host transactions                               | **401**                      |
| of which speculative (replayed and rolled back) | **394**                      |
| statements executed inside those replays        | **37,814**                   |
| widest transaction                              | **380 statements**           |
| errors Drupal raised and recovered from         | 18                           |
| result                                          | 39 tables, 939 rows, HTTP200 |

**92% of everything the engine executed was a replay** — 37,814 of 41,170. The node-save figure
above (54 of 59) is the same ratio at a smaller scale; at 380 statements per transaction it is the
whole cost. Hundreds of rows per transaction is where O(W\*R) first hurts.

The resulting site matches the one core's own sqlite driver builds: same 39 tables, same 939 rows,
one row of difference (`system.schema` for `cfw_do_sqlite`), and the front page renders 11,521
bytes with HTTP 200. The harness asserts that against a control install rather than a hard-coded
table list.

Reducing the cost means fewer _resolutions_, not a faster bridge. `Insert::execute()` asks for
`lastInsertId()` immediately after buffering each row, and Drupal's multi-row `Insert::execute()`
discards every id but the last while still paying a replay for each.

The replay cache does not move that number. It removes repeated resolutions —
a second `lastInsertId()` for the same buffered insert, a deferred `rowCount()` a dirty
read has already replayed past. It cannot remove the _first_ resolution of a newly
buffered statement, because the newest buffer index is by definition the one no earlier
replay covered, and `Insert::execute()` asks for `lastInsertId()` immediately after
buffering each row. So the alternating write-then-read pair stays at `N(N+1)`, the suite
still asserts **12 for N=3**, and the installer's cost is unchanged until something reduces
the number of resolutions rather than their repeat rate.

`CfwSqlClient` holds `$statementCount`, `$transactionCount`, `$speculativeCount` and
`$replayedStatementCount`, surfaced on `Connection`. Each is asserted against `FakeHost`'s own
count of the same thing, since a counter asserted against itself proves nothing. The installer
figures are read directly off them.

They are wired into an observability story rather than only into tests:
`worker/src/drupal/site-php.ts:1316` reads `statementCount()` into `$out['statementCount']`
on the `/driver` route. The four counters live on the `CfwSqlClient`, so they are per-connection,
and Drupal opens more than one connection across an install. `FakeHost`'s counters are
process-wide, so the harness compares the two rather than assuming they match.

---

## 🔗 Related Repositories

| Repository                                                      | What it is                                                                                                         |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| [`drupflare/worker`](https://github.com/drupflare/worker)       | the site: the Worker, the Durable Object, and the `cfwSqlExec` / `cfwSqlTxn` host implementation                   |
| [`drupflare/drupflare`](https://github.com/drupflare/drupflare) | `composer require drupflare/drupflare` - mail, HTTP, images, logging, and other capabilities over Workers bindings |
| [`drupflare/phasm`](https://github.com/drupflare/phasm)         | the PHP-to-WebAssembly build that produces the interpreter this driver runs inside                                 |

This driver does **not** require `drupflare/drupflare`, and that module does not require
this driver — they share no class and no service. They are listed in each other's `suggest`
because a Worker deployment normally wants both.

---

## 🤝 Contributing

Clone the repositories as siblings and point Composer at the local checkout with a path
repository; it symlinks, so edits are live with no reinstall.

```sh
composer install
composer run lint       # phpcs: docs, naming, API misuse
composer run analyze    # phpstan level 5, --memory-limit=1G
bunx prettier --check . # layout, every language including PHP

DRUPAL_ROOT=/path/to/drupal php tests/run-driver-suite.php
php tests/run-installer.php # 16; installs Drupal for real
```

---

## 📄 License

MIT (c) Gregory Mitchell 2026. See [LICENSE](LICENSE).
