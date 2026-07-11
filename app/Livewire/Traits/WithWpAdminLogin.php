<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Services\WordPressApiServiceFactory;

trait WithWpAdminLogin
{
    public function openWpAdmin(): void
    {
        $this->authorizeSiteModification($this->site);

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $username = $this->site->wpAdminUser?->username;
            $result = $api->getLoginUrl($username);

            if (! empty($result['login_url'])) {
                $this->js("window.open('".addslashes($result['login_url'])."', '_blank')");

                return;
            }

            $this->dispatch('notify', type: 'error', message: 'Could not generate login URL. No URL returned.');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Could not generate login URL: '.$e->getMessage());
        }
    }

    public function setWpAdminUser(?int $siteUserId): void
    {
        $this->authorizeSiteModification($this->site);

        if ($siteUserId) {
            $exists = $this->site->siteUsers()
                ->where('id', $siteUserId)
                ->where('role', 'administrator')
                ->exists();

            if (! $exists) {
                $this->dispatch('notify', type: 'error', message: 'Selected user is not an administrator of this site.');

                return;
            }
        }

        $this->site->update(['wp_admin_user_id' => $siteUserId]);
        $this->site->load('wpAdminUser');

        $name = $this->site->wpAdminUser?->display_name ?? $this->site->wpAdminUser?->username ?? 'Default';
        $this->dispatch('notify', type: 'success', message: "WP Admin login set to: {$name}");
    }
}
