---
title: Navigation
description: Send the browser to another page of the site after an action or a timer, with Connection::navigate().
order: 80
---

An action that ends on another page, such as a logout, a finished wizard or a saved record that has a page of its own, asks for it with [`Connection::navigate()`](../reference/Waypoint/Live/Connection.md#navigate). Once the action is done, the server tells the browser, and the browser loads the page.

```php
<?php
// src/AccountMenu.php

namespace App;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Component, Connection};

#[Component]
final class AccountMenu
{
    #[Inject]
    private Connection $connection;

    #[Inject]
    private Sessions $sessions;

    public function logout(): void
    {
        $this->sessions->end($this->connection->request);
        $this->connection->navigate('/login');
    }

    public function openSettings(): void
    {
        if (($this->connection->request->jwt['sub'] ?? null) === null) {
            // Counts although the action stops here
            $this->connection->navigate('/login?next=/settings');
            throw new \RuntimeException('Not logged in');
        }
        $this->connection->navigate('/settings');
    }
}
```

```html
<!-- src/AccountMenu.Template.php -->
<button data-on-click="openSettings">Settings</button>
<button data-on-click="logout">Log out</button>
```

## Rules

- **Only a path of this site.** The URL must start with a single `/`: `/login`, `/orders/42?tab=items`. Another site (`https://…`, `//other.test`), a `javascript:` URL, or a path with spaces, control characters or a backslash is refused with an `InvalidArgumentException`. Whatever a user typed can therefore never send another user's browser elsewhere.
- **After the action.** The browser goes when the action or timer is done, after its render was sent. The last `navigate()` of an action wins.
- **Even if the action throws.** A navigation asked for before an exception still happens. That makes a check at the top of an action simple: send a logged-out user to the login and stop with an exception, as `openSettings()` does. The exception is logged and the component gets `data-live-error` as usual, then the browser leaves.
- **Not during the page's first render.** Before a WebSocket exists there is nothing to send the browser; `navigate()` from a constructor or from code the template runs is an error when the page renders. Redirect in the page's controller or guard instead.
- **Timers too.** A `#[Every]` method may navigate, for example when a job it watches is finished.

For a plain link, a template needs no action: `<a href="/settings">` works as on any page. `navigate()` is for when the destination depends on what the action did.

## Testing

[`Tab::assertNavigatedTo()`](../reference/Waypoint/Live/Testing/Tab.md#assertnavigatedto) checks the destination of the tab's last navigation:

```php
$tab = Tab::for($app);
$tab->mount('app-account-menu')->click('logout');
$tab->assertNavigatedTo('/login');
```

See also: [The connection and the user](connection.md), [`Connection`](../reference/Waypoint/Live/Connection.md).
