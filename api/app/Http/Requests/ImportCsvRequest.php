<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is already behind auth:api
    }

    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'max:5120', // 5 MB
                'mimes:csv,txt',
                'mimetypes:text/csv,text/plain,application/csv',
            ],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
