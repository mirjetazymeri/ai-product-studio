<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

trait BuildsSpreadsheets
{
    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function csvFile(array $rows, string $name = 'products.csv'): UploadedFile
    {
        $lines = array_map(function (array $row) {
            $stream = fopen('php://temp', 'r+');
            fputcsv($stream, $row, escape: '');
            rewind($stream);

            return stream_get_contents($stream);
        }, $rows);

        return UploadedFile::fake()->createWithContent($name, implode('', $lines));
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    protected function xlsxFile(array $rows, string $name = 'products.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @return list<array<int, mixed>>
     */
    protected function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();

        return $rows;
    }

    /**
     * @return list<array<int, string>>
     */
    protected function readCsv(string $path): array
    {
        $stream = fopen($path, 'r');
        fseek($stream, 3);
        $rows = [];

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }
}
