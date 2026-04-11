<?php

declare(strict_types=1);

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\ClientCost;
use App\Models\ClientRevenue;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ClientProfitability extends Component
{
    public Client $client;

    public string $costType = 'hosting';

    public string $costDescription = '';

    public string $costAmount = '';

    public bool $costRecurring = true;

    public string $costInterval = 'monthly';

    public string $revenueType = 'maintenance';

    public string $revenueDescription = '';

    public string $revenueAmount = '';

    public bool $revenueRecurring = true;

    public string $revenueInterval = 'monthly';

    public function mount(Client $client): void
    {
        $this->client = $client;
    }

    #[Computed]
    public function summary(): array
    {
        $costs = $this->client->costs;
        $revenues = $this->client->revenues;

        $monthlyRevenue = $revenues->where('is_recurring', true)
            ->sum(fn ($r) => $r->recurring_interval === 'yearly' ? $r->amount / 12 : $r->amount);

        $monthlyCost = $costs->where('is_recurring', true)
            ->sum(fn ($c) => $c->recurring_interval === 'yearly' ? $c->amount / 12 : $c->amount);

        $oneTimeRevenue = $revenues->where('is_recurring', false)->sum('amount');
        $oneTimeCost = $costs->where('is_recurring', false)->sum('amount');

        $monthlyProfit = $monthlyRevenue - $monthlyCost;
        $margin = $monthlyRevenue > 0 ? round(($monthlyProfit / $monthlyRevenue) * 100, 1) : 0;

        return [
            'mrr' => $monthlyRevenue,
            'monthly_cost' => $monthlyCost,
            'monthly_profit' => $monthlyProfit,
            'margin' => $margin,
            'one_time_revenue' => $oneTimeRevenue,
            'one_time_cost' => $oneTimeCost,
        ];
    }

    public function addCost(): void
    {
        $this->validate([
            'costDescription' => 'required|string|max:255',
            'costAmount' => 'required|numeric|min:0',
        ]);

        ClientCost::create([
            'client_id' => $this->client->id,
            'type' => $this->costType,
            'description' => $this->costDescription,
            'amount' => (float) $this->costAmount,
            'is_recurring' => $this->costRecurring,
            'recurring_interval' => $this->costRecurring ? $this->costInterval : null,
            'starts_at' => now(),
        ]);

        $this->reset('costDescription', 'costAmount');
        unset($this->summary);
        $this->client->load('costs', 'revenues');
    }

    public function addRevenue(): void
    {
        $this->validate([
            'revenueDescription' => 'required|string|max:255',
            'revenueAmount' => 'required|numeric|min:0',
        ]);

        ClientRevenue::create([
            'client_id' => $this->client->id,
            'type' => $this->revenueType,
            'description' => $this->revenueDescription,
            'amount' => (float) $this->revenueAmount,
            'is_recurring' => $this->revenueRecurring,
            'recurring_interval' => $this->revenueRecurring ? $this->revenueInterval : null,
            'starts_at' => now(),
        ]);

        $this->reset('revenueDescription', 'revenueAmount');
        unset($this->summary);
        $this->client->load('costs', 'revenues');
    }

    public function deleteEntry(string $type, int $id): void
    {
        if ($type === 'cost') {
            ClientCost::where('client_id', $this->client->id)->where('id', $id)->delete();
        } else {
            ClientRevenue::where('client_id', $this->client->id)->where('id', $id)->delete();
        }

        unset($this->summary);
        $this->client->load('costs', 'revenues');
    }

    public function render()
    {
        return view('livewire.clients.client-profitability', [
            'costs' => $this->client->costs()->orderByDesc('created_at')->get(),
            'revenues' => $this->client->revenues()->orderByDesc('created_at')->get(),
        ]);
    }
}
