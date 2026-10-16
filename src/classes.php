<?php

namespace Waypoint\Live;

/**
 * CSS classes from strings and conditions, for a `class` attribute: a string is always added, and so is a value
 * under an integer key of an array; a string key is added when its value is truthy. A string or key may hold
 * several classes. Each class comes once, in order, joined by one space. Every template can call it without an
 * import:
 *
 *     <li class="{{ classes('row', ['late' => $order->late, 'bg-green-100 font-bold' => $order->isReady()]) }}">
 *
 * @param string|array<array-key, mixed> ...$parts
 */
function classes(string|array ...$parts): string
{
    $classes = [];
    foreach ($parts as $part) {
        foreach (is_array($part) ? $part : [$part] as $key => $value) {
            $names = is_int($key) ? (is_string($value) ? $value : '') : ($value ? $key : '');
            foreach (preg_split('/\s+/', $names, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
                $classes[$name] = true;
            }
        }
    }
    return implode(' ', array_keys($classes));
}
