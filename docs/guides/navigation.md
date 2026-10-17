---
title: Navigation
description: Send the browser to another page of the site after an action or a timer, with Connection::navigate().
order: 80
---

An action that ends on another page, such as a placed order, a finished wizard or a saved record that has a page of its own, asks for it with [`Connection::navigate()`](../reference/Waypoint/Live/Connection.md#navigate). Once the action is done, the server tells the browser, and the browser loads the page.

The bakery's checkout ends on the new order's page, from `src/Components/Checkout.php`:

```php
#[Inject]
private Connection $connection;

public function place(CheckoutForm $form): void
{
    // validate, then reserve the stock
    $order = $this->orders->place($this->cart->quantities(), trim($form->name), trim($form->email), $form->pickupDate, $this->i18n->locale());
    $this->cart->clear();
    $this->broadcast->changed();
    $this->connection->navigate("/orders/{$order->code}/thanks");
}
```

The thanks page is a plain controller route that shows the order with its `OrderTracker` ([Forms and binding](forms.md#the-checkout) has the whole component).

A check at the top of an action can send the browser elsewhere and stop:

```php
public function place(CheckoutForm $form): void
{
    if ($this->cart->count() === 0) {
        // Counts although the action stops here
        $this->connection->navigate('/');
        throw new \RuntimeException('The cart is empty');
    }
    // ...
}
```

## Rules

- **Only a path of this site.** The URL must start with a single `/`: `/backoffice/login`, `/orders/K7Q2/thanks?lang=de`. Another site (`https://…`, `//other.test`), a `javascript:` URL, or a path with spaces, control characters or a backslash is refused with an `InvalidArgumentException`. Whatever a user typed can therefore never send another user's browser elsewhere.
- **After the action.** The browser goes when the action or timer is done, after its render was sent. The last `navigate()` of an action wins.
- **Even if the action throws.** A navigation asked for before an exception still happens. That makes a check at the top of an action simple: send the browser to the right page and stop with an exception, as the second `place()` above does. The exception is logged and the component gets `data-live-error` as usual, then the browser leaves.
- **Not during the page's first render.** Before a WebSocket exists there is nothing to send the browser; `navigate()` from a constructor or from code the template runs is an error when the page renders. Redirect in the page's controller or guard instead.
- **Timers too.** A `#[Every]` method may navigate, for example when a job it watches is finished.

For a plain link, a template needs no action: `<a href="/cart">` works as on any page, as in the bakery's `CartBadge`. `navigate()` is for when the destination depends on what the action did.

## Testing

[`Tab::assertNavigatedTo()`](../reference/Waypoint/Live/Testing/Tab.md#assertnavigatedto) checks the destination of the tab's last navigation. From the bakery's `tests/Components/CheckoutTest.php`:

```php
$tab = $this->customerTab();
$tab->mount('app-add-to-cart', ['product' => '4'])->click('add')->click('add');
$tab->mount('app-checkout')->submit('form', ['name' => 'Mia', 'email' => 'mia@example.test', 'pickupDate' => $this->pickupDay()]);

$code = $this->sql("SELECT code FROM orders WHERE email = 'mia@example.test'");
$tab->assertNavigatedTo("/orders/$code/thanks");
```

See also: [The connection and the user](connection.md), [`Connection`](../reference/Waypoint/Live/Connection.md).
