# `Integration/` — Saloon connectors + typed DTOs (AD-3)

The concrete outbound edge: Saloon connectors and their typed request/response
DTOs, one namespace per target. This is the **only** layer that may talk to the
external world (the HTTP client, Saloon, Guzzle).

**Reached only from** a `Services/` seam facade (AD-3). Application code never
touches an `Integration/` class directly — it goes through the `Services/` facade.
