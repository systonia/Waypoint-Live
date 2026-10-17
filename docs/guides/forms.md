---
title: Forms and binding
description: Submit forms to a component, keep fields in step with its state, validate on the server and show the errors.
order: 50
---

A form needs no route and no controller: `data-on-submit` sends its fields to a method of the component, which validates them and changes its state; the next render shows the result or the errors. A field can also follow the component's state while the user types.

## The checkout

The bakery's checkout asks for a name, an email and a pickup day, reserves the stock and sends the customer to the order's page. From `src/Components/Checkout.php`:

```php
/**
 * The checkout form. Placing an order reserves the stock, empties the tab's cart, renders every open tab (the
 * order boards in the back office show the new order at once) and sends the browser to the order's page.
 */
#[Component]
final class Checkout
{
    #[Inject]
    private Cart $cart;

    #[Inject]
    private Orders $orders;

    #[Inject]
    private PickupCalendar $calendar;

    #[Inject]
    private Broadcast $broadcast;

    #[Inject]
    private Connection $connection;

    #[Inject]
    private Translator $i18n;

    private CheckoutForm $form;

    /** @var array<string, string> field => message */
    private array $errors = [];

    public function __construct()
    {
        $this->form = new CheckoutForm();
    }

    public function place(CheckoutForm $form): void
    {
        $this->form = $form;
        $this->errors = $form->errors;
        if ($this->cart->count() === 0) {
            $this->errors['cart'] = $this->i18n->t('Your cart is empty.');
        }
        if (!isset($this->errors['pickupDate']) && !in_array($form->pickupDate, $this->calendar->upcoming(), true)) {
            $this->errors['pickupDate'] = $this->i18n->t('Please choose one of the pickup days.');
        }
        if ($this->errors !== []) {
            return;
        }
        try {
            $order = $this->orders->place($this->cart->quantities(), trim($form->name), trim($form->email), $form->pickupDate, $this->i18n->locale());
        } catch (OutOfStock $e) {
            $this->errors['cart'] = $this->i18n->t('Sorry, we do not have that many of {product} any more.', ['product' => $e->product]);
            return;
        }
        $this->cart->clear();
        $this->broadcast->changed();
        $this->connection->navigate("/orders/{$order->code}/thanks");
    }
}
```

The template, shortened to two of its three fields (`src/Components/Checkout.Template.php`):

```html
@if ($this->cart->count() > 0)
    <form data-on-submit="place" class="grid gap-4">
        @if (isset($this->errors['cart']))
            <p role="alert" class="rounded-lg bg-red-50 p-3 text-red-800">
                {{ $this->errors['cart'] }}
            </p>
        @endif
        <label class="grid gap-1">
            <span class="text-sm font-medium">{{ $this->i18n->t('Your name') }}</span>
            <input name="name" value="{{ $this->form->name }}" autocomplete="name" class="rounded-lg border border-stone-300 px-3 py-2">
            @if (isset($this->errors['name']))
                <span class="text-sm text-red-700">{{ $this->errors['name'] }}</span>
            @endif
        </label>
        <label class="grid gap-1">
            <span class="text-sm font-medium">{{ $this->i18n->t('Pickup day') }}</span>
            <select name="pickupDate" class="rounded-lg border border-stone-300 px-3 py-2">
                <option value="">{{ $this->i18n->t('Choose a day') }}</option>
                @foreach ($this->calendar->upcoming() as $date)
                    <option value="{{ $date }}" @if ($this->form->pickupDate === $date) selected @endif>{{ Format::day($date, $this->i18n->locale()) }}</option>
                @endforeach
            </select>
            @if (isset($this->errors['pickupDate']))
                <span class="text-sm text-red-700">{{ $this->errors['pickupDate'] }}</span>
            @endif
        </label>
        <button class="rounded-lg bg-amber-800 px-4 py-3 font-semibold text-white hover:bg-amber-700">{{ $this->i18n->t('Reserve my order') }}</button>
    </form>
@endif
```

Submitting the form calls `place()` with the fields as a [`CheckoutForm`](#a-dto-for-the-form). With an empty name, a broken email and today as the pickup day (orders are for the days ahead), the component renders the three errors and keeps what was entered, since the template renders the submitted form's values. Once the order is placed, the cart is empty, so the form disappears, every open order board shows the new order ([Broadcast](push.md#changes-for-every-tab)) and the browser goes to the order's page ([Navigation](navigation.md)).

The same flow in the bakery's `tests/Components/CheckoutTest.php`, with the messages in German:

```php
public function testTheFormIsValidatedInTheCustomersLanguage(): void
{
    $this->app()->get(Translator::class)->setLocale('de');
    $tab = $this->customerTab();
    $tab->mount('app-add-to-cart', ['product' => '4'])->click('add');
    $tab->mount('app-checkout')
        ->submit('form', ['name' => '', 'email' => 'nope', 'pickupDate' => date('Y-m-d')])
        ->assertSee('Dieser Wert darf nicht leer sein.')
        ->assertSee('Dieser Wert ist keine gültige E-Mail-Adresse.')
        ->assertSee('Bitte wähle einen der Abholtage.');
    $this->assertSame(0, $this->sql("SELECT count(*) FROM orders WHERE status = 'pending'"));
}
```

## What the method gets

`data-on-submit` reads the form when it is submitted and sends it as one value, an array keyed by the fields' names:

- A field named `name[]` collects a list: checkboxes `extras[]` with the values `candles` and `card` arrive as `['candles', 'card']`.
- Any other name that occurs more than once keeps its last value. A checkbox group or a `<select multiple>` therefore needs a `[]` name.
- What the browser leaves out of a form is missing here too: unchecked checkboxes and disabled fields.
- The button that submitted the form is in it with its `name` and `value`, as in a classic post: `<button name="pay" value="shop">` and `<button name="pay" value="invoice">` tell the method which was pressed.
- An empty file input is left out; a file input sends an [`UploadedFile`](uploads.md), a list of them with `[]` and `multiple`.

All values are strings (or files). Treat the array like any request body: check every field, and never trust the browser to send only the fields the template has. A forged message can send any array, or no array at all, so the type of the parameter matters: `array $form` refuses a string, and the method is not called ([Events](events.md#one-value-from-the-browser)).

### A DTO for the form

A parameter typed with a class that uses `Waypoint\FromArray` gets the form as that DTO, built as a `#[Body]` DTO of a controller is: `"3"` becomes an `int`, `"baking"` a backed enum case, nested arrays nested DTOs. Its validation attributes apply, and nothing throws: `$form->isValid` and `$form->errors` tell what to show. The checkout's form, `src/Requests/CheckoutForm.php`:

```php
/** What the checkout asks for. Waypoint's validation attributes, checked inside a live action. */
final class CheckoutForm
{
    use FromArray;

    #[NotBlank]
    #[Length(
        max: 80
    )]
    public string $name = '';

    #[NotBlank]
    #[Email]
    public string $email = '';

    #[NotBlank]
    #[Regex('/^\d{4}-\d{2}-\d{2}$/')]
    public string $pickupDate = '';
}
```

`place()` above takes its `errors` as the component's and adds the checks that need the app (an empty cart, a day that is no pickup day). The messages come in the tab's language, through the i18n plugin.

Any validation code works, a DTO as above, plain PHP or a validator service injected into the component; the component decides how it shows the errors.

## Fields and the server

A render patches the page instead of replacing it, and form fields get special care, so that what the user is typing is never lost:

- **A field keeps what the user typed or picked until the server renders a different value for it.** If the server renders `value=""` both before and after an action, the field keeps its text.
- **While a field has the focus, a new value from the server only applies if the field still holds the previous one**, that is, if the user has not typed since. A render that lags behind the typing loses nothing.
- The same holds for `checked` on checkboxes and radio buttons and for the selected options of a `<select>`.

### Binding a field

To make a field follow the component's state, render the state as its value and send changes back:

```html
<input name="name" value="{{ $this->name }}" data-on-input="type">
```

```php
public function type(string $name): void
{
    $this->name = $name;
}
```

Now the server always knows what is in the field, and a new value from the server reaches the field: setting `$this->name = ''` in an action empties it, as does any other value the component computes (a normalised phone number, a suggestion that was picked).

A field without binding keeps what was typed after a submit, because the server renders the same `value` as before. To empty the form after a successful submit, either bind its fields, or stop rendering the form and render something else instead, as `Checkout` does once the cart is empty.

### Live search

`data-on-input` alone, without a form, makes a search field:

```html
<input type="search" value="{{ $this->query }}" data-on-input="search" placeholder="Rye, pretzel, croissant">
<ul>
    @foreach ($this->matches() as $product)
        <li data-key="{{ $product->id }}">{{ $product->name('en') }}</li>
    @endforeach
</ul>
```

```php
public function search(string $query): void
{
    $this->query = $query;
}

/** @return list<Product> */
private function matches(): array
{
    return array_values(array_filter($this->products->all(), fn (Product $p) => $this->query !== '' && stripos($p->name('en'), $this->query) !== false));
}
```

Typing is sent 150 ms after the last keystroke, and each answer renders the list. Give list items a `data-key`, so that the patch moves them instead of rewriting each one.

### Fields that change on their own

`data-on-change` sends a field's value when it changes: a `<select>`, a date, a checkbox (as `true`/`false`), or a text field when it loses the focus:

```html
<select data-on-change="sort">
    <option value="name" @if ($this->sort === 'name') selected @endif>Name</option>
    <option value="price" @if ($this->sort === 'price') selected @endif>Price</option>
</select>
```

## No CSRF token

A live form needs no CSRF token. The WebSocket connects only from the site's own pages (its `Origin` header is checked), so another site never gets to send an action ([Security model](security.md)).

See also: [Events and actions](events.md#one-value-from-the-browser), [Uploads](uploads.md), [`Mounted::submit()`](../reference/Waypoint/Live/Testing/Mounted.md#submit).
