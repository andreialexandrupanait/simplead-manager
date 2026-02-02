# Bug Fixes & Improvements

---

## Fix 1: Plugin/Theme Update Progress Bar

Currently when updating a plugin or theme, there's no visual feedback during the process — just a toast at the bottom when it's done. This needs to be much better.

**What to build:**

When the user clicks "Update" on a plugin/theme row:
1. The update button on that specific row changes to a progress indicator (spinner + "Updating...")
2. Show an inline progress bar on that row (animated, indeterminate since we can't get exact %)
3. The row should have a subtle highlight/background color change to indicate it's being processed
4. When complete: the progress bar fills to 100%, turns green, and the row shows a success message like "✅ Updated to v8.6.0" for a few seconds
5. If it fails: show "❌ Update failed: [reason]" in red on the row
6. After a few seconds, the success/error message fades and the row returns to normal (with the new version number)

For "Update All" bulk action:
1. Show a global progress bar at the top: "Updating 3 of 5 plugins..."
2. Each row updates sequentially and shows its own status
3. When all done, show summary: "✅ 5 updated, 0 failed"

**Implementation approach:**
- Use Livewire wire:loading and events for state management
- Track `$updatingPlugins` array to know which plugins are currently updating
- Emit events from the backend as each plugin completes
- Use Alpine.js for the animated progress bar and auto-dismiss

---

## Fix 2: Plugin/Theme Activate, Deactivate, Delete Actions

Currently we can only view and update plugins/themes. We need full management capabilities.

**Add these actions to each plugin row:**

For plugins:
- **Activate** — shown for inactive plugins (green button/link)
- **Deactivate** — shown for active plugins (yellow button/link)  
- **Delete** — shown for inactive plugins only (red button/link, with confirmation modal)

For themes:
- **Activate** — shown for inactive themes
- **Delete** — shown for inactive themes only (never delete active theme, with confirmation modal)

**WordPress Connector Plugin — add these endpoints:**

```php
// Activate plugin
POST /wp-json/simplead/v1/plugins/activate
Body: { "plugin": "akismet/akismet.php" }

// Deactivate plugin
POST /wp-json/simplead/v1/plugins/deactivate
Body: { "plugin": "akismet/akismet.php" }

// Delete plugin (must be deactivated first)
POST /wp-json/simplead/v1/plugins/delete
Body: { "plugin": "akismet/akismet.php" }

// Activate theme
POST /wp-json/simplead/v1/themes/activate
Body: { "theme": "flavor-starter" }

// Delete theme (must not be active)
POST /wp-json/simplead/v1/themes/delete
Body: { "theme": "flavor-starter" }
```

**WordPress plugin implementation:**

```php
// In class-plugins.php, add:

public static function activate_plugin(WP_REST_Request $request): WP_REST_Response {
    $plugin = $request->get_param('plugin');
    $result = activate_plugin($plugin);
    
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'success' => false, 
            'error' => $result->get_error_message()
        ], 400);
    }
    
    return new WP_REST_Response(['success' => true], 200);
}

public static function deactivate_plugin(WP_REST_Request $request): WP_REST_Response {
    $plugin = $request->get_param('plugin');
    deactivate_plugins($plugin);
    return new WP_REST_Response(['success' => true], 200);
}

public static function delete_plugin(WP_REST_Request $request): WP_REST_Response {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    
    $plugin = $request->get_param('plugin');
    
    // Safety: can't delete active plugin
    if (is_plugin_active($plugin)) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Cannot delete an active plugin. Deactivate it first.'
        ], 400);
    }
    
    $result = delete_plugins([$plugin]);
    
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'success' => false,
            'error' => $result->get_error_message()
        ], 400);
    }
    
    return new WP_REST_Response(['success' => true], 200);
}
```

```php
// In class-themes.php, add:

public static function activate_theme(WP_REST_Request $request): WP_REST_Response {
    $theme_slug = $request->get_param('theme');
    $theme = wp_get_theme($theme_slug);
    
    if (!$theme->exists()) {
        return new WP_REST_Response(['success' => false, 'error' => 'Theme not found'], 404);
    }
    
    switch_theme($theme_slug);
    return new WP_REST_Response(['success' => true], 200);
}

public static function delete_theme(WP_REST_Request $request): WP_REST_Response {
    $theme_slug = $request->get_param('theme');
    $active_theme = wp_get_theme();
    
    if ($theme_slug === $active_theme->get_stylesheet()) {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Cannot delete the active theme.'
        ], 400);
    }
    
    $result = delete_theme($theme_slug);
    
    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'success' => false,
            'error' => $result->get_error_message()
        ], 400);
    }
    
    return new WP_REST_Response(['success' => true], 200);
}
```

**Register the new routes** in class-api.php and **add corresponding methods** to WordPressApiService on the Laravel side.

**UI for actions** — add a small dropdown or action buttons at the end of each plugin/theme row:

```
│  Akismet Anti-spam       v4.2.1    Active    [Deactivate]          │
│  Hello Dolly             v1.7.2    Inactive  [Activate] [Delete]   │
│  WooCommerce  ⚡ 8.5→8.6  Active    [Update] [Deactivate]         │
```

Delete should always show a confirmation modal: "Are you sure you want to delete [Plugin Name]? This cannot be undone."

After each action, trigger a sync to refresh the data.

**Rebuild the plugin zip** after adding these endpoints.

---

## Fix 3: Show WordPress Users

Add a Users section to the site overview or as a sub-tab on the plugins page.

**WordPress Connector Plugin — add endpoint:**

```php
// In class-site-info.php or a new class-users.php

register_rest_route($namespace, '/users', [
    'methods' => 'GET',
    'callback' => ['SAM_Users', 'get_list'],
    'permission_callback' => $auth,
]);

// Implementation:
public static function get_list(WP_REST_Request $request): WP_REST_Response {
    $users = get_users([
        'orderby' => 'registered',
        'order' => 'DESC',
    ]);

    $result = [];
    foreach ($users as $user) {
        $result[] = [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'role' => implode(', ', $user->roles),
            'registered' => $user->user_registered,
            'last_login' => get_user_meta($user->ID, 'last_login', true) ?: null,
            'posts_count' => count_user_posts($user->ID),
            'avatar_url' => get_avatar_url($user->ID, ['size' => 48]),
        ];
    }

    return new WP_REST_Response([
        'users' => $result,
        'total' => count($result),
    ], 200);
}
```

**Dashboard side:**

- Add `site_users` table:

```php
Schema::create('site_users', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->integer('wp_user_id');
    $table->string('username');
    $table->string('email');
    $table->string('display_name')->nullable();
    $table->string('role');
    $table->string('avatar_url')->nullable();
    $table->integer('posts_count')->default(0);
    $table->timestamp('registered_at')->nullable();
    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();
    
    $table->unique(['site_id', 'wp_user_id']);
});
```

- Add to the sync job (SyncWordPressSite) to sync users alongside plugins and themes.

- Add to WordPressApiService: `public function getUsers(): array { return $this->request('GET', 'users'); }`

**UI — show on the Plugins & Themes page as a third tab:**

```
[Plugins (18)] [Themes (3)] [Users (5)]

┌─────────────────────────────────────────────────────────────────┐
│  ┌──────┐  Andrei (admin)                    Administrator      │
│  │avatar│  andrei@simplead.ro                                   │
│  └──────┘  Registered: Jan 15, 2020  •  45 posts               │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────┐  Alex (alex)                       Editor             │
│  │avatar│  alex@simplead.ro                                     │
│  └──────┘  Registered: Mar 22, 2021  •  12 posts               │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────┐  Client User (client)              Subscriber         │
│  │avatar│  client@example.com                                   │
│  └──────┘  Registered: Nov 10, 2023  •  0 posts                │
└─────────────────────────────────────────────────────────────────┘
```

Register the new route in the WP plugin, add it to the API class, rebuild the zip.

---

## Fix 4: Header Padding on Module Pages

The header on the Plugins & Themes, Updates, and other site-context pages has less padding than expected compared to the main layout. 

**Fix:** Check the page layout wrapper and ensure consistent padding. The content area should have the same spacing as other pages (like Dashboard or Uptime). 

Likely issue: the Livewire component blade templates for these pages are missing the standard page wrapper padding. Ensure all site-context pages use the same layout structure:

```blade
<div class="p-6 lg:p-8">
    {{-- Page header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Page Title</h1>
    </div>
    
    {{-- Page content --}}
    ...
</div>
```

Check all site-context pages (plugins, themes, updates, backups, security, uptime, settings) and make sure they all use consistent `p-6 lg:p-8` padding on the outer wrapper, and `mb-6` spacing on the page header.

---

## Fix 5: Dropbox OAuth — Page Refreshes Instead of Redirecting

**This is the critical bug.** When clicking "Connect Dropbox", the page just refreshes instead of redirecting to Dropbox's authorization page.

**Likely causes and fixes:**

1. **The button is inside a Livewire component and submitting a form instead of navigating:**
   
   If the "Connect Dropbox" button is rendered inside a Livewire component, clicking it might trigger a Livewire request instead of a normal navigation. Fix by making it a plain `<a>` tag with a direct href, NOT a wire:click button:

   ```blade
   {{-- WRONG — Livewire intercepts this --}}
   <button wire:click="connectDropbox">Connect Dropbox</button>
   
   {{-- CORRECT — plain link, bypasses Livewire --}}
   <a href="{{ route('dropbox.auth') }}" 
      class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
       Connect Dropbox Account
   </a>
   ```

2. **If it must go through Livewire first (to save other form data), use redirect:**

   ```php
   // In Livewire component
   public function connectDropbox()
   {
       return redirect()->route('dropbox.auth');
       // NOT: $this->redirect() — use Laravel's redirect() helper
   }
   ```

   Or better, in Livewire 3:
   ```php
   public function connectDropbox()
   {
       return $this->redirect(route('dropbox.auth'), navigate: false);
       // navigate: false ensures full page redirect, not Livewire SPA navigation
   }
   ```

3. **Check the route is defined and not behind any middleware that redirects back:**
   
   Make sure the route exists in web.php:
   ```php
   Route::get('/settings/storage/dropbox/auth', [DropboxAuthController::class, 'redirect'])->name('dropbox.auth');
   Route::get('/settings/storage/dropbox/callback', [DropboxAuthController::class, 'callback'])->name('dropbox.callback');
   ```

4. **Check the DropboxAuthController redirect method actually redirects to Dropbox:**
   
   ```php
   public function redirect()
   {
       $params = http_build_query([
           'client_id' => config('services.dropbox.app_key'),
           'response_type' => 'code',
           'redirect_uri' => route('dropbox.callback'),
           'token_access_type' => 'offline',
       ]);

       return redirect("https://www.dropbox.com/oauth2/authorize?{$params}");
   }
   ```
   
   Make sure `DROPBOX_APP_KEY` is set in `.env`. If it's empty, the redirect URL will be malformed and might cause a redirect loop.

5. **Check the Dropbox App settings:**
   - Go to https://www.dropbox.com/developers/apps
   - Ensure the redirect URI is exactly: `https://manager.simplead.ro/settings/storage/dropbox/callback`
   - Ensure the app has the correct permissions: files.content.write, files.content.read, account_info.read

**Debug steps:**
- Check the browser Network tab when clicking the button — is it making a Livewire XHR request or a navigation?
- Check Laravel logs for any errors
- Verify `DROPBOX_APP_KEY` and `DROPBOX_APP_SECRET` are set in `.env`
- Try visiting `/settings/storage/dropbox/auth` directly in the browser — does it redirect to Dropbox?

Fix all of these issues. The most likely culprit is #1 — the button is inside a Livewire component and needs to be a plain `<a>` link.

---

## Summary of all changes:

1. ✅ Update progress bar + inline row feedback for plugin/theme updates
2. ✅ Activate/deactivate/delete actions for plugins and themes (both WP plugin + dashboard)
3. ✅ WordPress users listing (WP plugin endpoint + dashboard sync + UI tab)
4. ✅ Fix header padding on all module pages (consistent p-6 lg:p-8)
5. ✅ Fix Dropbox OAuth redirect bug (use plain <a> tag or navigate:false)
6. ✅ Rebuild the WordPress connector plugin zip with all new endpoints

Work autonomously. Fix all issues.
