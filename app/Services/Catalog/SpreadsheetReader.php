<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Exceptions\CatalogFileException;
use Generator;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Streams CSV and XLSX files row by row, so large files don't have to fit in memory.
 */
final class SpreadsheetReader
{
    private const MAX_COLUMNS = 100;

    private const SAMPLE_BYTES = 65536;

    /**
     * @return list<string> column headers, made unique and never blank
     *
     * @throws CatalogFileException
     */
    public function headers(string $path, string $type): array
    {
        foreach ($this->iterate($path, $type) as $cells) {
            return $this->cleanHeaders($cells);
        }

        throw new CatalogFileException('This file is empty.');
    }

    /**
     * @return Generator<int, array<int, ?string>> data rows keyed by their row number in the file
     *
     * @throws CatalogFileException
     */
    public function rows(string $path, string $type): Generator
    {
        $number = 0;

        foreach ($this->iterate($path, $type) as $cells) {
            $number++;

            if ($number > 1) {
                yield $number => array_map(CellSanitizer::fromImport(...), $cells);
            }
        }
    }

    /**
     * @return Generator<int, array<int, mixed>>
     */
    private function iterate(string $path, string $type): Generator
    {
        try {
            $reader = $this->openReader($path, $type);
        } catch (CatalogFileException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new CatalogFileException('This file couldn\'t be read. It may be damaged or not a real '.strtoupper($type).' file.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }

                // Only the first worksheet is imported.
                break;
            }
        } catch (OpenSpoutException) {
            throw new CatalogFileException('This file couldn\'t be read. It may be damaged or not a real '.strtoupper($type).' file.');
        } finally {
            $reader->close();

            // The XLSX reader's objects reference each other, and on Windows they keep the
            // file locked until collected, which would stop the upload being deleted.
            unset($sheet, $row, $reader);
            gc_collect_cycles();
        }
    }

    private function openReader(string $path, string $type): ReaderInterface
    {
        if ($type === 'xlsx') {
            if (file_get_contents($path, length: 2) !== 'PK') {
                throw new CatalogFileException('This doesn\'t look like a real Excel (.xlsx) file.');
            }

            $reader = new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
        } else {
            $reader = $this->csvReader($path);
        }

        $reader->open($path);

        return $reader;
    }

    private function csvReader(string $path): CsvReader
    {
        $sample = (string) file_get_contents($path, length: self::SAMPLE_BYTES);

        if (str_contains($sample, "\0")) {
            throw new CatalogFileException('This doesn\'t look like a text CSV file.');
        }

        // A sample can end partway through a character, so only complete lines are checked.
        $lastNewline = strrpos($sample, "\n");
        $complete = $lastNewline === false ? $sample : substr($sample, 0, $lastNewline);

        return new CsvReader(new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: $this->delimiter($sample),
            ENCODING: mb_check_encoding($complete, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        ));
    }

    private function delimiter(string $sample): string
    {
        $firstLine = strtok($sample, "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];

        arsort($counts);

        return array_key_first($counts);
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return list<string>
     */
    private function cleanHeaders(array $cells): array
    {
        if (count($cells) > self::MAX_COLUMNS) {
            throw new CatalogFileException('This file has too many columns. The limit is '.self::MAX_COLUMNS.'.');
        }

        $headers = [];
        $seen = [];

        foreach (array_values($cells) as $index => $cell) {
            $header = CellSanitizer::fromImport($cell) ?? 'Column '.($index + 1);
            $key = mb_strtolower($header);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $headers[] = $seen[$key] > 1 ? "{$header} ({$seen[$key]})" : $header;
        }

        if ($headers === [] || collect($cells)->every(fn ($cell) => CellSanitizer::fromImport($cell) === null)) {
            throw new CatalogFileException('The first row must contain column names.');
        }

        return $headers;
    }
}
