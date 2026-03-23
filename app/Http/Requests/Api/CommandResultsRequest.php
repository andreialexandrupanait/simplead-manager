<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CommandResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'results' => 'required|array|max:50',
            'results.*.command_id' => 'required|integer',
            'results.*.success' => 'required|boolean',
            'results.*.error' => 'nullable|string|max:1000',
            'results.*.data' => 'nullable|array',
        ];
    }
}
