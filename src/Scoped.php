<?php

namespace Waypoint\Live;

use Attribute;

/**
 * One instance per circuit (browser tab) instead of one per app:
 * a cart, a wizard's progress, a tab's filters. Every component of the tab that injects it gets the same
 * instance; another tab gets its own. It lives as long as the circuit. Needs a constructor without required
 * arguments; its own #[Inject] properties are set, scoped ones from the same circuit.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Scoped
{
}
