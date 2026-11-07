<?php

declare(strict_types=1);

namespace Tests\Unit\Parsers;

use App\Data\Postal\PostalRecord;
use App\Services\Parsers\CsvFile;
use App\Services\Parsers\LaPoste\PostalCodeParser;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostalCodeParserTest extends TestCase
{
    #[Test]
    public function it_decodes_the_cp1252_encoding(): void
    {
        $path = sys_get_temp_dir() . '/laposte-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, mb_convert_encoding("#Code_commune_INSEE;Nom_de_la_commune;Code_postal;Libellé_d_acheminement;Ligne_5\n42218;SAINT ÉTIENNE;42000;SAINT ÉTIENNE;\n", 'CP1252', 'UTF-8'));

        try {
            $records = iterator_to_array((new PostalCodeParser(new CsvFile))->parse($path), false);
        }
        finally {
            (new Filesystem)->delete($path);
        }

        $this->assertCount(1, $records);
        $this->assertSame('SAINT ÉTIENNE', $records[0]->label);
        $this->assertSame('42000', $records[0]->postalCode);
    }

    #[Test]
    public function it_parses_the_postal_codes_of_a_commune(): void
    {
        $records = iterator_to_array((new PostalCodeParser(new CsvFile))->parse(__DIR__ . '/../../Fixtures/laposte/hexasmal.csv'), false);

        $tours = array_values(array_filter($records, static fn (PostalRecord $postalRecord): bool => '37261' === $postalRecord->inseeCode));

        $this->assertSame(['37000', '37100', '37200'], array_values(array_unique(array_map(static fn (PostalRecord $postalRecord): string => $postalRecord->postalCode, $tours))));
        $this->assertSame('TOURS', $tours[0]->label);
    }

    #[Test]
    public function it_skips_the_lines_without_code(): void
    {
        $path = sys_get_temp_dir() . '/laposte-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, "#Code_commune_INSEE;Nom_de_la_commune;Code_postal;Libelle;Ligne_5\n;SANS CODE;;SANS CODE;\n");

        try {
            $records = iterator_to_array((new PostalCodeParser(new CsvFile))->parse($path), false);
        }
        finally {
            (new Filesystem)->delete($path);
        }

        $this->assertSame([], $records);
    }
}
