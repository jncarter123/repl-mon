# repl-monitor

A monitor for MariaDB primary/replica pairs. It writes a heartbeat on each
primary once a minute, reads it back off the replica, and emails whoever is on
the list when the replica falls behind, stops replicating, or stops answering.

---

## How it decides something is wrong

Two independent signals per pair, per minute:

**1. The heartbeat.** One row per pair in a small table that is inside
replication. Every minute the monitor upserts that row on the primary with a
timestamp taken from *its own* clock, then reads the row back off the replica
and compares it to *its own* clock again. Neither database server's clock enters
the arithmetic, so a server with a drifting clock cannot produce phantom lag —
which is the usual failure of `Seconds_Behind_Source`.

After writing a beat the monitor waits up to two seconds for that beat to show
up on the replica before measuring. Without that window a perfectly healthy pair
reads as a full minute behind on every check, because the newest row on the
replica is still the previous minute's beat. The wait ends the instant the beat
arrives, so a healthy pair costs one extra query and a genuinely lagging pair
falls through to the last beat that *did* arrive — which is the number you want.

**2. `SHOW REPLICA STATUS`.** A stopped IO or SQL thread is caught the moment it
stops, rather than a threshold's worth of minutes later when the heartbeat
finally goes stale. Being refused this grant is recorded and shown, but is not
itself treated as a fault: the heartbeat answers the question on its own.

The verdict, worst first:

| Status | Meaning |
|:--|:--|
| **Unreachable** | One of the two servers did not answer. |
| **Broken** | A replication thread is not running, the replica reports no replication at all, or no beat has ever arrived. |
| **Lagging** | The beat arrived, but older than the pair's threshold. |
| **Healthy** | The beat arrived inside the threshold. |

---

## Getting started

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# point DB_* at this app's own database, then:
php artisan migrate
```

Visit `/register` to create the first operator account. **The sign-up page then
disappears** — this app stores credentials for production database servers, so
open registration is not something it can have. Later accounts:

```bash
php artisan replication:add-user --name="Jane" --email=jane@example.com
```

### The one line that makes it a monitor

```cron
* * * * * cd /path/to/repl-monitor && php artisan schedule:run >> /dev/null 2>&1
```

That drives `replication:check` every minute and `replication:prune` nightly.
Without it the app is a UI over an empty history.

---

## Setting up a pair

### 1. Give it a database to beat in

The heartbeat table has to be somewhere that is actually replicated. Either use
an existing replicated schema or make one for the purpose:

```sql
-- on the primary
CREATE DATABASE repl_monitor;
```

Make sure it is not excluded by `binlog_ignore_db` / `replicate_ignore_db` on
either side, or the monitor will correctly and permanently report it as broken.

### 2. Grant the monitor user

On the **primary** — it needs to write the beat, and `CREATE` only if you want
the app to make the table for you:

```sql
CREATE USER 'repl_monitor'@'10.0.0.%' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, CREATE ON repl_monitor.* TO 'repl_monitor'@'10.0.0.%';
```

On the **replica** — it needs to read the beat, plus the privilege that makes
`SHOW REPLICA STATUS` readable:

```sql
CREATE USER 'repl_monitor'@'10.0.1.%' IDENTIFIED BY '…';
GRANT SELECT ON repl_monitor.* TO 'repl_monitor'@'10.0.1.%';

-- MariaDB 10.5.9+
GRANT REPLICA MONITOR ON *.* TO 'repl_monitor'@'10.0.1.%';
-- older MariaDB, or MySQL
GRANT REPLICATION CLIENT ON *.* TO 'repl_monitor'@'10.0.1.%';
```

Nothing here needs write access to your data, and nothing needs `SUPER`.

### 3. Add the pair

**Server pairs → Add a pair.** Fill in both sides, use **Test connection** on
each — it reports connecting, the heartbeat table and the status grant
separately, so a half-working pair tells you which half — then **Create it on
the primary** to make the heartbeat table. Replication carries the DDL to the
replica; if your setup does not replicate DDL, run:

```bash
php artisan replication:install-heartbeat <pair> --replica
```

### 4. Say who gets told

**Recipients** is the global list. A pair can name its own recipients on its own
page, in which case it uses *those instead of* the global list, not as well as.
Both the pair page and the recipients page say so on screen, and warn when a
pair would have nobody to email.

---

## Alerting

Configured per pair, so a reporting replica and a payments replica do not have
to share a temperament:

- **Lag threshold** — seconds behind before the pair counts as lagging.
- **Failures before alerting** — consecutive bad checks. `2` rides out a single
  slow minute; `1` tells you about it.
- **Remind every** — minutes between reminders while an outage continues. `0`
  sends one email per outage and no reminders.

On top of that: a problem that gets *worse* (lagging → broken) always sends
immediately, because that is new information; and a recovery always sends once,
so nobody drives in to check.

Every email is recorded — subject, recipients, and any delivery failure — on the
pair's page and on the dashboard. An alert nobody can prove was sent is the same
as no alert.

---

## Commands

| Command | |
|:--|:--|
| `replication:check [pair]` | One pass over every enabled pair. This is the scheduled one. |
| `replication:test [pair]` | Connect to both servers and report which grants are missing. |
| `replication:install-heartbeat [pair] [--replica]` | Create the heartbeat table. |
| `replication:prune` | Trim check history (default 14 days); alerts are kept a year. |
| `replication:add-user` | Create an operator account. |

---

## Notes on what is stored

- Database passwords are encrypted at rest with `APP_KEY` and are never sent
  back to the browser. A blank password field on the edit form means "leave it
  alone"; clearing one takes an explicit switch.
- Error text from PDO is scrubbed of the pair's own credentials before it is
  written to the check history, shown on screen, or put in an email.
- The heartbeat table name is validated as a plain identifier in the form *and*
  again at the connection layer, because it is interpolated into SQL where no
  placeholder is possible.
- Deleting a pair takes its history and its own recipients with it. The
  heartbeat row on your primary is left where it is — deleting from your
  database is not this app's call to make.

## Demo rows

The dev database ships with three throwaway pairs (`demo-in-sync`,
`demo-no-heartbeat`, `demo-unreachable`) pointed at the local MariaDB so the
dashboard has something to show. Delete them whenever.

## Checks

```bash
composer test   # pint --test, phpstan level 7, pest
```
