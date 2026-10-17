<?php

namespace Waypoint\Live;

use Attribute;

/**
 * A public property the page sets when it places the component: `$this->live('app-add-to-cart', ['product' => 3])`.
 * The value comes back from the browser when it connects, so treat it like a query parameter, never as proof
 * of who the user is. Properties without #[Input] cannot be set from outside.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Input
{
}
