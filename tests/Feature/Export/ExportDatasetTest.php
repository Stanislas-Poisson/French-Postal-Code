<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Actions\ImportCities;
use App\Data\Postal\PostalRecord;
use App\Models\CommuneSuccession;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use League\Csv\Reader;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CogFixtures;
use Tests\TestCase;

final class ExportDatasetTest extends TestCase
{
    use RefreshDatabase;

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/french-postal-code-export-' . bin2hex(random_bytes(4));

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
    public function it_exports_a_closed_commune_with_its_validity(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        $reader = Reader::from($this->directory . '/csv/communes.csv', 'r');
        $reader->setHeaderOffset(0);

        $chaille = array_values(array_filter(
            iterator_to_array($reader->getRecords(), false),
            static fn (array $row): bool => '85043' === $row['insee_code'],
        ));

        $this->assertSame('2016-01-01', $chaille[0]['valid_to']);
        $this->assertSame('85', $chaille[0]['department_code']);
    }

    #[Test]
    public function it_exports_a_valid_json_that_matches_the_csv(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        $decoded = json_decode((string) file_get_contents($this->directory . '/json/cities.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame(['37000', '37200'], array_column($decoded, 'postal_code'));
    }

    #[Test]
    public function it_exports_the_cities_with_the_code_of_their_commune(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        $reader = Reader::from($this->directory . '/csv/cities.csv', 'r');
        $reader->setHeaderOffset(0);

        $rows = iterator_to_array($reader->getRecords(), false);

        $this->assertCount(2, $rows);
        $this->assertSame('37261', $rows[0]['commune_insee_code']);
        $this->assertSame('Tours', $rows[0]['commune_name']);
        $this->assertSame('37', $rows[0]['department_code']);
        $this->assertSame('24', $rows[0]['region_code']);
        $this->assertSame(['37000', '37200'], array_column($rows, 'postal_code'));
    }

    #[Test]
    public function it_exports_the_history_that_lets_a_user_migrate_an_old_code(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        $reader = Reader::from($this->directory . '/csv/commune_successions.csv', 'r');
        $reader->setHeaderOffset(0);

        $absorbed = array_values(array_filter(
            iterator_to_array($reader->getRecords(), false),
            static fn (array $row): bool => '85043' === $row['from_code'],
        ));

        $this->assertCount(1, $absorbed);
        $this->assertSame('85213', $absorbed[0]['to_code']);
        $this->assertSame('absorbed', $absorbed[0]['kind']);
        $this->assertSame('2016-01-01', $absorbed[0]['effective_date']);
    }

    #[Test]
    public function it_writes_a_csv_and_a_json_file_for_each_dataset(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        foreach (['regions', 'departments', 'communes', 'cities', 'commune_successions', 'reference_changes'] as $name) {
            $this->assertFileExists($this->directory . '/csv/' . $name . '.csv');
            $this->assertFileExists($this->directory . '/json/' . $name . '.json');
        }
    }

    #[Test]
    public function it_writes_the_exact_time_of_the_generation(): void
    {
        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        /** @var array<string, mixed> $statistics */
        $statistics = json_decode((string) file_get_contents($this->directory . '/statistics.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsString($statistics['built_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $statistics['built_at']);
        $this->assertSame($statistics['generated_at'], substr($statistics['built_at'], 0, 10));
    }

    #[Test]
    public function it_writes_the_figures_of_the_dataset_next_to_the_files(): void
    {
        Snapshot::query()->delete();
        Snapshot::query()->create(['source' => 'insee_cog', 'version' => '2026', 'checksum' => 'a', 'fetched_at' => now(), 'imported_at' => now()]);
        Snapshot::query()->create(['source' => 'laposte', 'version' => '2026-09', 'checksum' => 'b', 'fetched_at' => now(), 'imported_at' => now()]);

        $this->command('dataset:export', ['--path' => $this->directory])->assertSuccessful();

        /** @var array<string, mixed> $statistics */
        $statistics = json_decode((string) file_get_contents($this->directory . '/statistics.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $statistics['cities']);
        $this->assertGreaterThan(0, $statistics['communes']);
        $this->assertSame(CommuneSuccession::query()->count(), $statistics['successions']);
        $this->assertSame(['none' => 2], $statistics['cities_by_source']);
        $this->assertSame('2026', $statistics['cog_vintage']);
        $this->assertSame('2026-09', $statistics['laposte_version']);
    }
}
