<?php /** @var \Demo\CityRow $this */ ?>
<li><button data-on-click="choose"<?php if ($this->selected): ?> aria-current="true"<?php endif ?>><?= $this->city ?></button></li>
