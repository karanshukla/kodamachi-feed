# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer install              # dependencies
vendor/bin/phpunit            # the whole suite
php tempest serve             # dev server
php tempest posts:collect     # the Jetstream collector
php tempest migrate:up        # migrations (--force where there is no TTY)
php tempest feed:stats        # what is indexed, and where the cursor is
```

`vendor/bin/phpunit` is the primary correctness check. There is no build step. `find app tests public -name '*.php' -print0 | xargs -0 -n1 php -l` catches syntax errors faster than booting the framework.

**PHP 8.5 is required.** Tempest 3.2.1 declares `php: ^8.5`, so `composer install` fails outright on 8.4. The Docker base image is pinned to `dunglas/frankenphp:1-php8.5` for the same reason.

**`ext-openssl` is required and is not decoration.** ATProto publishes signing keys as compressed elliptic curve points inside a multibase string, and recovering the full point means a modular square root. `karanshukla/php-atproto-identity` does that by handing the key to OpenSSL exactly as published — RFC 5480 permits a compressed `SubjectPublicKeyInfo` — and only falls back to bignum arithmetic on a build whose OpenSSL refuses one. Without openssl, every authenticated request fails.

**`ext-gmp` is a test dependency only.** `Tests\Support\TestKey` encodes base58 with it so the round trip in the test suite crosses an implementation other than the package's own. Production needs no bignum extension.

## Architecture

An ATProto feed generator for Kodamachi content. It surfaces posts containing `kodamachi` in text or in image alt text. Ported from navyfragen-feed, whose behaviour it deliberately preserves.

### Two processes, one container

PHP cannot hold a WebSocket open across web requests, so ingestion and serving are separate processes. They share one container because they share a SQLite file and a platform volume attaches to a single service: splitting them would mean splitting the database. `docker/supervisord.conf` runs both, `docker/entrypoint.sh` migrates first.

**1. Ingestion (write path)**

`App\Jetstream\JetstreamSubscription` opens a WebSocket to `FEEDGEN_SUBSCRIPTION_ENDPOINT` with `wantedCollections=app.bsky.feed.post`. Jetstream emits plain JSON and filters server-side, so there is no CAR or CBOR decoding. Matching posts go into `posts`; the `time_us` cursor is persisted to `sub_state` every 30 seconds so a reconnect resumes rather than jumping to the live tip.

The event JSON is decoded once, by hand, and matched on the raw array. `Post::fromArray()` is not used on the hot path: this sees every post created on the network, several hundred a second, while matches arrive a handful at a time, so hydrating a typed model per event would spend all of that work on records about to be discarded.

**Liveness.** A half-open TCP connection emits no error and no close, so a dead subscription used to look exactly like a quiet one. Jetstream filtered to `app.bsky.feed.post` still carries every post on the network, so 60s of total silence means the socket is dead. The blocking read is given a 15s timeout; `ConnectionTimeoutException` is not an error, it is how the loop gets a turn to save the cursor, report health, and check for a stall. A health line reports event and match counts every 5 minutes *unconditionally*, including when both are zero. Matching posts are genuinely rare, so "no posts indexed today" is normal and is not on its own evidence of a broken subscription. Check the `jetstream:` line first.

`FeedMaintenance::runDue()` is called from the same loop and returns how long it spent. Backfill searches block the loop for seconds at a time, which would otherwise read as silence and trip the watchdog, so the stall clock is advanced by exactly that amount.

**2. Serving (read path)**

Tempest routes `#[Get]`-attributed controllers in `app/Api`. `FeedService::getFeed()` reads `posts` ordered by `indexed_at DESC, uri DESC` and returns a cursor of the last row's `indexed_at` in milliseconds.

The skeleton is sent with `Cache-Control: private, max-age=60` so no shared cache holds one caller's response for another, and `describeFeedGenerator` with `public, max-age=3600`.

There is no in-process feed cache, unlike the TypeScript version. A per-worker cache would be near-useless under FrankenPHP (any worker may serve any request) and the query is a single indexed range scan over a table that holds tens of rows.

### Two cursors, two units

Do not confuse them.

| Cursor | Unit | Stored in |
|---|---|---|
| Jetstream subscription | microseconds (`time_us`) | `sub_state.cursor` |
| Feed skeleton pagination | milliseconds | returned to the client, compared against `posts.indexed_at` |

`time_us` is read straight off the raw JSON as an int. Do not route it through libphpsky's `CommitEvent::timeUs`, which is a `DateTimeInterface` built from a float division and is not exact at microsecond precision.

### libphpsky specifics

- Output and input classes are flat, not nested: `DescribeFeedGenerator\DescribeFeedGeneratorOutput`, not `DescribeFeedGenerator\Output`. The same applies to `GetFeedSkeletonOutput`, `PutRecordInput`, `DeleteRecordInput`. The published libphpsky-feed example uses the old nested names and will not compile against current `dev-main`.
- Build the meta client with `ATProtoMetaClient::default($client)`. The constructor triggers a deprecation even when you pass a client, and Tempest escalates deprecations to exceptions outside production.
- `ATProtoClientBuilder` points session creation at bsky.social. For an account on a third-party PDS that is wrong, so `ATProtoMetaClientInitializer` assembles `AuthAwareClient` by hand with `CreateSession`/`RefreshSession` aimed via `withEndpoint()`.
- The Jetstream client in libphpsky has no reconnect, no read timeout, and picks a host at random, so `App\Jetstream` does not use it. Its native `subscribeRepos` subscription has the same gaps, and decodes CBOR for every commit on the network rather than letting Jetstream filter server-side.
- Every action defaults to `https://bsky.social`. Anything that touches the publisher's repo needs `withEndpoint($config->pdsUrl)`, or an account on a third-party PDS gets its writes sent to the wrong server.
- Hydrating a `PostView` drops the record's `embed`, which is where alt text lives. `BackfillService` uses `rawQuery()` and matches on the raw record for that reason.
- `uploadBlob` takes no request body, so `FeedService` uploads the avatar as a raw PSR-7 request through `$metaClient->getClient()`, which still attaches the session.

### Tempest specifics

- `updateOrCreate()` needs a model with a primary column and does not work against a bare table name. Use an existence check plus `insert()`/`update()`.
- Config file paths resolve against the *working directory*, which is the project root for the console and `public/` for the web server. `app/Config/db.config.php` resolves the SQLite path against the project root explicitly, otherwise you get two different databases.
- `composer.json` must exist in the runtime image. Tempest reads it at boot to work out discovery locations.
- Outside `ENVIRONMENT=production`, any non-2xx response is replaced by the HTML debug page, JSON body and all. Test error responses with `ENVIRONMENT=production`.
- Session, cookie and previous-URL middleware are removed in `App\Framework\DisableFrameworkMiddleware`. This is a machine-to-machine JSON API.

### Supervisor specifics

`supervisor.rpcinterface_factory` must be written with a colon (`supervisor.rpcinterface:make_main_rpcinterface`). Supervisor 4.2 resolves it through `pkg_resources`, and setuptools 78 reads a fully dotted spec as a module path, so the documented dotted form fails at boot with `cannot be resolved`. Without that section `supervisorctl status` does not work, and there is then no way to ask a running container whether the collector is alive.

### Database

Four tables, all in `app/Database`:

| Table | Purpose |
|---|---|
| `posts` | `uri` (unique), `cid`, `indexed_at` (ms, indexed) |
| `sub_state` | one row per subscription endpoint, holding the Jetstream cursor |
| `did_documents` | resolved DID documents, with `fetched_at` |
| `rate_limits` | fixed-window counters |

The collector sets `journal_mode = WAL` on start. The mode is stored in the file, so it covers the web process too, and it lets web workers read while the collector writes.

The last two are in the database rather than in process memory because any worker may serve any request: an in-process cache would re-resolve the same DID on every request, and an in-process counter would give each worker its own allowance and enforce nothing in aggregate.

**Production requirement:** `FEEDGEN_SQLITE_LOCATION` must point at a mounted volume. Railway's filesystem is ephemeral, so anything else is wiped on each deploy, taking the index and the cursor with it.

### Rate limiting

| Layer | Limit | Key |
|---|---|---|
| `ThrottleMiddleware` | 3000 / 15 min | client address |
| `GetFeedSkeletonController` | 100 / min | requester DID, or client address when anonymous |

The client address is the *last* `X-Forwarded-For` entry, the one Railway's edge appended, matching Express's `trust proxy 1` in the TypeScript version. Everything before it is caller-supplied, and trusting the first entry let any caller choose a fresh bucket per request.

`getFeedSkeleton` is called by the AppView server-side, so the address-keyed buckets are shared across every viewer behind that AppView rather than being one person's budget. Keep them well above real traffic. A tripped limit shows the user whatever they already had, which reads as "the feed stopped updating" rather than as an error, so a limit that is too tight is worse than no limit.

The TypeScript version also had an `express-slow-down` layer. It was not ported: the AppView times out a slow feed generator, so added latency produces an empty feed rather than backpressure.

### Auth

`App\ServiceAuth` verifies the ATProto service-auth JWT, and is now only the protocol half of that: the claim checks and the key-rotation handling. DID resolution and did:key decoding moved out to [`karanshukla/php-atproto-identity`](https://github.com/karanshukla/php-atproto-identity), which is where `DidDocumentResolver`, `DidDocumentCache`, `HttpDidDocumentResolver` and `VerificationKey` now come from. The verifier itself is still free of Tempest and of application types, so it can be lifted into libphpsky as a PR ([aazsamir/libphpsky#7](https://github.com/aazsamir/libphpsky/pull/7) does exactly that). Tempest-specific implementations stay in `app/Framework`: `DatabaseDidDocumentCache` backs the package's cache interface with the `did_documents` table, and `ServiceAuthVerifierInitializer` wires it up.

Failures from the identity layer are wrapped in `ServiceAuthException` at the verifier boundary, so callers still catch one type.

**Auth is not mandatory** (`FEEDGEN_REQUIRE_AUTH` defaults to `false`) and should stay that way unless something makes the response requester-dependent. The skeleton is byte-identical for every caller, so requiring auth adds no privacy and only decides which clients can load the feed at all. Anything that 401s a whole client shows its users a permanently empty feed, which is indistinguishable from the feed being broken.

Three traps live in this path:

- **`lxm`.** The claim postdates the original service-auth spec. A token minted without it comes from an older implementation, not for the wrong method, so `ServiceAuthVerifier` tolerates an absent claim and rejects a present-and-wrong one.
- **`aud`.** It must equal the service DID. That DID is derived from `FEEDGEN_HOSTNAME` as `did:web:$hostname` rather than generated, so it survives a redeploy. A service DID that changes on each boot fails every authenticated request afterwards.
- **Key rotation.** A rotated signing key invalidates every cached DID document. When a signature fails against every key in the cached document, the document is refetched once and the signature retried. Only a signature mismatch earns that second resolution: an expired token will not verify against a fresher document either.

Auth failures log the JWT's claimed issuer, decoded without verification, so an account-specific failure is visible in the logs rather than looking like a stale feed.
