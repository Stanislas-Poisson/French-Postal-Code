<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Actions\ImportCities;
use App\Data\Postal\PostalRecord;
use App\Models\City;
use App\Models\Snapshot;
use App\Services\Export\PackageExporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Csv\Reader;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CogFixtures;
use Tests\TestCase;

final class ExportPackageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/french-postal-code-package-' . bin2hex(random_bytes(4));

        CogFixtures::import($this->app);
        $this->app->make(ImportCities::class)->execute(
            [new PostalRecord('37261', '37000', 'TOURS'), new PostalRecord('37261', '37200', 'TOURS')],
            CarbonImmutable::parse('2026-01-01'),
            CogFixtures::snapshot($this->app, 'laposte'),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function it_describes_the_files_in_a_manifest(): void
    {
        Snapshot::query()->delete();
        Snapshot::query()->create(['source' => 'insee_cog', 'version' => '2026', 'checksum' => 'a', 'fetched_at' => now(), 'imported_at' => now()]);
        Snapshot::query()->create(['source' => 'laposte', 'version' => '2026-09', 'checksum' => 'b', 'fetched_at' => now(), 'imported_at' => now()]);

        $this->command('dataset:export', ['--path' => $this->directory, '--package' => true])->assertSuccessful();

        /** @var array{cog_vintage: string, laposte_version: string, tables: array<string, array{rows: int, columns: list<string>}>} $manifest */
        $manifest = json_decode((string) file_get_contents($this->directory . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2026', $manifest['cog_vintage']);
        $this->assertSame('2026-09', $manifest['laposte_version']);
        $this->assertSame(array_keys(PackageExporter::TABLES), array_keys($manifest['tables']));

        foreach (PackageExporter::TABLES as $table => $columns) {
            $this->assertSame($columns, $manifest['tables'][$table]['columns']);
            $this->assertSame(count($this->rows($table)['rows']), $manifest['tables'][$table]['rows']);
        }
    }

    #[Test]
    public function it_does_not_write_the_package_unless_asked(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->directory . '/package');
    }

    #[Test]
    public function it_keeps_the_identifiers_of_the_relations(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory, '--package' => true])->assertSuccessful();

        $regions     = $this->identifiers('regions');
        $departments = $this->identifiers('departments');
        $communes    = $this->identifiers('communes');
        $cities      = $this->identifiers('cities');

        $this->assertNotEmpty($cities);

        foreach ($this->rows('departments')['rows'] as $row) {
            $this->assertTrue('' === $row['region_id'] || in_array($row['region_id'], $regions, true));
        }

        foreach ($this->rows('communes')['rows'] as $row) {
            $this->assertTrue('' === $row['department_id'] || in_array($row['department_id'], $departments, true));
        }

        foreach ($this->rows('cities')['rows'] as $row) {
            $this->assertContains($row['commune_id'], $communes);
            $this->assertTrue('' === $row['replaced_by_city_id'] || in_array($row['replaced_by_city_id'], $cities, true));
        }
    }

    #[Test]
    public function it_writes_a_city_replaced_by_another_one_with_both_identifiers(): void
    {
        $replaced    = City::query()->orderBy('id')->firstOrFail();
        $replacement = City::query()->orderBy('id')->skip(1)->firstOrFail();

        DB::table('cities')->where('id', $replaced->id)->update(['replaced_by_city_id' => $replacement->id, 'valid_to' => '2026-06-01']);

        $this->command('dataset:export', ['--path' => $this->directory, '--package' => true])->assertSuccessful();

        $first = $this->rows('cities')['rows'][0];

        $this->assertSame((string) $replacement->id, $first['replaced_by_city_id']);
        $this->assertSame('2026-06-01', $first['valid_to']);
    }

    #[Test]
    public function it_writes_empty_values_for_what_is_missing(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory, '--package' => true])->assertSuccessful();

        $city = $this->rows('cities')['rows'][0];

        $this->assertSame('', $city['valid_to']);
        $this->assertSame('', $city['replaced_by_city_id']);
    }

    #[Test]
    public function it_writes_one_file_per_table_with_the_columns_of_the_table(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory, '--package' => true])
            ->expectsOutputToContain('package/regions')
            ->assertSuccessful();

        foreach (PackageExporter::TABLES as $table => $columns) {
            $this->assertSame($columns, $this->rows($table)['header'], $table);
        }
    }

    /**
     * @return list<string>
     */
    private function identifiers(string $table): array
    {
        return array_map(static fn (array $row): string => $row['id'], $this->rows($table)['rows']);
    }

    /**
     * @return array{header: list<string>, rows: list<array<string, string>>}
     */
    private function rows(string $table): array
    {
        $reader = Reader::from($this->directory . '/package/' . $table . '.csv', 'r');
        $reader->setHeaderOffset(0);

        /** @var list<array<string, string>> $rows */
        $rows = iterator_to_array($reader->getRecords(), false);

        return ['header' => array_values($reader->getHeader()), 'rows' => $rows];
    }
}
