<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BuildDatasetStatistics;
use App\Services\Export\DatasetExporter;
use App\Services\Export\PackageExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ExportDatasetCommand extends Command
{
    protected $description = 'Export the dataset and its history to CSV and JSON files';

    protected $signature = 'dataset:export
        {--path= : Directory of the export (storage/app/exports by default)}
        {--package : Also write the files of the Composer package to <path>/package}';

    public function handle(
        DatasetExporter $datasetExporter,
        BuildDatasetStatistics $buildDatasetStatistics,
        PackageExporter $packageExporter,
    ): int {
        $directory = $this->directory();

        $this->printCounts('', $datasetExporter->export($directory));

        $statistics = $buildDatasetStatistics->execute()->toArray();
        $flags      = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        File::put($directory . '/statistics.json', json_encode($statistics, $flags) . "\n");

        $withPackage = (bool) $this->option('package');

        if ($withPackage) {
            $this->printCounts('package/', $packageExporter->export($directory . '/package'));
        }

        $files = $withPackage ? 'csv, json, statistics.json and package' : 'csv, json and statistics.json';

        $this->info('Exported to ' . $directory . ' (' . $files . ').');

        return self::SUCCESS;
    }

    private function directory(): string
    {
        $path = $this->option('path');

        return is_string($path) && '' !== $path ? $path : storage_path('app/exports');
    }

    /**
     * @param array<string, int> $counts number of rows written by file
     */
    private function printCounts(string $prefix, array $counts): void
    {
        foreach ($counts as $name => $count) {
            $this->line(sprintf('%-28s %d rows', $prefix . $name, $count));
        }
    }
}
