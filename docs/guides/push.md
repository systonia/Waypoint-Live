---
title: Timers, broadcasts and server push
description: "Update a component from the server with #[Every] timers, render other tabs with Broadcast, and reach every worker with Tenma::publish()."
order: 100
---

A tab renders after its own actions. Two things render it without an event from its browser: a timer of one of its components, and a broadcast from somewhere else in the app.

## Timers with #[Every]

[`#[Every(seconds)]`](../reference/Waypoint/Live/Every.md) on a method runs it on a timer while the browser is connected. After each run the tab renders, and what changed is pushed to the browser:

```php
<?php
// src/Clock.php

namespace App;

use Waypoint\Live\{Component, Every};

#[Component]
final class Clock
{
    private string $time;

    public function __construct()
    {
        $this->tick();
    }

    #[Every(1.0)]
    private function tick(): void
    {
        $this->time = date('H:i:s');
    }
}
```

```html
<!-- src/Clock.Template.php -->
<time>{{ $this->time }}</time>
```

A timer method may be private, and the browser cannot call it unless the template also names it in a `data-on-*` attribute. A render that changes nothing sends nothing, so a timer that finds nothing new costs a render on the server and no traffic.

A timer that watches a job:

```php
<?php
// src/Export.php

namespace App;

use Waypoint\Live\{Component, Every};

#[Component]
final class Export
{
    private int $progress = 0;

    #[Every(0.5)]
    public function poll(): void
    {
        if ($this->progress < 100) {
            $this->progress += 25;    // in an app: ask the job queue
        }
    }
}
```

```html
<!-- src/Export.Template.php -->
@if ($this->progress < 100)
    <progress max="100" value="{{ $this->progress }}"></progress>
@else
    <a href="/exports/latest.csv">Download</a>
@endif
```

When timers run:

- **Only while connected.** Timers start when the component is created in the tab's circuit; the page's first render starts none. The first run comes one interval after that.
- **Paused while disconnected.** When the WebSocket drops, the tab's timers pause; after a [reconnect](reconnect.md) they start again.
- **Stopped for good** when the component is removed (a child no longer placed) or the tab is gone.
- **Like an action.** An exception in a timer is logged and marks the component with `data-live-error`; the timer keeps running. A timer may call [`navigate()`](navigation.md).

A timer method takes no required parameters, is not static, and its interval is more than 0. The development checks and `waypoint-check` report a timer that breaks one of these rules. In development, the console and the log warn when timers send more than 10 renders a second in one tab.

A component with many timers in many tabs adds up: 1,000 open tabs with a one-second timer are 1,000 renders a second. Prefer a longer interval, or a broadcast when the change happens.

## Changes for every tab

A tab renders after its own actions and timers. When state that tabs share through an app-wide service changes, the other tabs need to hear about it: [`Broadcast::changed()`](../reference/Waypoint/Live/Broadcast.md#changed) renders every connected tab, and each sends the components whose HTML changed.

A chat room:

```php
<?php
// src/Room.php

namespace App;

/** One for the whole app: every tab sees the same messages. */
final class Room
{
    /** @var list<string> */
    public array $messages = [];
}
```

```php
<?php
// src/Chat.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Broadcast, Component};

#[Component]
final class Chat
{
    #[Inject]
    private Room $room;

    #[Inject]
    private Broadcast $broadcast;

    /** @param array{message?: string} $form */
    public function say(array $form): void
    {
        $message = trim($form['message'] ?? '');
        if ($message === '') {
            return;
        }
        $this->room->messages[] = mb_substr($message, 0, 500);
        $this->broadcast->changed();
    }
}
```

```html
<!-- src/Chat.Template.php -->
<ul>
@foreach (array_slice($this->room->messages, -20) as $message)
    <li>{{ $message }}</li>
@endforeach
</ul>
<form data-on-submit="say">
    <input name="message" autocomplete="off">
    <button>Send</button>
</form>
```

Outside a component, for example in a controller or a queue worker of the same process, take the broadcast from the app: `$app->get(Broadcast::class)->changed()`. A tab whose connection dropped renders when it comes back.

A broadcast renders every tab of every user, whether it shows the room or not; tabs whose HTML did not change send nothing. It is meant for changes that are rare compared to the number of open tabs.

Each tab renders with its own connection's request values (its locale, its request id in the log), not with those of the request that called `changed()` ([The connection](connection.md#connection)).

## Several workers

Each Tenma worker has its own memory, its own tabs and its own instance of every app-wide service. Under a Tenma with `Tenma::publish()`, `changed()` goes out through it: every worker renders its tabs, and with `tenma --pubsub redis://host` every process on that Redis too. A worker subscribes to these broadcasts when its first tab connects, so a script that boots the app without a server (a CLI task, a test) ends as usual.

That is enough when the shared state lives outside the workers, in Postgres or Redis: each worker renders from the same source.

State in memory, like the `Room` above, differs per worker. Send the change itself to every worker, and let each update its own copy and render its own tabs with [`changedHere()`](../reference/Waypoint/Live/Broadcast.md#changedhere):

```php
// At startup, after $app->attach() and before Tenma::serve(): runs in every worker
$room = $app->get(Room::class);
$broadcast = $app->get(Broadcast::class);
Tenma::subscribe('chat', function (string $message) use ($room, $broadcast) {
    $room->messages[] = $message;
    $broadcast->changedHere();
});
```

```php
// In the action, instead of changing the room and calling changed()
public function say(array $form): void
{
    $message = trim($form['message'] ?? '');
    if ($message !== '') {
        Tenma::publish('chat', mb_substr($message, 0, 500));
    }
}
```

`changedHere()` renders only the tabs of the worker it runs in. Calling `changed()` in a subscription instead would render every tab once per worker.

Under a Tenma without `Tenma::publish()`, and in tests, `changed()` renders the tabs of its own worker.

## Testing

Timers run on fake time in [`Testing\Tab`](testing.md), and two tabs of one app share its services, so a broadcast in one renders the other:

```php
$tab = Tab::for($app);
$export = $tab->mount('app-export');
$tab->advance(1.0);                                  // two runs of poll()
$this->assertStringContainsString('value="50"', $export->html());

$anna = Tab::for($app)->mount('app-chat');
$ben = Tab::for($app)->mount('app-chat');
$anna->submit('form', ['message' => 'Hello']);
$ben->assertSee('Hello');
```

See also: [`Every`](../reference/Waypoint/Live/Every.md), [`Broadcast`](../reference/Waypoint/Live/Broadcast.md), [Deployment and limits](deployment.md).
