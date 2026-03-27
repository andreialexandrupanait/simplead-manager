<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $category
 * @property string $action
 * @property array|null $payload
 * @property \App\Enums\SecurityCommandPriority $priority
 */
class SecurityCommandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'action' => $this->action,
            'payload' => $this->payload,
            'priority' => $this->priority->value,
        ];
    }
}
