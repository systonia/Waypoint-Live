<?php

namespace Waypoint\Live\Testing;

use Dom\Element;
use Dom\HTMLDocument;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * A component in a Tab: events go through its template as the browser's would, by the method an element names
 * (`click('increment')`) or by a CSS selector (`click('button.reset')`), and the assertions read the HTML the
 * browser would have.
 */
final class Mounted
{
    /** @internal */
    public function __construct(private readonly Tab $tab, private readonly string $id)
    {
    }

    /** The element whose data-on-click names $target, or that $target selects; its data-arg goes along. */
    public function click(string $target): self
    {
        $element = $this->element('data-on-click', $target);
        return $this->call($element, 'data-on-click', $element->hasAttribute('data-arg') ? [$element->getAttribute('data-arg')] : []);
    }

    /** Types $value into a data-on-input field, without the browser's wait. */
    public function input(string $target, string $value): self
    {
        return $this->call($this->element('data-on-input', $target), 'data-on-input', [$value]);
    }

    /** A data-on-change field changes: its value, or true or false for a checkbox. */
    public function change(string $target, string|bool $value): self
    {
        return $this->call($this->element('data-on-change', $target), 'data-on-change', [$value]);
    }

    /**
     * Submits the data-on-submit form that $target names or selects, with $form as its fields.
     *
     * @param array<string, mixed> $form The form's fields as the browser sends them: `name[]` fields as a list
     */
    public function submit(string $target, array $form): self
    {
        return $this->call($this->element('data-on-submit', $target), 'data-on-submit', [(object) $form]);
    }

    /** A file picked in a data-on-change file input: the method gets it as an UploadedFile. */
    public function upload(string $target, string $name, string $content, string $type = 'application/octet-stream'): self
    {
        $file = ['$file' => ['name' => $name, 'type' => $type, 'data' => base64_encode($content)]];
        return $this->call($this->element('data-on-change', $target), 'data-on-change', [$file]);
    }

    /** A child placed by this component's template: by its key, or the first of its tag. */
    public function find(string $selector, string|int|null $key = null): self
    {
        $node = $this->tab->circuit()->byId($this->id) ?? throw new LogicException("No component '$this->id'");
        $child = $node->children[$selector . ($key !== null ? "=$key" : '#1')] ?? throw new LogicException("<$selector> " . ($key !== null ? "with key '$key' " : '') . 'is not in the template');
        return new self($this->tab, $child->id);
    }

    /** The HTML the browser has inside the component's tag. */
    public function html(): string
    {
        $root = explode('.', $this->id)[0];
        if ($root === $this->id) {
            return $this->tab->html($this->id);
        }
        $element = self::document($this->tab->html($root))->querySelector('[data-live-id="' . $this->id . '"]');
        return $element->innerHTML ?? throw new LogicException("<{$this->id}> is no longer on the page");
    }

    /** The component itself, for its state. */
    public function component(): object
    {
        return ($this->tab->circuit()->byId($this->id) ?? throw new LogicException("No component '$this->id'"))->component;
    }

    /** $text as the template prints it, escaped. */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString(htmlspecialchars($text, ENT_QUOTES), $this->html());
        return $this;
    }

    /** That $text, escaped as the template prints it, is not in the component's HTML. */
    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString(htmlspecialchars($text, ENT_QUOTES), $this->html());
        return $this;
    }

    /** By the method an element names in $attribute, or by a CSS selector. */
    private function element(string $attribute, string $target): Element
    {
        $document = self::document($this->html());
        if (preg_match('/^[A-Za-z_]\w*$/', $target) === 1) {
            // A method called with arguments the server bound has an ID of its own: remove~3f…
            foreach ($document->querySelectorAll("[$attribute]") as $element) {
                $value = (string) $element->getAttribute($attribute);
                if ($value === $target || str_starts_with($value, "$target~")) {
                    return $element;
                }
            }
        }
        $element = $document->querySelector($target);
        if ($element === null || !$element->hasAttribute($attribute)) {
            throw new LogicException("Nothing in <{$this->id}> has $attribute=\"$target\" or is \"$target\" with $attribute");
        }
        return $element;
    }

    /** @param list<mixed> $args */
    private function call(Element $element, string $attribute, array $args): self
    {
        // The component the element is in, as the browser finds it: this one, or a child inside
        $id = $element->closest('[data-live-id]')?->getAttribute('data-live-id') ?? $this->id;
        $this->tab->send(['t' => 'call', 'id' => $id, 'm' => $element->getAttribute($attribute), 'a' => $args]);
        return $this;
    }

    private static function document(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString("<!doctype html><body>$html", LIBXML_NOERROR);
    }
}
