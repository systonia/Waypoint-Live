<?php /** @var \Waypoint\Live\Tests\Fixtures\Visitor $this */ ?>
<p><?= $this->connection->request->cookies['who'] ?? 'nobody' ?></p><button data-on-click="leave">leave</button>
