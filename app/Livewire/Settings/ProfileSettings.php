<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProfileSettings extends Component
{
    use WithFileUploads;

    // Profile
    public string $name = '';
    public string $email = '';
    public string $timezone = 'UTC';
    public $avatar = null;

    // Password
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->timezone = $user->timezone ?? 'UTC';
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . Auth::id(),
            'timezone' => 'required|timezone',
            'avatar' => 'nullable|image|max:2048',
        ]);

        $user = Auth::user();
        $user->name = $this->name;
        $user->email = $this->email;
        $user->timezone = $this->timezone;

        if ($this->avatar) {
            $path = $this->avatar->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        session()->flash('profile-saved', true);
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => 'required',
            'newPassword' => ['required', 'string', Password::min(8), 'different:currentPassword'],
            'newPasswordConfirmation' => 'required|same:newPassword',
        ]);

        if (!Hash::check($this->currentPassword, Auth::user()->password)) {
            $this->addError('currentPassword', 'The current password is incorrect.');
            return;
        }

        Auth::user()->update([
            'password' => $this->newPassword,
        ]);

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');

        session()->flash('password-changed', true);
    }

    public function logoutOtherSessions(): void
    {
        DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();

        session()->flash('sessions-cleared', true);
    }

    public function deleteAccount(): void
    {
        $user = Auth::user();
        Auth::logout();
        $user->delete();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/login');
    }

    public function getSessionsProperty()
    {
        return DB::table('sessions')
            ->where('user_id', Auth::id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) {
                return (object) [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_activity' => \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                    'is_current' => $session->id === session()->getId(),
                ];
            });
    }

    public function render()
    {
        return view('livewire.settings.profile-settings')
            ->layout('components.layouts.app', ['title' => 'Profile']);
    }
}
