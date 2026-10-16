---
title: Security model
description: What the browser can and cannot do to a live component, and what every component has to check itself.
order: 150
---

A live component runs on the server with the app's full access to its services, and the browser talks to it over a WebSocket that anyone can open with their own messages. This page lists what Live guarantees and what is left to the component.

## What Live guarantees

**Only this site connects.** The WebSocket route `/_live` refuses a connection whose `Origin` header does not name the host of the request (`403`). Browsers set `Origin` themselves, so a page on another site cannot open a WebSocket to the app with the user's cookies. That is why live actions need no CSRF token.

**Only listed components can be created.** The browser names a component by its selector, never by class. A selector that is not registered in `LivePlugin` (or by a plugin through `Components`) is refused.

**Only inputs can be set from outside.** A page, a template or the browser can set only public properties marked `#[Input]`. Any other name is refused.

**Only methods the template names can be called.** The browser can call a public, non-static method only if the component's template names it in a `data-on-*` attribute. Every other public method stays out of reach, and so does one named only in commented-out markup (`<!-- -->`, `{{/* */}}`).

**Bound arguments never leave the server.** A call such as `data-on-click="remove($task)"` reaches the browser only as an ID. The browser can send that ID back, but it cannot change the arguments, and an ID is only valid for the last two renders of that component. A click on an item that is gone since does nothing.

**One value per call.** A call takes at most one value from the browser: the one its event brings. It fills the first parameter the template does not bind; further parameters keep their defaults. A message with more values is refused.

**Navigation stays on the site.** [`navigate()`](navigation.md) accepts only paths of the site, so no value can send a browser to another site.

**Errors stay on the server.** In production the browser gets only `{"error": true}` and marks the component with `data-live-error`; messages, paths and stacks go to the log. The circuit keeps running, and one failing component does not hold back the others.

**Parked components go back to the same user only.** A reconnecting browser gets its parked circuit back only if its JWT names the same `sub` ([Reconnects](reconnect.md#the-same-user-only)). The circuit's token is never in a URL, a cookie or storage.

## What every component checks itself

**The user, in what it shows and in every action.** The guards of a page's route do not protect the components on that page: any browser that reaches `/_live` can mount any listed component with any inputs. A component whose data or actions are not for everyone finds the user in its [`Connection`](connection.md) and checks it in its template and in every action.

**Inputs, like query parameters.** `#[Input]` values set by a page travel through the browser and come back when it connects. A user can change them. Never take an input as proof of who the user is or what they may see: `#[Input] public int $userId` is a hole; the user comes from the connection's request.

**The one value from the browser.** The field's value, the form, `data-arg`: they come from the browser and can be forged, even for a click without `data-arg`. A method like `save(bool $publish = false)` can be called with `$publish` set by anyone who writes their own messages. Check it.

**Types are converted, not checked.** Values from the browser are converted as in a PHP call without `strict_types`: `"7"` fills an `int`, `"false"` fills a `bool` as `true`. A value that cannot be converted is an error. Validate ranges and formats in the method.

**Forms.** A form arrives as an array whose keys and values the browser chose. Check every field you use, ignore the rest, and never write the array into a model as it is.

**Uploads.** Name and type of a file come from the browser. Never use the name as a path, and check the content before trusting the type ([Uploads](uploads.md)).

**Markup.** `{{ }}` and `<?= ?>` escape everything except [`Html`](../reference/Waypoint/Live/Html.md). Wrap only markup you built safely in `Html`, and remember that `<?php echo $value ?>` prints without escaping.

**Revocation.** `$request->jwt` is what the middlewares found when the WebSocket connected, and a WebSocket can stay open for hours. A component that must notice a logout or a revoked token at once asks its source again on every action.

## Resource limits

A tab can hold at most 50 top-level components, a call at most 20 files, and a WebSocket message at most Tenma's `--max-body`. Each open tab holds its components in the server's memory until it closes, plus up to 3 minutes parked. See [Deployment and limits](deployment.md#limits).

See also: [The connection and the user](connection.md), [Events and actions](events.md#what-the-browser-can-call).
