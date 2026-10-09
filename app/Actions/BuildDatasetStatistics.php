<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\Export\DatasetStatistics;
use App\Models\City;
use App\Models\Commune;
use App\Models\CommuneSuccession;
use App\Models\Department;
use App\Models\Region;
use App\Models\Snapshot;

final class BuildDatasetStatistics
{
    /**
     * Counts what is currently valid in the dataset, with the version of the sources it was built from.
     */
    public function execute(): DatasetStatistics
    {
        /** @var array<string, int> $bySource */
        $bySource = City::query()
            ->current()
            ->selectRaw("coalesce(coordinate_source, 'none') as source, count(*) as total")
            ->groupBy('source')
            ->pluck('total', 'source')
            ->map(static fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
            ->all();

        return new DatasetStatistics(
            generatedAt: now()->toDateString(),
            builtAt: now()->utc()->toIso8601ZuluString(),
            cogVintage: $this->latestVersion(FetchSources::COG),
            laPosteVersion: $this->latestVersion(FetchSources::LA_POSTE),
            regions: Region::query()->current()->count(),
            departments: Department::query()->current()->count(),
            communes: Commune::query()->current()->count(),
            cities: City::query()->current()->count(),
            successions: CommuneSuccession::query()->count(),
            citiesBySource: $bySource,
        );
    }

    private function latestVersion(string $source): ?string
    {
        $snapshot = Snapshot::query()
            ->where('source', $source)
            ->whereNotNull('imported_at')
            ->latest('imported_at')
            ->orderByDesc('id')
            ->first();

        return $snapshot?->version;
    }
}
