<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\Insee\HistoricCommuneRecord;
use App\Enums\CommuneKind;
use App\Services\CommunePeriodMerger;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommunePeriodMergerTest extends TestCase
{
    #[Test]
    public function it_ignores_the_delegated_and_associated_communes(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('01015', 'Arbignieu', '2019-01-01', null, CommuneKind::Delegated),
            $this->record('01016', 'Arbigny', '2019-01-01', null, CommuneKind::Associated),
        ], []);

        $this->assertSame([], $periods);
    }

    #[Test]
    public function it_keeps_the_periods_of_every_code(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('01001', "L'Abergement-Clémenciat", '1943-01-01', null),
            $this->record('01002', "L'Abergement-de-Varey", '1943-01-01', null),
        ], []);

        $this->assertSame(['01001', '01002'], array_column($periods, 'code'));
    }

    #[Test]
    public function it_merges_consecutive_periods_of_a_renamed_commune(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('28274', 'Moutiers', '1943-01-01', '2026-01-01'),
            $this->record('28274', 'Moutiers-en-Beauce', '2026-01-01', null),
        ], []);

        $this->assertCount(1, $periods);
        $this->assertSame('Moutiers-en-Beauce', $periods[0]['name']);
        $this->assertSame('1943-01-01', $periods[0]['from']);
        $this->assertNull($periods[0]['to']);
    }

    #[Test]
    public function it_sorts_the_records_of_a_code_by_date(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('28274', 'Moutiers-en-Beauce', '2026-01-01', null),
            $this->record('28274', 'Moutiers', '1943-01-01', '2026-01-01'),
        ], []);

        $this->assertCount(1, $periods);
        $this->assertSame('1943-01-01', $periods[0]['from']);
    }

    #[Test]
    public function it_splits_the_periods_at_a_date_where_the_entity_changes(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('85213', 'Saint-Florent-des-Bois', '1943-01-01', '2016-01-01'),
            $this->record('85213', "Rives de l'Yon", '2016-01-01', null),
        ], ['85213' => ['2016-01-01' => true]]);

        $this->assertCount(2, $periods);
        $this->assertSame('2016-01-01', $periods[0]['to']);
        $this->assertSame('2016-01-01', $periods[1]['from']);
    }

    #[Test]
    public function it_splits_the_periods_when_the_kind_changes(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('75056', 'Paris', '1943-01-01', '1960-01-01'),
            $this->record('75056', 'Paris', '1960-01-01', null, CommuneKind::Arrondissement),
        ], []);

        $this->assertCount(2, $periods);
    }

    #[Test]
    public function it_splits_the_periods_when_there_is_a_gap_between_them(): void
    {
        $periods = (new CommunePeriodMerger)->merge([
            $this->record('01001', "L'Abergement", '1943-01-01', '1970-01-01'),
            $this->record('01001', "L'Abergement", '1980-01-01', null),
        ], []);

        $this->assertCount(2, $periods);
    }

    private function record(
        string $code,
        string $name,
        string $from,
        ?string $to,
        CommuneKind $communeKind = CommuneKind::Commune,
    ): HistoricCommuneRecord {
        return new HistoricCommuneRecord(
            $code,
            $communeKind,
            $name,
            CarbonImmutable::parse($from),
            null === $to ? null : CarbonImmutable::parse($to),
        );
    }
}
