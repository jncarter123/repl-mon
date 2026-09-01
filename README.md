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

## Running it in a container

Two services out of one image, in `compose.yaml`:

| | |
|:--|:--|
| `app` | The UI, on FrankenPHP. Also runs the migrations on start. |
| `scheduler` | `schedule:work` — the cron line above, with nothing else on the box to run it. |

The scheduler is not an optional extra. Without it the container is a UI over
an empty history.

```bash
cp docker.env.example docker.env   # mail settings live here
docker compose up -d --build
```

Then `http://localhost:8000/register` for the first operator account, after
which the sign-up page disappears as it does anywhere else.

Everything the monitor owns — its SQLite database and its `APP_KEY` — is on the
`repl-monitor-data` volume. **Back it up.** The key is what decrypts the stored
database passwords; a volume restored without it leaves every pair with
credentials that cannot be read. Set `APP_KEY` in `docker.env` instead if you
would rather hold it yourself; leave it blank and the first run generates one
and says so in the log.

The monitor's own store is deliberately not on a server it watches. A monitor
that goes down with the database is one that cannot tell you the database went
down.

### Next to MaxScale

The container needs to reach the database servers itself; the host having
access does not carry over. If MaxScale is already on a Docker network that can
see them, put this on the same one — the bottom of `compose.yaml` has the
stanza, commented:

```yaml
networks:
  default:
    name: maxscale_default   # docker network ls for the real name
    external: true
```

**Point pairs at the servers, not at the MaxScale listener.** A pair's two
addresses have to be the actual primary and the actual replica: the whole
measurement is "write here, read *there*", and a read that MaxScale routes to
whichever backend it likes — including the primary — measures nothing. Router
ports are the one place these credentials should not go.

### Publishing the image

`.github/workflows/docker.yml` builds and pushes to Docker Hub. It needs one
secret on the repository, `DOCKERHUB_TOKEN` — a Docker Hub access token with
read/write — and a `DOCKERHUB_USERNAME` variable if your Hub namespace is not
the GitHub owner's name.

Those are two different tabs under *Settings → Secrets and variables →
Actions*: the token goes in **Secrets**, the username in **Variables**, and it
has to be a *repository* variable — `vars` cannot see an environment-scoped one
unless the job asks for that environment, and this job does not. A username the
workflow cannot see is not an error: it falls back to the GitHub owner's name
and fails at the login as `incorrect username or password`, which reads like a
bad token. The run prints the username it is about to use, so the log settles
which of the two it is.

```bash
git tag v0.1.0 && git push origin v0.1.0   # publishes 0.1.0, 0.1, latest
```

Or run the workflow by hand from the Actions tab to publish the current commit,
optionally under a name like `edge`. Either way the commit SHA is always one of
the tags, so a running container can be traced back to a line of code.

It builds `linux/amd64` only, because that is what the MaxScale host is. The
`platforms:` line in the workflow takes `linux/arm64` as well if you want to
pull the same image onto an Apple Silicon machine.

On the server, then, there is nothing to build:

```bash
echo "REPL_MONITOR_IMAGE=<namespace>/repl-monitor:0.1.0" > .env   # beside compose.yaml
docker compose pull && docker compose up -d
```

Nothing secret is baked into the image: `.env` and `docker.env` are both in
`.dockerignore`, and the `APP_KEY` is generated on the volume at run time.

### Day to day

```bash
docker compose logs -f scheduler                              # what it is doing every minute
docker compose run --rm app php artisan replication:add-user  # another operator
docker compose run --rm app php artisan replication:test pair-name
docker compose run --rm scheduler check                       # one pass, now
docker compose up -d --build                                  # upgrade; migrations run on start
```

### Behind a reverse proxy

The UI is published on `127.0.0.1:8000` only, on the assumption that something
already terminating TLS goes in front of it. Widen the mapping in
`compose.yaml` if not.

Set `APP_URL` to what the browser sees, **scheme included** —
`https://repl-monitor.example.com`. The container itself only ever speaks plain
http on 8080, so this is what tells the app it is an https site, and getting it
wrong is not subtle: the browser blocks every asset on the page as mixed
content and you get an unstyled login form, with nothing in any server log
saying why.

Two mechanisms carry it, and the second exists because the first is not always
enough. `trustProxies` in `bootstrap/app.php` honours `X-Forwarded-Proto` — the
ordinary case; it trusts any address, which is safe exactly as long as the port
stays on loopback as above. But put a CDN or a load balancer in front of the
proxy that terminates TLS and speaks plain http onward, and the proxy will
truthfully forward `X-Forwarded-Proto: http`, arguing for the wrong scheme. So
an `https://` `APP_URL` also forces the scheme outright
(`AppServiceProvider::configureUrlScheme()`), which is why setting it correctly
is the thing that actually matters here.

If the proxy is itself a container — Traefik, nginx-proxy, Caddy in Docker —
two things bite, and both look identical from outside: a 502, with the proxy's
own dashboard perfectly green, because a proxy does not discover an unreachable
backend until a request arrives.

**Reach it over a network, not over `127.0.0.1`.** A `127.0.0.1:8000` publish
binds the host's loopback and nothing else, so from inside another container it
is unreachable — by the bridge gateway, by `host.docker.internal`, by anything.
Put the proxy on this project's network instead (there is a commented block at
the foot of `compose.yaml` for the reverse case, joining an existing one), drop
the `ports:` mapping entirely, and address the container: `http://app:8080`.

**Say which port.** The image inherits `EXPOSE 80`, `443` and `2019` from
FrankenPHP on top of its own `8080`, and 8080 is the only one listening. A
proxy that discovers backends from Docker cannot pick for you with five to
choose from, so tell it — for Traefik that is
`traefik.http.services.<name>.loadbalancer.server.port=8080`, alongside
`traefik.docker.network` if the container is on more than one network.

Times in the UI are UTC.

---

## Setting up a pair

### 1. Give it a database to beat in

The heartbeat table has to be somewhere that is actually replicated. Either name
an existing replicated schema when you add the pair, or let the app make one for
the purpose — **Set up the heartbeat** on the pair form creates the schema,
creates the table in it, and then proves a beat actually crosses. Same thing from
the command line:

```bash
php artisan replication:provision <pair>
```

If you would rather do it by hand, it is one statement:

```sql
-- on the primary
CREATE DATABASE repl_monitor;
```

Either way, the schema must not be excluded by `binlog_ignore_db` /
`replicate_ignore_db` on either side, or the monitor will correctly and
permanently report the pair as broken. This is the easiest way to set the app up
wrong and the hardest to spot later, which is why the setup step ends by writing
a beat and waiting for it: if it does not arrive, it reads those four variables
and tells you which one is in the way, or that a replication thread is stopped.

This does not configure replication — it assumes your DBAs already have. It
issues no `CHANGE MASTER`, changes no server settings, and its only DDL is
`CREATE ... IF NOT EXISTS`.

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
separately, so a half-working pair tells you which half — then **Set up the
heartbeat**, which creates the schema and table on the primary and confirms the
beat reaches the replica.

Replication carries the DDL across for you. If your setup does not replicate DDL,
tick **Create on the replica as well**, or run:

```bash
php artisan replication:provision <pair> --replica
```

If the monitor's own credentials are not allowed to create the schema, the app
does not ask you for a root password: it prints the `CREATE DATABASE` and `GRANT`
to hand to somebody who has the rights, and you run it again afterwards. It is
safe to run as many times as you like.

Later on, **Verify replication** on a pair's own page repeats just the last step.

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

## Watching the monitor

Email is one way out of this app, and it is the one that fails quietly: a
scheduler that has stopped, an SMTP server that has started refusing mail, or a
pair nobody is on the list for all look exactly like a quiet week. So there is a
second way out, for Icinga or anything else that speaks HTTP:

```
GET /api/health
```

It needs a token, and until one exists the route 404s — it names your pairs and
their state, so it is not something to leave open by accident.

**Generate one on the Health endpoint page**, in the sidebar. It works on the next
request; there is nothing to restart. Give each one the name of whatever is
going to use it, because the list is also the answer to "which of these can I
delete?". Rotating is: generate the new one, move the checks over, delete the
old one — both work in the meantime, so no check ever goes red for a reason
nobody needs to investigate. Deleting the last one switches the endpoint off
again.

The list shows when each token was last polled, which is worth a glance: a token
that has never been used is a check somebody started wiring up and did not
finish.

If you would rather keep the secret in the environment, `REPL_HEALTH_TOKEN` in
`docker.env` still works alongside the generated ones. It is shown on the
dashboard but cannot be rotated from there — this app does not write to its own
environment.

```bash
REPL_HEALTH_TOKEN=$(openssl rand -hex 24)   # optional; the UI is easier
```

Send whichever token as `Authorization: Bearer …`, as `X-Health-Token: …`, or —
for a check command that can only send a URL — as `?token=…`.

```bash
curl -sS -H "X-Health-Token: $TOKEN" http://localhost:8000/api/health
```

```
REPLICATION CRITICAL - 1 broken, 2 healthy of 3 monitored pairs (1 paused)
MONITOR: Nobody would be emailed about analytics: the pair has no recipients of its own and the global list is empty.
CRITICAL: payments is broken — The replica reports no replication at all.
OK: orders is healthy, 0.42s behind
OK: reporting is healthy, 1.1s behind
PAUSED: staging is not being checked
| total=4 enabled=3 paused=1 ok=2 lagging=0 broken=1 unreachable=0 unknown=0 stale=0 max_lag=1.1s oldest_check=31s
```

The first line is the whole verdict, and the status code says the same thing:

| | |
|:--|:--|
| `200` + `REPLICATION OK` | Every enabled pair is healthy and was checked in the last few minutes. |
| `503` + `REPLICATION WARNING` | Lagging, never yet checked, nothing configured to watch, or a pair nobody would be emailed about. |
| `503` + `REPLICATION CRITICAL` | Broken, unreachable, **not checked recently**, or an alert that could not be delivered. |
| `401` | The token is wrong or missing. |
| `404` | No token exists at all, or `?pair=` names a pair that does not exist. |

Lag answers 503 along with everything else, deliberately: a check that reads
nothing but the status code has to see a replica falling behind, or the failure
this endpoint exists to prevent walks straight back in.

**"Not checked recently" is the point of the whole thing.** Every other signal
here is one this app already emails about. That one is the signal it cannot
send: if `replication:check` has not run for `REPL_HEALTH_STALE_AFTER_MINUTES`
(default 5), every status on the dashboard is older than it looks and no email
is coming, however bad things get. Nothing inside the container can notice
that — hence the endpoint.

In Icinga, belt and braces — the status code *and* the string:

```
check_http -H monitor.example.com -u /api/health \
           -k "X-Health-Token: <token>" -s "REPLICATION OK"
```

Add `?pair=<name|id|key>` for a service attached to the host a single pair lives
on; the pair's UUID is the stable one if the name might be edited later.
`?format=json` (or `Accept: application/json`) returns the same report with the
numbers unrounded, for a dashboard or a custom plugin. The `|` line is perfdata
for something that reads the body — `check_http` reports its own and ignores it.

---

## Commands

| Command | |
|:--|:--|
| `replication:check [pair]` | One pass over every enabled pair. This is the scheduled one. |
| `replication:test [pair]` | Connect to both servers and report which grants are missing. |
| `replication:provision [pair] [--replica] [--verify-only]` | Create the heartbeat schema and table, then prove a beat gets across. |
| `replication:install-heartbeat [pair] [--replica]` | Create just the heartbeat table, assuming the schema exists. |
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
