<?php

namespace Waypoint\Live\Tests\Fixtures;

use Waypoint\Attributes\{Authenticated, Permissions, Role};
use Waypoint\Live\Component;

/** Only for those who are signed in; some actions need more */
#[Component(
    selector: 'test-vault',
    template: 'Vault.Template.php'
)]
#[Authenticated]
final class Vault
{
    /** @var list<string> */
    public array $done = [];

    public function peek(): void
    {
        $this->done[] = 'peek';
    }

    #[Role(
        role: 'admin'
    )]
    public function wipe(): void
    {
        $this->done[] = 'wipe';
    }

    #[Permissions(
        permissions: ['vault.open']
    )]
    public function open(): void
    {
        $this->done[] = 'open';
    }
}
