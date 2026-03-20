<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithSorting;
use App\Models\Site;
use App\Models\SiteUser;
use App\Services\ActivityLogger;
use App\Services\WordPressApiService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityUsers extends Component
{
    use WithPagination, WithSorting, WithSiteAuthorization;

    public Site $site;
    public string $roleFilter = '';

    // Create modal
    public string $newUsername = '';
    public string $newEmail = '';
    public string $newPassword = '';
    public string $newRole = 'subscriber';
    public string $newDisplayName = '';

    // Edit modal
    public ?int $editingUserId = null;
    public string $editUsername = '';
    public string $editEmail = '';
    public string $editRole = '';
    public string $editDisplayName = '';

    // Delete modal
    public ?int $deletingUserId = null;
    public string $deletingUsername = '';
    public ?int $reassignTo = null;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        if ($this->sortBy === 'name') {
            $this->sortBy = 'role';
        }
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function lastSynced()
    {
        return SiteUser::where('site_id', $this->site->id)->max('synced_at');
    }

    #[Computed]
    public function roleCounts()
    {
        return SiteUser::where('site_id', $this->site->id)
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();
    }

    #[Computed]
    public function availableRoles(): array
    {
        $roles = SiteUser::where('site_id', $this->site->id)
            ->select('role')
            ->distinct()
            ->pluck('role')
            ->toArray();

        // Always include standard WP roles
        $standard = ['administrator', 'editor', 'author', 'contributor', 'subscriber'];
        return array_values(array_unique(array_merge($standard, $roles)));
    }

    // --- Create ---

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->newUsername = '';
        $this->newEmail = '';
        $this->newPassword = '';
        $this->newRole = 'subscriber';
        $this->newDisplayName = '';
        $this->dispatch('open-modal-create-user');
    }

    public function createUser(): void
    {
        $this->validate([
            'newUsername' => 'required|min:3|max:60',
            'newEmail' => 'required|email',
            'newPassword' => 'required|min:8',
            'newRole' => 'required',
        ]);

        try {
            $api = new WordPressApiService($this->site);
            $api->createUser([
                'username' => $this->newUsername,
                'email' => $this->newEmail,
                'password' => $this->newPassword,
                'role' => $this->newRole,
                'display_name' => $this->newDisplayName,
            ]);

            ActivityLogger::wpUserCreated($this->site, $this->newUsername);
            $this->dispatch('close-modal-create-user');
            $this->dispatch('notify', type: 'success', message: "User {$this->newUsername} created.");
            $this->clearComputedCaches();
            $this->syncUsers();
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    // --- Edit ---

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $siteUser = SiteUser::where('site_id', $this->site->id)->findOrFail($id);
        $this->editingUserId = $siteUser->wp_user_id;
        $this->editUsername = $siteUser->username;
        $this->editEmail = $siteUser->email;
        $this->editRole = $siteUser->role;
        $this->editDisplayName = $siteUser->display_name ?? '';
        $this->dispatch('open-modal-edit-user');
    }

    public function updateUser(): void
    {
        $this->validate([
            'editEmail' => 'required|email',
            'editRole' => 'required',
        ]);

        try {
            $api = new WordPressApiService($this->site);
            $api->updateUser($this->editingUserId, [
                'email' => $this->editEmail,
                'role' => $this->editRole,
                'display_name' => $this->editDisplayName,
            ]);

            ActivityLogger::wpUserUpdated($this->site, $this->editUsername);
            $this->dispatch('close-modal-edit-user');
            $this->dispatch('notify', type: 'success', message: "User {$this->editUsername} updated.");
            $this->clearComputedCaches();
            $this->syncUsers();
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    // --- Delete ---

    public function confirmDeleteUser(int $id): void
    {
        $siteUser = SiteUser::where('site_id', $this->site->id)->findOrFail($id);
        $this->deletingUserId = $siteUser->wp_user_id;
        $this->deletingUsername = $siteUser->username;
        $this->reassignTo = null;
        $this->dispatch('open-modal-delete-user');
    }

    public function deleteUser(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $api->deleteUser($this->deletingUserId, $this->reassignTo);

            // Remove local record
            SiteUser::where('site_id', $this->site->id)
                ->where('wp_user_id', $this->deletingUserId)
                ->delete();

            ActivityLogger::wpUserDeleted($this->site, $this->deletingUsername);
            $this->dispatch('close-modal-delete-user');
            $this->dispatch('notify', type: 'success', message: "User {$this->deletingUsername} deleted.");
            $this->clearComputedCaches();
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());
        }
    }

    // --- Helpers ---

    private function clearComputedCaches(): void
    {
        unset($this->roleCounts, $this->lastSynced);
    }

    private function syncUsers(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $result = $api->getUsers();
            $users = $result['users'] ?? [];
            $now = now();

            foreach ($users as $wpUser) {
                SiteUser::updateOrCreate(
                    ['site_id' => $this->site->id, 'wp_user_id' => $wpUser['id']],
                    [
                        'username' => $wpUser['login'],
                        'email' => $wpUser['email'],
                        'display_name' => $wpUser['display_name'],
                        'role' => $wpUser['roles'][0] ?? 'subscriber',
                        'last_login_at' => $wpUser['last_login'] ? \Carbon\Carbon::parse($wpUser['last_login']) : null,
                        'synced_at' => $now,
                    ]
                );
            }
        } catch (\Exception $e) {
            // Silent — the main action already succeeded
        }
    }

    public function render()
    {
        $query = SiteUser::where('site_id', $this->site->id);

        if ($this->roleFilter) {
            $query->where('role', $this->roleFilter);
        }

        $query->orderBy($this->sortBy, $this->sortDir)
            ->orderBy('username');

        return view('livewire.sites.detail.security.security-users', [
            'users' => $query->paginate(50),
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Users',
        ]);
    }
}
