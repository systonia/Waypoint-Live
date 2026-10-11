<?php

namespace Waypoint\Live;

use Stringable;

/**
 * Markup a template prints as it is: `<?= $this->region('main') ?>` where region() returns Html. Only for HTML
 * that was built safely, such as another template's output; anything from a user stays a string and is escaped.
 */
final readonly class Html implements Stringable
{
    public function __construct(private string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
