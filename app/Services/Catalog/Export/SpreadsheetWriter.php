<?php

namespace App\Services\Catalog\Export;

use App\Services\Catalog\CellSanitizer;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Streams rows to a file. Every cell passes through the formula-injection guard, and
 * CSV files start with a UTF-8 byte order mark so Excel reads ë, ç and ß correctly.
 *
 * XLSX files also get a styled header, set column widths, a frozen header row and filters.
 */
final class SpreadsheetWriter
{
    private const FONT = 'Calibri';

    private const HEADER_HEIGHT = 24.0;

    // A fixed height stops long text stretching a row. The full value
    // remains in the cell and shows in Excel's formula bar.
    private const ROW_HEIGHT = 18.0;

    private const DEFAULT_COLUMN_WIDTH = 18.0;

    private const COLUMN_WIDTHS = [
        'product_name' => 32.0,
        'details' => 45.0,
        'status' => 12.0,
        'language' => 13.0,
        'title' => 34.0,
        'short_description' => 45.0,
        'long_description' => 60.0,
        'meta_title' => 34.0,
        'meta_description' => 45.0,
        'slug' => 30.0,
        'keywords' => 34.0,
    ];

    private WriterInterface $writer;

    private string $type;

    /** @var list<string> */
    private array $headers = [];

    private int $rowCount = 0;

    // The library counts each Style object as a new format, so these are shared
    // to stay within Excel's limits on large exports.
    private Style $headerStyle;

    private Style $textStyle;

    private Style $multilineStyle;

    public function __construct()
    {
        $this->headerStyle = new Style(
            fontBold: true,
            fontSize: 11,
            fontColor: 'FFFFFF',
            fontName: self::FONT,
            cellVerticalAlignment: CellVerticalAlignment::CENTER,
            shouldWrapText: false,
            backgroundColor: '4F46E5',
        );

        $this->textStyle = new Style(
            fontSize: 11,
            fontName: self::FONT,
            cellVerticalAlignment: CellVerticalAlignment::TOP,
            shouldWrapText: false,
        );

        // The library always wraps text containing line breaks, so that style is shared too.
        $this->multilineStyle = $this->textStyle->withShouldWrapText(true);
    }

    public function open(string $path, string $type): void
    {
        $this->type = $type;
        $this->headers = [];
        $this->rowCount = 0;
        $this->writer = $type === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $this->writer->openToFile($path);
    }

    /**
     * @param  list<string>  $headers  machine-readable column names
     */
    public function addHeader(array $headers): void
    {
        if ($this->type !== 'xlsx') {
            $this->addRow($headers);

            return;
        }

        $this->headers = $headers;
        $this->rowCount++;

        $this->writer->addRow(Row::fromValuesWithStyle(array_map($this->label(...), $headers), $this->headerStyle, self::HEADER_HEIGHT));
    }

    /**
     * @param  list<mixed>  $cells
     */
    public function addRow(array $cells): void
    {
        $values = array_map(CellSanitizer::forExport(...), $cells);

        if ($this->type !== 'xlsx') {
            $this->writer->addRow(Row::fromValues($values));

            return;
        }

        $this->rowCount++;

        $this->writer->addRow(new Row(array_map(
            fn ($value) => Cell::fromValue($value, is_string($value) && str_contains($value, "\n") ? $this->multilineStyle : $this->textStyle),
            $values,
        ), self::ROW_HEIGHT));
    }

    public function close(): void
    {
        if ($this->type === 'xlsx') {
            $this->applySheetLayout();
        }

        $this->writer->close();
    }

    private function applySheetLayout(): void
    {
        $sheet = $this->writer->getCurrentSheet();

        foreach ($this->headers as $index => $header) {
            $sheet->setColumnWidth(self::COLUMN_WIDTHS[$header] ?? self::DEFAULT_COLUMN_WIDTH, $index + 1);
        }

        // Row 2 is the first row that scrolls, which keeps row 1 frozen.
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $sheet->setAutoFilter(new AutoFilter(0, 1, count($this->headers) - 1, $this->rowCount));
    }

    /**
     * Column names stay recognizable to the importer, so this file can be imported again.
     */
    private function label(string $header): string
    {
        return strtr(ucwords(str_replace('_', ' ', $header)), ['Id' => 'ID', 'Sku' => 'SKU']);
    }
}
