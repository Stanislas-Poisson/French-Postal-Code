<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EventModality;
use App\Enums\SuccessionKind;
use App\Models\CommuneEvent;
use App\Models\CommuneSuccession;
use Illuminate\Support\LazyCollection;

final class BuildSuccessions
{
    private const int CHUNK_SIZE = 1000;

    /**
     * Derives, from the events, the succession of the commune codes (and arrondissement codes).
     * Only the events between two communes are used: delegated and associated communes do not own a code of their own.
     *
     * The identifiers are kept: a succession that already exists with the same codes, kind and date keeps its
     * identifier, because the package loads the files by identifier and would otherwise duplicate the successions.
     *
     * @return int number of successions written
     */
    public function execute(): int
    {
        $available = $this->existingIds();
        $count     = 0;

        foreach ($this->rows()->chunk(self::CHUNK_SIZE) as $chunk) {
            $kept = [];
            $new  = [];

            foreach ($chunk as $row) {
                $key = $this->keyOf($row);

                if (isset($available[$key]) && [] !== $available[$key]) {
                    $row['id'] = array_shift($available[$key]);
                    $kept[]    = $row;
                }
                else {
                    $new[] = $row;
                }
            }

            if ([] !== $kept) {
                CommuneSuccession::query()->upsert($kept, ['id'], ['commune_event_id', 'from_code', 'to_code', 'kind', 'effective_date']);
            }

            if ([] !== $new) {
                CommuneSuccession::query()->insert($new);
            }

            $count += $chunk->count();
        }

        // What the events no longer give is removed.
        foreach (array_chunk(array_merge(...array_values($available)), self::CHUNK_SIZE) as $ids) {
            CommuneSuccession::query()->whereIn('id', $ids)->delete();
        }

        return $count;
    }

    /**
     * @return array<string, list<int>> the identifiers of the existing successions, by codes, kind and date
     */
    private function existingIds(): array
    {
        $ids = [];

        foreach (CommuneSuccession::query()->orderBy('id')->get() as $communeSuccession) {
            $ids[$this->keyOf([
                'from_code'      => $communeSuccession->from_code,
                'to_code'        => $communeSuccession->to_code,
                'kind'           => $communeSuccession->kind->value,
                'effective_date' => $communeSuccession->effective_date->toDateString(),
            ])][] = $communeSuccession->id;
        }

        return $ids;
    }

    /**
     * @param array{from_code: string, to_code: string|null, kind: string, effective_date: string} $row
     */
    private function keyOf(array $row): string
    {
        return implode('|', [$row['from_code'], $row['to_code'] ?? '', $row['kind'], $row['effective_date']]);
    }

    private function kindOf(CommuneEvent $communeEvent): ?SuccessionKind
    {
        return match (true) {
            null === $communeEvent->code_before,
            true !== $communeEvent->kind_before?->ownsCode() => null,
            null === $communeEvent->code_after               => $this->kindWithoutSuccessor($communeEvent),
            // A commune becoming an arrondissement (or the opposite) is not a change of commune code.
            $communeEvent->kind_before !== $communeEvent->kind_after,
            ! $communeEvent->kind_after->ownsCode()         => null,
            default                                         => $this->kindWithSuccessor($communeEvent),
        };
    }

    private function kindWithoutSuccessor(CommuneEvent $communeEvent): ?SuccessionKind
    {
        return EventModality::Deletion === $communeEvent->modality ? SuccessionKind::Deleted : null;
    }

    private function kindWithSuccessor(CommuneEvent $communeEvent): ?SuccessionKind
    {
        $sameCode = $communeEvent->code_before === $communeEvent->code_after;

        return match ($communeEvent->modality) {
            EventModality::NameChange => SuccessionKind::Renamed,
            EventModality::Deletion,
            EventModality::SimpleMerger,
            EventModality::NewCommuneCreation,
            EventModality::AssociatedMerger => $sameCode ? SuccessionKind::CodeReused : SuccessionKind::Absorbed,
            EventModality::CodeChangeDepartment,
            EventModality::CodeChangeSeat => $sameCode ? null : SuccessionKind::Replaced,
            EventModality::Creation,
            EventModality::Reinstatement => $sameCode ? null : SuccessionKind::Split,
            default                      => null,
        };
    }

    /**
     * @return LazyCollection<int, array{commune_event_id: int, from_code: string, to_code: string|null, kind: string, effective_date: string}>
     */
    private function rows(): LazyCollection
    {
        return CommuneEvent::query()
            ->orderBy('id')
            ->cursor()
            ->map($this->toRow(...))
            ->filter();
    }

    /**
     * @return array{commune_event_id: int, from_code: string, to_code: string|null, kind: string, effective_date: string}|null
     */
    private function toRow(CommuneEvent $communeEvent): ?array
    {
        $kind = $this->kindOf($communeEvent);

        if (! $kind instanceof SuccessionKind) {
            return null;
        }

        return [
            'commune_event_id' => $communeEvent->id,
            'from_code'        => (string) $communeEvent->code_before,
            'to_code'          => SuccessionKind::Deleted === $kind ? null : $communeEvent->code_after,
            'kind'             => $kind->value,
            'effective_date'   => $communeEvent->effective_date->toDateString(),
        ];
    }
}
