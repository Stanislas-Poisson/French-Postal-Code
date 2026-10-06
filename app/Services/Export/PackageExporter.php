<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Actions\BuildDatasetStatistics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Csv\Writer;

/**
 * Writes the dataset in the format loaded by the Composer package for Laravel and Symfony.
 *
 * Unlike the published files, which replace the foreign keys by codes so that a file can be read on its own,
 * a file here holds the columns of its table, with the identifiers of the relations: the package inserts the rows
 * with their original identifiers, so that the foreign keys of an application stay valid from one version to the next.
 */
final readonly class PackageExporter
{
    /**
     * The tables of the package, in the order they are loaded, with their columns.
     *
     * @var array<string, list<string>>
     */
    public const array TABLES = [
        'regions'             => ['id', 'code', 'name', 'slug', 'valid_from', 'valid_to'],
        'departments'         => ['id', 'region_id', 'code', 'type', 'name', 'slug', 'valid_from', 'valid_to'],
        'communes'            => [
            'id',
            'department_id',
            'insee_code',
            'kind',
            'name',
            'slug',
            'centre_latitude',
            'centre_longitude',
            'valid_from',
            'valid_to',
        ],
        'cities'              => [
            'id',
            'commune_id',
            'postal_code',
            'label',
            'latitude',
            'longitude',
            'address_count',
            'coordinate_source',
            'valid_from',
            'valid_to',
            'replaced_by_city_id',
        ],
        'commune_successions' => ['id', 'from_code', 'to_code', 'kind', 'effective_date'],
    ];

    public function __construct(private BuildDatasetStatistics $buildDatasetStatistics) {}

    /**
     * @return array<string, int> number of rows written by table
     */
    public function export(string $directory): array
    {
        File::ensureDirectoryExists($directory);

        $counts = [];

        foreach (self::TABLES as $table => $columns) {
            $counts[$table] = $this->write($directory, $table, $columns);
        }

        $this->writeManifest($directory, $counts);

        return $counts;
    }

    /**
     * @param list<string> $columns
     */
    private function write(string $directory, string $table, array $columns): int
    {
        $writer = Writer::from($directory . '/' . $table . '.csv', 'w');
        $writer->insertOne($columns);

        $count = 0;

        foreach (DB::table($table)->select($columns)->orderBy('id')->cursor() as $lazyCollection) {
            $writer->insertOne(array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                array_values((array) $lazyCollection),
            ));
            $count++;
        }

        return $count;
    }

    /**
     * The manifest lets the package check what it loads: the versions of the sources, and the rows and columns of each file.
     *
     * @param array<string, int> $counts
     */
    private function writeManifest(string $directory, array $counts): void
    {
        $datasetStatistics = $this->buildDatasetStatistics->execute();

        $tables = [];

        foreach (self::TABLES as $table => $columns) {
            $tables[$table] = ['rows' => $counts[$table], 'columns' => $columns];
        }

        File::put(
            $directory . '/manifest.json',
            json_encode([
                'generated_at'    => $datasetStatistics->generatedAt,
                'cog_vintage'     => $datasetStatistics->cogVintage,
                'laposte_version' => $datasetStatistics->laPosteVersion,
                'tables'          => $tables,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
