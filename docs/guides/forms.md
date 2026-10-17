---
title: Forms and binding
description: Submit forms to a component, keep fields in step with its state, validate on the server and show the errors.
order: 50
---

A form needs no route and no controller: `data-on-submit` sends its fields to a method of the component, which validates them and changes its state; the next render shows the result or the errors. A field can also follow the component's state while the user types.

## A newsletter form

```php
<?php
// src/Newsletter.php

namespace App;

use Waypoint\Live\Component;

#[Component]
final class Newsletter
{
    private string $email = '';

    /** @var list<string> */
    private array $topics = [];

    /** @var array<string, string> field => message */
    private array $errors = [];

    private bool $subscribed = false;

    public function type(string $email): void
    {
        $this->email = $email;
        unset($this->errors['email']);
    }

    /** @param array{email?: string, topics?: list<string>} $form */
    public function subscribe(array $form): void
    {
        $this->email = trim($form['email'] ?? '');
        $this->topics = array_values(array_intersect($form['topics'] ?? [], ['releases', 'events']));
        $this->errors = [];
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors['email'] = 'Please enter an email address.';
        }
        if ($this->topics === []) {
            $this->errors['topics'] = 'Pick at least one topic.';
        }
        if ($this->errors === []) {
            $this->subscribed = true;
        }
    }
}
```

```html
<!-- src/Newsletter.Template.php -->
@if ($this->subscribed)
    <p>Thanks! We will write to {{ $this->email }}.</p>
@else
    <form data-on-submit="subscribe">
        <label>Email <input name="email" type="email" value="{{ $this->email }}" data-on-input="type"></label>
        @if (isset($this->errors['email']))
            <p class="error">{{ $this->errors['email'] }}</p>
        @endif
        <label><input type="checkbox" name="topics[]" value="releases" @if (in_array('releases', $this->topics, true)) checked @endif> Releases</label>
        <label><input type="checkbox" name="topics[]" value="events" @if (in_array('events', $this->topics, true)) checked @endif> Events</label>
        @if (isset($this->errors['topics']))
            <p class="error">{{ $this->errors['topics'] }}</p>
        @endif
        <button>Subscribe</button>
    </form>
@endif
```

Submitting the form calls `subscribe()` with the fields as an array. With an invalid address and no topic, the component renders both errors and keeps what was entered; typing in the email field clears the email error at once, since `data-on-input="type"` reaches the server while the user types.

The same flow in a test:

```php
$form = Tab::for($app)->mount('app-newsletter');
$form->submit('form', ['email' => 'nope'])
    ->assertSee('Please enter an email address.')
    ->assertSee('Pick at least one topic.');
$form->input('input[name=email]', 'anna@example.com')->assertDontSee('Please enter');
$form->submit('form', ['email' => 'anna@example.com', 'topics' => ['events']])
    ->assertSee('Thanks! We will write to anna@example.com.');
```

## What the method gets

`data-on-submit` reads the form when it is submitted and sends it as one value, an array keyed by the fields' names:

- A field named `name[]` collects a list: `topics[]` above arrives as `['releases', 'events']`.
- Any other name that occurs more than once keeps its last value. A checkbox group or a `<select multiple>` therefore needs a `[]` name.
- What the browser leaves out of a form is missing here too: unchecked checkboxes and disabled fields.
- The button that submitted the form is in it with its `name` and `value`, as in a classic post: `<button name="as" value="draft">` and `<button name="as" value="public">` tell the method which was pressed.
- An empty file input is left out; a file input sends an [`UploadedFile`](uploads.md), a list of them with `[]` and `multiple`.

All values are strings (or files). Treat the array like any request body: check every field, and never trust the browser to send only the fields the template has. A forged message can send any array, or no array at all, so the type of the parameter matters: `array $form` refuses a string, and the method is not called ([Events](events.md#one-value-from-the-browser)).

### A DTO for the form

A parameter typed with a class that uses `Waypoint\FromArray` gets the form as that DTO, built as a `#[Body]` DTO of a controller is: `"3"` becomes an `int`, `"l"` a backed enum case, nested arrays nested DTOs. Its validation attributes apply, and nothing throws: `$form->isValid` and `$form->errors` tell what to show.

```php
final class Signup
{
    use FromArray;

    #[Email]
    public string $email;

    public int $seats = 1;
}

public function sign(Signup $form): void
{
    $this->errors = $form->errors;
    if ($form->isValid) {
        $this->signups->add($form->email, $form->seats);
    }
}
```


Any validation code works, plain PHP as above or a validator service injected into the component; the component decides how it shows the errors.

## Fields and the server

A render patches the page instead of replacing it, and form fields get special care, so that what the user is typing is never lost:

- **A field keeps what the user typed or picked until the server renders a different value for it.** If the server renders `value=""` both before and after an action, the field keeps its text.
- **While a field has the focus, a new value from the server only applies if the field still holds the previous one**, that is, if the user has not typed since. A render that lags behind the typing loses nothing.
- The same holds for `checked` on checkboxes and radio buttons and for the selected options of a `<select>`.

### Binding a field

To make a field follow the component's state, render the state as its value and send changes back:

```html
<input name="email" value="{{ $this->email }}" data-on-input="type">
```

```php
public function type(string $email): void
{
    $this->email = $email;
}
```

Now the server always knows what is in the field, and a new value from the server reaches the field: setting `$this->email = ''` in an action empties it, as does any other value the component computes (a normalised phone number, a suggestion that was picked).

A field without binding keeps what was typed after a submit, because the server renders the same `value` as before. To empty the form after a successful submit, either bind its fields, or stop rendering the form and render a confirmation instead, as `Newsletter` does.

### Live search

`data-on-input` alone, without a form, makes a search field:

```html
<input type="search" value="{{ $this->query }}" data-on-input="search" placeholder="Find a city">
<ul>
@foreach ($this->cities->search($this->query) as $city)
    <li data-key="{{ $city }}">{{ $city }}</li>
@endforeach
</ul>
```

```php
public function search(string $query): void
{
    $this->query = $query;
}
```

Typing is sent 150 ms after the last keystroke, and each answer renders the list. Give list items a `data-key`, so that the patch moves them instead of rewriting each one.

### Fields that change on their own

`data-on-change` sends a field's value when it changes: a `<select>`, a date, a checkbox (as `true`/`false`), or a text field when it loses the focus:

```html
<select data-on-change="sort">
    <option value="name" @if ($this->sort === 'name') selected @endif>Name</option>
    <option value="date" @if ($this->sort === 'date') selected @endif>Date</option>
</select>
```

## No CSRF token

A live form needs no CSRF token. The WebSocket connects only from the site's own pages (its `Origin` header is checked), so another site never gets to send an action ([Security model](security.md)).

See also: [Events and actions](events.md#one-value-from-the-browser), [Uploads](uploads.md), [`Mounted::submit()`](../reference/Waypoint/Live/Testing/Mounted.md#submit).
