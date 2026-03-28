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
use Livewire\Component;

class UserManagement extends Component
{
    public string $inviteEmail = '';

    public string $inviteRole = 'manager';

    public ?int $deletingUserId = null;

    public function sendInvitation(): void
    {
        $this->validate([
            'inviteEmail' => 'required|email|max:255',
            'inviteRole' => 'required|in:admin,manager,viewer',
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
        Invitation::findOrFail($id)->delete();
        session()->flash('success', 'Invitation revoked.');
    }

    public function confirmDeleteUser(int $id): void
    {
        if ($id === auth()->id()) {
            return;
        }
        $this->deletingUserId = $id;
        $this->dispatch('open-modal-delete-user');
    }

    public function deleteUser(): void
    {
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
        if ($userId === auth()->id()) {
            return;
        }

        $user = User::findOrFail($userId);
        $user->update(['role' => $role]);
    }

    public function toggleClientAssignment(int $userId, int $clientId): void
    {
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
