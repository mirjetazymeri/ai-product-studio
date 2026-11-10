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

        <section id="api-access" class="card scroll-mt-6 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">API Access</h2>
                    <p class="mt-1 text-sm text-gray-500">Let another application work with your products through the REST API.</p>
                </div>
                <a href="{{ route('settings.api-docs', 'README.md') }}" class="btn btn-secondary">API Documentation</a>
            </div>

            @if ($newApiToken)
                <div role="status" class="mt-5 rounded-lg bg-amber-50 p-3 ring-1 ring-amber-200">
                    <label for="new-api-token" class="block text-sm font-medium text-amber-900">Copy your new token now. It won't be shown again.</label>
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                        <input id="new-api-token" type="text" readonly value="{{ $newApiToken }}" autocomplete="off" spellcheck="false" class="field min-w-0 flex-1 font-mono text-xs">
                        <button type="button" data-copy="#new-api-token" class="btn btn-secondary shrink-0">Copy</button>
                    </div>
                </div>
            @endif

            <div class="mt-5">
                <h3 class="text-sm font-semibold">API Tokens</h3>

                @if ($apiTokens->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">You haven't created any tokens yet.</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100">
                        @foreach ($apiTokens as $token)
                            <li class="flex flex-col gap-3 py-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        <span class="min-w-0 break-words">{{ $token->name }}</span>
                                        @if ($token->expires_at?->isPast())
                                            <span class="rounded-md bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-200 ring-inset">Expired</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        Created {{ $token->created_at->format('M j, Y') }}
                                        · Last Used {{ $token->last_used_at?->format('M j, Y, H:i') ?? 'Never' }}
                                        · Expiration {{ $token->expires_at?->format('M j, Y') ?? 'None' }}
                                    </p>
                                    <p class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($token->abilities as $ability)
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-600">{{ $ability }}</span>
                                        @endforeach
                                    </p>
                                </div>
                                <form method="POST" action="{{ route('settings.api-tokens.destroy', $token->id) }}" data-confirm="Revoke “{{ $token->name }}”? Applications using it will stop working immediately." class="shrink-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger-soft">Revoke</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @unless ($apiIncluded)
                <p class="mt-5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200">The REST API isn't included in your current plan. <a href="{{ route('pricing') }}" class="font-medium underline">Compare plans</a></p>
            @else
            <form method="POST" action="{{ route('settings.api-tokens.store') }}" data-single-submit class="mt-5 space-y-4 border-t border-gray-100 pt-5">
                @csrf
                <h3 class="text-sm font-semibold">Generate Token</h3>

                <x-form.input name="name" id="api-token-name" label="Token name" bag="apiToken" :value="$errors->apiToken->isNotEmpty() ? old('name') : null" required maxlength="100" hint="For example, the application that will use it." />

                <fieldset>
                    <legend class="text-sm font-medium text-gray-700">Token Permissions</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($apiAbilities as $ability)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="abilities[]" value="{{ $ability->value }}" @checked($errors->apiToken->isNotEmpty() && in_array($ability->value, (array) old('abilities'), true)) class="mt-0.5 size-4 shrink-0 rounded accent-indigo-600">
                                <span class="min-w-0"><span class="font-mono text-xs">{{ $ability->value }}</span> <span class="text-gray-500">{{ $ability->label() }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @if ($errors->apiToken->has('abilities') || $errors->apiToken->has('abilities.*'))
                        <p class="mt-1.5 text-sm text-red-600">{{ $errors->apiToken->first('abilities') ?: $errors->apiToken->first('abilities.*') }}</p>
                    @endif
                </fieldset>

                <x-form.input name="expires_at" id="api-token-expires" label="Expiration (optional)" type="date" bag="apiToken" :min="now()->addDay()->toDateString()" hint="Leave empty for a token that lasts until you revoke it." />

                <button type="submit" class="btn btn-primary">Generate Token</button>
            </form>
            @endunless
        </section>
    </div>
</x-layouts.app>
