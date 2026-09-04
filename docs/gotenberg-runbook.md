# Gotenberg outage runbook

`lazarski_bip_upload`'s DOCX/XLSX → PDF conversion (`GotenbergDocumentConverter`) depends
on an external Gotenberg instance. This is the only conversion backend in production —
there is no local `soffice` fallback there (see `LibreOfficeDocumentConverter`'s class
docs for why: production is home.pl shared hosting, CageFS jail, no root).

## Symptom

The document review screen (`.../DocumentImport/review?documentSet=...`) shows:

```
<filename> — Błąd (Gotenberg request failed with status 502.)
```

## Where the backend actually lives

- Public endpoint: `https://fileconvert.advantage.pl` (Basic Auth; credentials in this
  project's `.env` as `GOTENBERG_BASE_URL` / `GOTENBERG_AUTH_USER` /
  `GOTENBERG_AUTH_PASSWORD`).
- Physically: a podman container named `gotenberg-bip` (`gotenberg/gotenberg:8`) on the
  `adv-vps` host, bound to `127.0.0.1:8073`, fronted by nginx which is what actually
  answers at the public hostname above.

## Diagnosis

A 502 with a **plain nginx error page** (not JSON, not a Gotenberg-formatted error)
means nginx can't reach the container behind it — this is a full outage, not a
per-document problem. Confirm before assuming it's the file's fault:

```bash
# unauthenticated: should be 401 if the proxy itself is fine
curl -sS -o /dev/null -w "%{http_code}\n" https://fileconvert.advantage.pl/health

# authenticated: 502 here (vs. 200) means the Gotenberg container itself is down
curl -sS -o /dev/null -w "%{http_code}\n" \
  -u "$GOTENBERG_AUTH_USER:$GOTENBERG_AUTH_PASSWORD" \
  https://fileconvert.advantage.pl/health
```

If that second call 502s, it's the container, not the document. SSH in and check:

```bash
ssh adv-vps "podman ps -a --filter name=gotenberg-bip"
ssh adv-vps "podman inspect gotenberg-bip --format \
  'Status: {{.State.Status}} | ExitCode: {{.State.ExitCode}} | RestartPolicy: {{.HostConfig.RestartPolicy.Name}}'"
```

## Fix

```bash
ssh adv-vps "podman start gotenberg-bip"
```

Then re-verify with the authenticated `curl` above (should now return 200), and retry
the failed document import.

## Root cause seen once already (2026-09-04) — check for recurrence

`gotenberg-bip`'s restart policy was `unless-stopped`. Podman's boot-time auto-start
mechanism (`podman-restart.service`, a user-level systemd unit) only starts containers
via `podman start --all --filter restart-policy=always` — **`unless-stopped` is
invisible to it**, unlike Docker where that policy does survive a daemon/host restart.
After a host reboot, every `always`-policy container came back; `gotenberg-bip` quietly
didn't, and stayed down until someone tried a conversion hours later.

Fixed by setting the policy to `always` in place (no container recreate needed on
podman ≥ 4):

```bash
ssh adv-vps "podman update --restart=always gotenberg-bip"
ssh adv-vps "podman inspect gotenberg-bip --format 'RestartPolicy: {{.HostConfig.RestartPolicy.Name}}'"
# should print: RestartPolicy: always
```

If this recurs, check the policy first — it may have been reset by a container
recreate/redeploy that didn't carry the `always` flag forward.
