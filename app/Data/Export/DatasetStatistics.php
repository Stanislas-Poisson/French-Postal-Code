<?php

declare(strict_types=1);

namespace App\Data\Export;

/**
 * The figures of a published dataset, written next to the exported files.
 *
 * `generatedAt` is the day of the generation, `builtAt` its exact time (UTC): the workflow Watch sources compares the
 * sources with the second one, because a source can change the same day as a build that already holds it.
 */
final readonly class DatasetStatistics
{
    /**
     * @param array<string, int> $citiesBySource count of current cities by source of their point
     */
    public function __construct(
        public string $generatedAt,
        public string $builtAt,
        public ?string $cogVintage,
        public ?string $laPosteVersion,
        public int $regions,
        public int $departments,
        public int $communes,
        public int $cities,
        public int $successions,
        public array $citiesBySource,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at'     => $this->generatedAt,
            'built_at'         => $this->builtAt,
            'cog_vintage'      => $this->cogVintage,
            'laposte_version'  => $this->laPosteVersion,
            'regions'          => $this->regions,
            'departments'      => $this->departments,
            'communes'         => $this->communes,
            'cities'           => $this->cities,
            'successions'      => $this->successions,
            'cities_by_source' => $this->citiesBySource,
        ];
    }
}
