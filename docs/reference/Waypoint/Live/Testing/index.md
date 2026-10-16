---
title: "Waypoint\\Live\\Testing"
description: "The classes, interfaces, traits and enums in Waypoint\\Live\\Testing."
order: 200
generated: true
---

## Types

- [`Mounted`](Mounted.md) · final class — A component in a Tab: events go through its template as the browser's would, by the method an element names (`click('increment')`) or by a CSS selector (`click('button.reset')`), and the assertions read the HTML the browser would have.
- [`Tab`](Tab.md) · final class — One browser tab in a test, without a browser: the app's components with its services, driven through the same messages the browser sends, so a test proves the template's wiring too.
