<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UploadCatalogFileRequest extends FormRequest
{
    /**
     * The file's real structure is checked again when it is parsed.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.config('catalog.max_file_kb'),
                'extensions:csv,xlsx',
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV or Excel file to upload.',
            'file.max' => 'This file is too large. The limit is '.round(config('catalog.max_file_kb') / 1024).' MB.',
            'file.extensions' => 'Only .csv and .xlsx files are supported.',
            'file.mimetypes' => 'Only CSV and Excel files are supported.',
        ];
    }
}
