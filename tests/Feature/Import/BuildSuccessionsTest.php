<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Actions\BuildSuccessions;
use App\Enums\CommuneKind;
use App\Enums\EventModality;
use App\Enums\SuccessionKind;
use App\Models\CommuneEvent;
use App\Models\CommuneSuccession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CogFixtures;
use Tests\TestCase;

final class BuildSuccessionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{EventModality, CommuneKind|null, string|null, CommuneKind|null, string|null, SuccessionKind|null}>
     */
    public static function rules(): iterable
    {
        $com = CommuneKind::Commune;

        yield 'rename keeps the code' => [EventModality::NameChange, $com, '28274', $com, '28274', SuccessionKind::Renamed];

        yield 'absorbed by a merger' => [EventModality::NewCommuneCreation, $com, '85043', $com, '85213', SuccessionKind::Absorbed];

        yield 'simple merger' => [EventModality::SimpleMerger, $com, '21551', $com, '21084', SuccessionKind::Absorbed];

        yield 'seat commune keeps its code' => [EventModality::NewCommuneCreation, $com, '85213', $com, '85213', SuccessionKind::CodeReused];

        yield 'code change of a transfer' => [EventModality::CodeChangeSeat, $com, '49069', $com, '49126', SuccessionKind::Replaced];

        yield 'code change of a department' => [EventModality::CodeChangeDepartment, $com, '14513', $com, '50649', SuccessionKind::Replaced];

        yield 'reinstatement splits a commune' => [EventModality::Reinstatement, $com, '15141', $com, '15031', SuccessionKind::Split];

        yield 'creation splits a commune' => [EventModality::Creation, $com, '97357', $com, '97362', SuccessionKind::Split];

        yield 'deletion without successor' => [EventModality::Deletion, $com, '97123', null, null, SuccessionKind::Deleted];

        yield 'creation of the same code is ignored' => [EventModality::Creation, $com, '97357', $com, '97357', null];

        yield 'delegated commune is ignored' => [EventModality::NewCommuneCreation, $com, '01330', CommuneKind::Delegated, '01330', null];

        yield 'associated commune is ignored' => [EventModality::AssociatedMerger, CommuneKind::Associated, '01324', $com, '01245', null];

        yield 'commune to arrondissement is ignored' => [EventModality::Creation, $com, '13055', CommuneKind::Arrondissement, '13201', null];
    }

    #[Test]
    #[DataProvider('rules')]
    public function it_derives_the_succession_of_an_event(
        EventModality $eventModality,
        ?CommuneKind $kindBefore,
        ?string $codeBefore,
        ?CommuneKind $kindAfter,
        ?string $codeAfter,
        ?SuccessionKind $successionKind,
    ): void {
        $snapshot = CogFixtures::snapshot($this->app);

        CommuneEvent::query()->create([
            'snapshot_id'    => $snapshot->id,
            'modality'       => $eventModality,
            'effective_date' => '2016-01-01',
            'kind_before'    => $kindBefore,
            'code_before'    => $codeBefore,
            'kind_after'     => $kindAfter,
            'code_after'     => $codeAfter,
        ]);

        $count = $this->app->make(BuildSuccessions::class)->execute();

        if (! $successionKind instanceof SuccessionKind) {
            $this->assertSame(0, $count);

            return;
        }

        $communeSuccession = CommuneSuccession::query()->firstOrFail();
        $this->assertSame($successionKind, $communeSuccession->kind);
        $this->assertSame($codeBefore, $communeSuccession->from_code);
        $this->assertSame(SuccessionKind::Deleted === $successionKind ? null : $codeAfter, $communeSuccession->to_code);
    }

    #[Test]
    public function it_keeps_the_identifiers_when_the_events_are_replaced(): void
    {
        CogFixtures::import($this->app);
        $before = $this->successions();
        $this->assertNotSame([], $before);

        // What the next import does: the events are replaced by new ones, the successions are built again.
        CommuneSuccession::query()->update(['commune_event_id' => null]);
        $events = CommuneEvent::query()->get()->map(fn (CommuneEvent $communeEvent): array => $communeEvent->only([
            'snapshot_id', 'modality', 'effective_date', 'kind_before', 'code_before', 'name_before', 'kind_after', 'code_after', 'name_after',
        ]))->all();
        CommuneEvent::query()->delete();

        foreach ($events as $event) {
            CommuneEvent::query()->create($event);
        }

        $this->app->make(BuildSuccessions::class)->execute();

        $this->assertSame($before, $this->successions());
    }

    #[Test]
    public function it_rebuilds_the_successions_without_duplicates(): void
    {
        CogFixtures::import($this->app);
        $count = CommuneSuccession::query()->count();

        $this->app->make(BuildSuccessions::class)->execute();

        $this->assertSame($count, CommuneSuccession::query()->count());
    }

    #[Test]
    public function it_removes_the_successions_that_the_events_no_longer_give(): void
    {
        CogFixtures::import($this->app);
        $count = CommuneSuccession::query()->count();
        $this->assertGreaterThan(1, $count);

        CommuneSuccession::query()->update(['commune_event_id' => null]);
        CommuneEvent::query()->limit(1)->delete();
        $remaining = CommuneEvent::query()->count();

        $this->app->make(BuildSuccessions::class)->execute();

        $this->assertLessThan($count, CommuneSuccession::query()->count());
        $this->assertSame($remaining, CommuneEvent::query()->count());
    }

    /**
     * @return array<int, array{int, string, string|null, string, string}>
     */
    private function successions(): array
    {
        return CommuneSuccession::query()->orderBy('id')->get()->map(fn (CommuneSuccession $communeSuccession): array => [
            $communeSuccession->id,
            $communeSuccession->from_code,
            $communeSuccession->to_code,
            $communeSuccession->kind->value,
            $communeSuccession->effective_date->toDateString(),
        ])->values()->all();
    }
}
