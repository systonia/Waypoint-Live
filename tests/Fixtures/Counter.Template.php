<?php /** @var \Waypoint\Live\Tests\Fixtures\Counter $this */ ?>
<button data-on-click="add" data-arg="1"><?= $this->label ?></button>
<output><?= $this->count ?></output>
<?php if ($this->admin): ?>admin<?php endif ?>
<?php echo $this->label ?>
