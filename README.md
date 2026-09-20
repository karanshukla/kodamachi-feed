# kodamachi-feed

A Bluesky feed generator for Kodamachi, built on [libphpsky](https://github.com/aazsamir/libphpsky) and [Tempest](https://github.com/tempestphp/tempest-framework). It indexes posts that mention `kodamachi` in their text or in image alt text, and serves them back to the AppView as a feed skeleton.

Structurally it is a port of [navyfragen-feed](https://github.com/karanshukla/navyfragen-feed) from TypeScript to PHP, carrying over the ingestion watchdog, the backfill, the rate limiting, and the service-auth handling.

## Requirements

PHP 8.5 or newer, with `openssl`, `pdo_sqlite`, `mbstring`, `intl` and `pcntl`. Tempest 3.2.1 requires 8.5. `openssl` is not optional: service-auth signing keys are published as compressed elliptic curve points, and recovering one is OpenSSL's job. No bignum extension is required; `gmp` is used by the test suite, and the Docker image keeps it only as a fallback safety net.

## Running it locally

```bash
composer install
cp .env.example .env          # then set FEEDGEN_PUBLISHER_DID
php tempest migrate:up
php tempest serve             # the API
php tempest posts:collect     # the collector, in a second terminal
```

`ENVIRONMENT=local` gives you Tempest's debug page, which replaces JSON error bodies with HTML. Set `ENVIRONMENT=production` if you want to see what a client actually receives.

## Commands

| Command | What it does |
|---|---|
| `posts:collect` | Subscribes to Jetstream and indexes matching posts. Runs until stopped. |
| `posts:backfill` | Recovers matching posts via `searchPosts`. Needs credentials. |
| `posts:prune` | Deletes posts past the retention window. |
| `feed:publish` / `feed:unpublish` | Writes or deletes the feed generator record on the publisher account. |
| `feed:stats` | What is indexed, and where the subscription cursor sits. |
| `migrate:up` | Applies migrations. Add `--force` where there is no TTY. |

## Endpoints

| Route | Purpose |
|---|---|
| `/xrpc/app.bsky.feed.getFeedSkeleton` | The feed itself. |
| `/xrpc/app.bsky.feed.describeFeedGenerator` | Which feeds this service serves. |
| `/.well-known/did.json` | The `did:web` document the AppView resolves. |
| `/` | Name, description, and the feed's `at://` URI. |

## Deploying

The image runs two processes under supervisord: FrankenPHP serving HTTP, and the collector holding the Jetstream socket. They are in one container because they share a SQLite file, and a platform volume attaches to a single service.

```bash
docker build -t kodamachi-feed .
docker run -p 8000:80 \
  -e FEEDGEN_HOSTNAME=feed.example.com \
  -e FEEDGEN_PUBLISHER_DID=did:plc:... \
  -v kodamachi-data:/app/var \
  kodamachi-feed
```

**Mount a volume at `/app/var`.** Railway's filesystem is ephemeral, so without one the index and the subscription cursor are wiped on every deploy and the feed comes back empty.

`PORT` is honoured if the platform sets it. `supervisorctl status` inside the container tells you whether the collector is actually up, which is worth checking: a container serving HTTP with a dead collector looks perfectly healthy and indexes nothing.

## Configuration

Everything is environment variables, documented in [.env.example](.env.example). The ones that matter most:

| Variable | Notes |
|---|---|
| `FEEDGEN_HOSTNAME` | The public hostname. `did:web:$FEEDGEN_HOSTNAME` becomes the service DID. |
| `FEEDGEN_PUBLISHER_DID` | The account that publishes the feed record. |
| `FEEDGEN_SQLITE_LOCATION` | Point this at a mounted volume in production. |
| `FEEDGEN_MATCH_TEXT` / `FEEDGEN_MATCH_ALT` | Comma-separated, case-insensitive substrings. |
| `FEEDGEN_HANDLE` / `FEEDGEN_APP_PASSWORD` | Only needed for backfill and publishing. |
| `FEEDGEN_SERVICE_DID` | Only if the service DID is not the `did:web` form. It must be stable. |

## Tests

```bash
vendor/bin/phpunit
```

Unit tests cover the service-auth verifier (including both curves, the `lxm` compatibility case, and key rotation), the post matcher, and feed pagination. Integration tests boot the framework and exercise the HTTP endpoints. CI additionally boots the server and curls it, then builds the image and checks both processes come up.

## License

MIT.
