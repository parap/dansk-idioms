# Dansk idiomer

`README.md` is the developer guide. It is long, so read the section the task needs:

- changing `src/` or `tests/`: "Development", then "Tests"
- SQL, a migration, collation or encoding: "Gotchas worth knowing"
- the importer or anything under `content/`: "Adding new idioms" and "How the importer reads a post"
- shipping: "Deploying"; ports, TLS and routes: "HTTPS", and the `edge` project beside this one

## Commands run in the container

This box has the standalone `docker-compose` binary, not the `docker compose` plugin. PHP commands
take the `docker-compose exec app` prefix:

```
docker-compose exec app vendor/bin/phpunit
python3 bin/prove-tests-red.py --changed     # the gate before a commit
```

The full `prove-tests-red.py` run, over an hour, never blocks a deploy: it judges the tests, not
the code. Deploy once `--changed` is green, start the full run in the background, and fix a
surviving fault in a follow-up commit.

## The dev stack mounts this checkout, not a worktree

The running stack was started here and mounts this directory (`.:/var/www/html`). With no project
`name:` in `docker-compose.yml`, a git worktree is a different compose project with no `.env`. From a worktree, `bin/ui-tests.py`
cannot seed, `prove-tests-red.py --anchors` cannot list tests, and the test step of `bin/deploy.sh`
tests nothing of the worktree. Run container-backed steps against this checkout, at the commit
being checked.
