<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class UserManagement extends Component
{
    public string $inviteEmail = '';

    public string $inviteRole = 'manager';

    public ?int $deletingUserId = null;

    /**
     * Defense-in-depth: every mutation in this component is admin-only. The
     * route carries `role:admin` middleware, but we re-check inside the
     * component so a misconfigured / bypassed route cannot let a non-admin
     * mutate users or roles.
     */
    private function authorizeAdmin(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Only admins can manage users.');
    }

    public function sendInvitation(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'inviteEmail' => 'required|email|max:255',
            'inviteRole' => ['required', Rule::enum(UserRole::class)],
        ]);

        // Check if user already exists
        if (User::where('email', $this->inviteEmail)->exists()) {
            $this->addError('inviteEmail', 'A user with this email already exists.');

            return;
        }

        // Check for pending invitation
        $existing = Invitation::where('email', $this->inviteEmail)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            $this->addError('inviteEmail', 'A pending invitation already exists for this email.');

            return;
        }

        $invitation = Invitation::create([
            'email' => $this->inviteEmail,
            'role' => $this->inviteRole,
            'token' => Str::random(64),
            'invited_by' => auth()->id(),
            'expires_at' => now()->addHours(72),
        ]);

        Mail::to($this->inviteEmail)->send(new UserInvitationMail($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        session()->flash('success', 'Invitation sent successfully.');
    }

    public function resendInvitation(int $id): void
    {
        $this->authorizeAdmin();

        $invitation = Invitation::findOrFail($id);

        if ($invitation->accepted_at) {
            return;
        }

        // Extend expiry and resend
        $invitation->update([
            'expires_at' => now()->addHours(72),
            'token' => Str::random(64),
        ]);

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation->fresh()));

        session()->flash('success', 'Invitation resent.');
    }

    public function revokeInvitation(int $id): void
    {
        $this->authorizeAdmin();

        Invitation::findOrFail($id)->delete();
        session()->flash('success', 'Invitation revoked.');
    }

    public function confirmDeleteUser(int $id): void
    {
        $this->authorizeAdmin();

        if ($id === auth()->id()) {
            return;
        }
        $this->deletingUserId = $id;
        $this->dispatch('open-modal-delete-user');
    }

    public function deleteUser(): void
    {
        $this->authorizeAdmin();

        if (! $this->deletingUserId || $this->deletingUserId === auth()->id()) {
            return;
        }

        User::findOrFail($this->deletingUserId)->delete();
        $this->deletingUserId = null;
        $this->dispatch('close-modal-delete-user');
        session()->flash('success', 'User deleted.');
    }

    public function updateRole(int $userId, string $role): void
    {
        $this->authorizeAdmin();

        // Never let a user change their own role (self-escalation).
        if ($userId === auth()->id()) {
            return;
        }

        // Reject anything that is not a real UserRole enum case. Without this,
        // a crafted role string could persist an invalid/privileged value.
        $validated = validator(
            ['role' => $role],
            ['role' => ['required', Rule::enum(UserRole::class)]],
        )->validate();

        $user = User::findOrFail($userId);
        $user->update(['role' => $validated['role']]);
    }

    public function toggleClientAssignment(int $userId, int $clientId): void
    {
        $this->authorizeAdmin();

        if ($userId === auth()->id()) {
            return;
        }

        $user = User::findOrFail($userId);
        $user->assignedClients()->toggle($clientId);
    }

    public function render()
    {
        $users = User::with('assignedClients')->orderBy('name')->get();
        $pendingInvitations = Invitation::whereNull('accepted_at')
            ->with('inviter')
            ->orderByDesc('created_at')
            ->get();
        $clients = Client::orderBy('name')->get(['id', 'name']);

        return view('livewire.settings.user-management', [
            'users' => $users,
            'pendingInvitations' => $pendingInvitations,
            'roles' => UserRole::cases(),
            'clients' => $clients,
        ])->layout('components.layouts.app', ['title' => 'Users & Invitations']);
    }
}
