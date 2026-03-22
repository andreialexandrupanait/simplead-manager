<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SyncStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => 'required|array|max:200',
            'settings.*.category' => 'required|string|max:50',
            'settings.*.key' => 'required|string|max:100',
            'settings.*.applied' => 'nullable|boolean',
            'settings.*.failed' => 'nullable|boolean',
            'settings.*.reason' => 'nullable|string|max:1000',
        ];
    }
}
