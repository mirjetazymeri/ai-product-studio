<x-layouts.app title="Settings">
    <x-page-header title="Settings" description="Manage your account." />

    <div class="max-w-2xl space-y-6">
        <section class="card p-5 sm:p-6">
            <h2 class="text-base font-semibold">Profile</h2>
            <p class="mt-1 text-sm text-gray-500">Your name and the email you log in with.</p>

            <form method="POST" action="{{ route('settings.profile.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('PATCH')
                <x-form.input name="name" label="Name" :value="old('name', $user->name)" required autocomplete="name" />
                <x-form.input name="email" label="Email" type="email" :value="old('email', $user->email)" required autocomplete="username" />
                <button type="submit" class="btn btn-primary">Save profile</button>
            </form>
        </section>

        <section class="card p-5 sm:p-6">
            <h2 class="text-base font-semibold">Password</h2>
            <p class="mt-1 text-sm text-gray-500">Use at least 8 characters.</p>

            <form method="POST" action="{{ route('settings.password.update') }}" class="mt-5 space-y-4">
                @csrf
                @method('PUT')
                <x-form.input name="current_password" label="Current password" type="password" bag="updatePassword" required autocomplete="current-password" />
                <x-form.input name="password" label="New password" type="password" bag="updatePassword" required autocomplete="new-password" />
                <x-form.input name="password_confirmation" label="Confirm new password" type="password" bag="updatePassword" required autocomplete="new-password" />
                <button type="submit" class="btn btn-primary">Change password</button>
            </form>
        </section>

        <section class="card p-5 sm:p-6">
            <h2 class="text-base font-semibold">AI provider</h2>
            <p class="mt-1 text-sm text-gray-500">Set on the server through environment variables.</p>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Provider</dt>
                    <dd>Google Gemini</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Model</dt>
                    <dd class="min-w-0 text-right break-words">{{ config('services.gemini.model') ?: 'Not configured' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Credentials</dt>
                    <dd>{{ filled(config('services.gemini.api_key')) ? 'Configured' : 'Not configured' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</x-layouts.app>
