# `Integration/` — Saloon connectors + typed DTOs (AD-3)

The concrete outbound edge: Saloon connectors and their typed request/response
DTOs, one namespace per target. This is the **only** layer that may talk to the
external world (the HTTP client, Saloon, Guzzle).

**Reached only from** `CommandHandler`s (AD-3). Under the slim, Laravel-idiomatic
layer model there is no `Services/` facade — a CommandHandler calls an `Integration/`
connector directly, and nothing else in `app/` may reach the external world. The
public entry point per target is a `{Target}Api` seam class in this namespace
(e.g. `Integration/Forum/ForumApi`) that hides the connector, requests and DTOs.
