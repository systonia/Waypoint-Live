<?php

namespace Demo;

use Waypoint\Attributes\Inject;
use Waypoint\Live\{Action, Component};

final class CitySearch extends Component
{
    #[Inject]
    private CityStore $cities;

    private string $query = '';
    private ?string $selected = null;

    #[Action]
    public function search(string $query): void
    {
        $this->query = $query;
    }

    #[Action]
    public function select(string $city): void
    {
        $this->selected = $city;
    }

    public function render(): string
    {
        $rows = '';
        foreach ($this->cities->search($this->query) as $city) {
            $current = $city === $this->selected ? ' aria-current="true"' : '';
            $rows .= "<li><button wp-live-click=\"select\" wp-live-arg=\"{$this->e($city)}\"$current>{$this->e($city)}</button></li>";
        }
        $selected = $this->selected !== null ? "Ausgewählt: {$this->e($this->selected)}" : 'Nichts ausgewählt';
        return <<<HTML
            <input type="search" wp-live-input="search" value="{$this->e($this->query)}" placeholder="Stadt suchen" aria-label="Stadt suchen">
            <p>{$selected}</p>
            <ul>{$rows}</ul>
            HTML;
    }
}
