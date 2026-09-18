# Deployment

One image, roles selected by the container's command -- `web` (default), `horizon`, `schedule`, `migrate`, `artisan`; anything else `exec "$@"`s through. Never flags: `docker run khronoz horizon` is the whole interface. See `docker/entrypoint.sh`.

## Compose profiles

| profile | services |
| --- | --- |
| *(none)* | `pgsql`, `valkey`, `rustfs`, `gotenberg` -- needed whichever way the app runs |
| `dev` | `mailpit` |
| `prod` | `web`, `horizon`, `schedule` |
| `migrate` | `migrate` (`docker compose --profile migrate run --rm migrate`) |

No `queue` role: Horizon *is* a `queue:work` supervisor, and a second pool on the same Redis connection is invisible to its metrics. `schedule` runs exactly one replica, always -- two would double-fire every task.

`horizon`, `schedule` and `migrate` set `healthcheck: {disable: true}`. The image's HEALTHCHECK probes the HTTP port; inheriting it parks a listener-less role as permanently unhealthy.

## env_file beats image ENV

`env_file: .env` wins over every `ENV` in the Dockerfile, so anything the image sets and `.env` also carries must be re-pinned in the compose anchor. This bit twice: `APP_ENV=local` from `.env` made the container register Telescope (a dev dependency `--no-dev` drops -> `Class "Laravel\Telescope\TelescopeServiceProvider" not found`), and `INERTIA_SSR_PORT=43714` made SSR listen on the host-facing port instead of the in-container 13714. The anchor pins `APP_ENV`, `APP_DEBUG`, `INERTIA_SSR_*`, the service hostnames and `TELESCOPE_ENABLED`.

## Running migrations

```sh
# Containers (staging). Backing services must be up first.
docker compose up -d
docker compose --profile migrate run --rm migrate

# Host (local development).
composer migrate
```

Both do the same two things: `php artisan migrate --force --database=owner`, then `php artisan db:grant`. The compose form is one-shot -- it runs, prints, and exits; `--rm` drops the container. It is idempotent, so re-running after a deploy is the normal move. Run it *before* `--profile prod up -d`, or against a running stack whenever new migrations land.

## Owner connection / no RUN_MIGRATIONS

Migrations run on the `owner` connection, and `AppServiceProvider::guardOwnerConnection()` throws if that connection resolves outside a console run. So the web/horizon/schedule roles blank `DB_OWNER_USERNAME`/`DB_OWNER_PASSWORD` in the anchor and only the one-shot `migrate` service restores them from `.env`. There is deliberately no `RUN_MIGRATIONS` entrypoint flag (paayo has one); `migrate` ends with `php artisan db:grant`, which re-grants the app role's row privileges on newly created tables.

## Debian builder, Alpine runtime

`package.json` pins `*-linux-x64-gnu` rollup/oxide/lightningcss binaries with no musl counterparts, so Vite cannot build on Alpine. `vendor/` is pure PHP and built assets are plain JS/CSS, so both cross the libc boundary. The builder needs a *bootable Laravel app* (source, vendor, a throwaway `APP_KEY`) because `@laravel/vite-plugin-wayfinder` shells out to `php artisan wayfinder:generate`. `pcntl` is required in the builder even though it runs no worker -- Horizon declares it and Composer refuses the lock without it.

Build assets with `npm run build`: khronoz's `build` is already `vite build && vite build --ssr`. `build:ssr` alone would skip the client bundle.

## ssr.noExternal

`vite.config.js` sets `ssr: isSsrBuild ? { noExternal: true } : undefined`. Without it Vite externalises react, react-dom/server, @inertiajs/react and every Radix package out of the SSR bundle as bare imports. The build still succeeds; the container dies at runtime with `ERR_MODULE_NOT_FOUND` on every SSR page while `/up` stays green. With it the only bare specifiers left are Node builtins -- which is what lets the runtime stage ship `node` with no `node_modules`.

The SSR bundle is `bootstrap/ssr/ssr.js` (paayo emits `app.js`); `docker/cluster.mjs`'s `BUNDLE` default and `SSR_BUNDLE` follow that.

## Caches

`view:cache` and `event:cache` are baked at build (environment-independent). `config:cache` and `route:cache` run at container start, where the real environment exists -- gated on `SKIP_OPTIMIZE`.

## runit, not supervisord

The `web` role is two processes. `docker/web.sh` backgrounds `runsvdir` and traps SIGTERM for an *ordered* shutdown: Octane first with 30s, SSR second with 10s, so in-flight requests finish rendering. `docker/octane.sh` polls `INERTIA_SSR_PORT` before starting Octane because runsvdir starts both at once. `docker/finish.sh`, symlinked as each service's `finish`, reproduces supervisord's FATAL -- three starts dying inside 5s take the container down with exit 1 instead of runit looping forever behind a green `/up`.

## Worker counts

`OCTANE_WORKERS`, `OCTANE_TASK_WORKERS` and `SSR_WORKERS` default to `auto`, resolved from the cgroup CPU quota by the entrypoint. Swoole and Node both report *host* cores inside a quota'd container. Override only against a measurement.

## AWS prune -- KEEP is load-bearing

`docker/prune.php` deletes 423 of 436 `aws/aws-sdk-php` service models (~51 MB). The models are load-bearing: every call resolves through `AwsClientTrait::__call()` reading `api-2.json.php`. `KEEP = ['Kms','S3','SSO','SSOOIDC','Sts','Signin']` covers khronoz, which uses S3 alone. **Adding an AWS-backed feature means adding its namespace to KEEP first** -- a pruned service fails at class load in production behind a green build.

## .dockerignore traps

`.npmrc` must NOT be ignored (`legacy-peer-deps=true`; without it `npm ci` fails ERESOLVE on typescript 7 vs typescript-eslint). `docker/` must NOT be ignored (the build runs `docker/prune.php`). `tsconfig.json` must NOT be ignored (the asset build reads it). Conversely `database/*.sqlite*` (telescope.sqlite reaches 1.3 GB), `storage/*.key` (Passport secrets) and `storage/inertia-devtools` must be -- they took the image from 364 MB to 1.96 GB and shipped private keys.

## Secrets with no local counterpart

`PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`: `config/passport.php` reads both from env with no fallback and the image bakes no keys into an empty `storage/`, so a container without them fails on the first token issue. `PASSKEYS_USER_HANDLE_SECRET` must be set and stable before staging enrols its first passkey (it defaults to `APP_KEY`).

`RUSTFS_EXTERNAL_ADDRESS` is what RustFS signs presigned URLs against. Empty host means "whatever the request came in on", correct locally; a staging box reached by name needs that name. Same class of issue as `AWS_ENDPOINT` in the anchor: the internal name is signed into every temporary URL, so it is right only while nothing outside the compose network downloads a stored ledger PDF.

## Watch item: Swoole on musl

musl's default thread stack is 128 KB against glibc's 8 MB, and Laravel's boot stack is deep. paayo needed `gssencmode=disable` on the Postgres DSN to keep Swoole workers alive; khronoz has no equivalent connector override, so this is the most likely first failure under real load. Symptom: `worker(pid=...) abnormal exit, signal=11`, empty-bodied 200s on DB-touching routes, `/up` still green. Fix: rebase the runtime stage on `php:${PHP_VERSION}-cli-bookworm-slim` and translate the `apk` block to `apt-get` -- the builder stage is a worked example of the same extension set on Debian. (300 requests at concurrency 25 came back clean on Docker Desktop; that is not a staging box.)
