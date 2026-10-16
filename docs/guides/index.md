---
title: Guides
description: One part of Live per page, with examples that run as they are.
order: 10
---

Each guide covers one part of Live, with complete components: a class, its template and, where it helps, the PHPUnit test that drives it. The examples were run with [`Testing\Tab`](testing.md) against the package. The [reference](../reference/index.md) lists every public class and method.

Building components:

- [Components](components.md): `#[Component]`, selector and template, state, inputs, lifecycle, components of a plugin
- [Templates](templates.md): `{{ }}`, comments, `@if` and `@foreach`, plain PHP, `Html` for trusted markup
- [Events and actions](events.md): `data-on-*`, `data-arg`, bound calls like `remove($task)`, what the browser can call
- [Child components and inputs](children.md): placing components in templates, keys, closures as callbacks
- [Forms and binding](forms.md): `data-on-submit`, fields that follow the server, validation
- [Services](services.md): `#[Inject]`, `#[Scoped]` services per tab, what an app-wide service may inject
- [The connection and the user](connection.md): `Connection`, the JWT, checking the user in every component
- [Navigation](navigation.md): sending the browser to another page after an action
- [Uploads](uploads.md): file inputs and forms with files
- [Timers, broadcasts and server push](push.md): `#[Every]`, `Broadcast`, several workers

Running and checking:

- [Reconnects and parked circuits](reconnect.md): what happens when the connection drops
- [Development tools](development.md): hot reload, the error overlay, editor support, tracing and inspecting
- [Static analysis](static-analysis.md): `vendor/bin/live-check` and the PHPStan extension
- [Testing](testing.md): `Testing\Tab` and `Testing\Mounted`
- [Security model](security.md): what the browser can and cannot do
- [Deployment and limits](deployment.md): workers, proxies, limits of the proof of concept
