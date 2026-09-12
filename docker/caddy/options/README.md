# Global Caddy options that belong to one deployment

Files here are merged into Caddy's global options block. The directory is gitignored
apart from this note, because what goes in it is per-host and, in the case of the ACME
address, personal — a public repository is a mailing list to whoever scrapes it.

The glob tolerates an empty directory, so a clone with nothing here is a valid
configuration. A variable would not: `email {$ACME_EMAIL}` with the variable unset is a
parse error, and the whole site fails to start rather than starting without an address.

## `email.conf`

Where Let's Encrypt sends warnings when a renewal keeps failing. Without it the
certificate still issues and still renews; nobody is told when it stops.

```
email you@example.com
```
