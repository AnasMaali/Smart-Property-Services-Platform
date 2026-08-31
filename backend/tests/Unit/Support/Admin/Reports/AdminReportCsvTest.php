<?php

namespace Tests\Unit\Support\Admin\Reports;

use App\Support\Admin\Reports\AdminReportCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV formula injection protection (OWASP) - any user-controlled cell
 * value starting with `=`, `+`, `-`, or `@` must be escaped with a leading
 * `'` before it reaches a spreadsheet application, matching this feature's
 * `19. CSV SECURITY` requirement.
 */
class AdminReportCsvTest extends TestCase
{
    private function streamToString(array $header, iterable $rows): string
    {
        $response = AdminReportCsv::stream('test.csv', $header, $rows);

        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public static function formulaTriggerProvider(): array
    {
        return [
            'equals' => ['=SUM(A1:A9)'],
            'plus' => ['+1+1'],
            'minus' => ['-2+3'],
            'at' => ['@import'],
        ];
    }

    #[DataProvider('formulaTriggerProvider')]
    public function test_cells_starting_with_a_formula_trigger_are_escaped(string $value): void
    {
        $csv = $this->streamToString(['Name'], [[$value]]);

        $this->assertStringContainsString("'".$value, $csv);
        $this->assertStringNotContainsString("\n".$value, $csv);
    }

    public function test_ordinary_values_are_left_untouched(): void
    {
        $csv = $this->streamToString(['Name'], [['John Doe'], ['100.000000']]);

        $this->assertStringContainsString('John Doe', $csv);
        $this->assertStringContainsString('100.000000', $csv);
        $this->assertStringNotContainsString("'John Doe", $csv);
    }

    public function test_null_cell_values_render_as_empty_string_without_error(): void
    {
        $csv = $this->streamToString(['Name', 'Note'], [['John Doe', null]]);

        $this->assertStringContainsString('John Doe', $csv);
    }

    public function test_output_starts_with_a_utf8_bom_and_the_header_row(): void
    {
        $csv = $this->streamToString(['Name', 'Amount'], [['John Doe', '10.00']]);

        $this->assertStringStartsWith("\xEF\xBB\xBFName,Amount", $csv);
    }
}
