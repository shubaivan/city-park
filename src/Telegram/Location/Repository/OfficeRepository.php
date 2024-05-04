<?php

namespace App\Telegram\Location\Repository;

use App\Telegram\Location\Model\Office;

class OfficeRepository
{
    /**
     * @var Office[]|null
     */
    private ?array $offices;

    public function __construct()
    {
        $this->offices = null;
    }

    /**
     * @return Office[]
     */
    public function findNearest(float $latitude, float $longitude, int $count = 3): array
    {
        $this->init();

        $offices = [];
        foreach ((array) $this->offices as $office) {
            $offices[(string) $office->getDistance($latitude, $longitude)] = $office;
        }

        ksort($offices);

        return \array_slice($offices, 0, $count);
    }

    private function init()
    {
        if (null !== $this->offices) {
            return;
        }

        $this->offices = [
            new Office('Cherkasy', 49.4405128, 32.0964188),
        ];
    }
}