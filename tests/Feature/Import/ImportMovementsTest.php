<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Actions\ImportMovements;
use App\Data\Insee\CommuneMovementRecord;
use App\Enums\CommuneKind;
use App\Enums\EventModality;
use App\Models\CommuneEvent;
use App\Models\CommuneSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CogFixtures;
use Tests\TestCase;

final class ImportMovementsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_delete_the_successions_with_the_events(): void
    {
        CogFixtures::import($this->app);
        $ids = CommuneSuccession::query()->orderBy('id')->pluck('id')->all();
        $this->assertNotSame([], $ids);

        $this->app->make(ImportMovements::class)->execute([], CogFixtures::snapshot($this->app));

        $this->assertSame($ids, CommuneSuccession::query()->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_writes_the_movements_by_chunks_without_losing_any(): void
    {
        $movements = [];

        for ($i = 0; 1001 > $i; $i++) {
            $movements[] = new CommuneMovementRecord(
                EventModality::NameChange,
                CarbonImmutable::parse('2026-01-01'),
                CommuneKind::Commune,
                '28274',
                'Moutiers',
                CommuneKind::Commune,
                '28274',
                'Moutiers-en-Beauce',
            );
        }

        $count = $this->app->make(ImportMovements::class)->execute($movements, CogFixtures::snapshot($this->app));

        $this->assertSame(1001, $count);
        $this->assertSame(1001, CommuneEvent::query()->count());
    }
}
