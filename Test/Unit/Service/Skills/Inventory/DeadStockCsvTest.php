<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Service\Skills\Inventory;

use MagoAssistant\Mago\Service\Skills\Inventory\DeadStockCsv;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DeadStockCsvTest extends TestCase
{
    #[Test]
    public function itStartsWithAByteOrderMarkAndAHeaderLine(): void
    {
        $lines = explode("\n", (new DeadStockCsv())->build([]));

        self::assertSame(
            "\xEF\xBB\xBFSKU,Product name,Quantity,Units sold in period,Last sold,Created,Days of cover,"
            . 'Unit value,Value basis,Stock value',
            $lines[0]
        );
        self::assertSame('', $lines[1]);
    }

    #[Test]
    public function itWritesOneLinePerRowInColumnOrder(): void
    {
        $csv = (new DeadStockCsv())->build([[
            'stock_value' => 1300.0,
            'sku' => 'JACKET-1',
            'product_name' => 'Winter jacket, blue',
            'qty' => 40.0,
            'units_sold' => 0.0,
            'last_sold_at' => null,
            'created_at' => '2025-09-24 13:09:03',
            'days_of_cover' => null,
            'unit_value' => 32.5,
            'value_basis' => 'cost',
        ]]);

        self::assertSame(
            'JACKET-1,"Winter jacket, blue",40,0,,2025-09-24 13:09:03,,32.5,cost,1300',
            explode("\n", $csv)[1]
        );
    }

    #[Test]
    public function itNeutralisesTextThatASpreadsheetWouldRunAsAFormula(): void
    {
        $csv = (new DeadStockCsv())->build([
            ['sku' => '=HYPERLINK("x")', 'product_name' => '@SUM(A1)', 'qty' => -1.0],
        ]);

        self::assertSame('"\'=HYPERLINK(""x"")",\'@SUM(A1),-1,,,,,,,', explode("\n", $csv)[1]);
    }
}
