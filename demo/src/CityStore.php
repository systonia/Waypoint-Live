<?php

namespace Demo;

/** A service the component calls directly: no API route, no fetch() in between. */
final class CityStore
{
    private const CITIES = [
        'Amsterdam', 'Athen', 'Barcelona', 'Berlin', 'Bern', 'Bremen', 'Brüssel', 'Budapest', 'Dresden',
        'Dublin', 'Düsseldorf', 'Frankfurt', 'Graz', 'Hamburg', 'Hannover', 'Helsinki', 'Kopenhagen', 'Köln',
        'Leipzig', 'Lissabon', 'London', 'Lyon', 'Madrid', 'Mailand', 'München', 'Nürnberg', 'Oslo', 'Paris',
        'Prag', 'Rom', 'Salzburg', 'Stockholm', 'Stuttgart', 'Warschau', 'Wien', 'Zürich',
    ];

    /** @return list<string> */
    public function all(): array
    {
        return self::CITIES;
    }

    /** @return list<string> */
    public function search(string $query): array
    {
        return array_values(array_filter(self::CITIES, fn (string $city): bool => mb_stripos($city, trim($query)) !== false));
    }
}
