<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ActivityLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logs' => 'required|array|max:1000',
            'logs.*.event_type' => 'required|string|max:50',
            'logs.*.username' => 'nullable|string|max:255',
            'logs.*.object_type' => 'nullable|string|max:50',
            'logs.*.object_name' => 'nullable|string|max:255',
            'logs.*.action' => 'nullable|string|max:100',
            'logs.*.ip_address' => 'nullable|ip',
            'logs.*.user_agent' => 'nullable|string|max:500',
            'logs.*.details' => 'nullable|array|max:50',
            'logs.*.occurred_at' => 'nullable|date',
        ];
    }
}
