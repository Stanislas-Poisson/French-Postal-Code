<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\Insee\CommuneMovementRecord;
use App\Models\CommuneEvent;
use App\Models\CommuneSuccession;
use App\Models\Snapshot;

final class ImportMovements
{
    private const int CHUNK_SIZE = 1000;

    /**
     * Replaces the events with those of the file: the INSEE movements file is cumulative since 1943.
     *
     * @param iterable<CommuneMovementRecord> $movements
     *
     * @return int number of events written
     */
    public function execute(iterable $movements, Snapshot $snapshot): int
    {
        // The successions keep their identifiers between two updates: detach them, so that deleting the events does not cascade to them.
        CommuneSuccession::query()->update(['commune_event_id' => null]);
        CommuneEvent::query()->delete();

        $count = 0;
        $rows  = [];

        foreach ($movements as $movement) {
            $rows[] = [
                'snapshot_id'    => $snapshot->id,
                'modality'       => $movement->modality->value,
                'effective_date' => $movement->effectiveDate->toDateString(),
                'kind_before'    => $movement->kindBefore?->value,
                'code_before'    => $movement->codeBefore,
                'name_before'    => $movement->nameBefore,
                'kind_after'     => $movement->kindAfter?->value,
                'code_after'     => $movement->codeAfter,
                'name_after'     => $movement->nameAfter,
            ];
            $count++;

            if (self::CHUNK_SIZE === count($rows)) {
                CommuneEvent::query()->insert($rows);
                $rows = [];
            }
        }

        if ([] !== $rows) {
            CommuneEvent::query()->insert($rows);
        }

        return $count;
    }
}
