<x-layouts.guest title="Reset password" heading="Choose a new password">
    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-form.input name="email" label="Email" type="email" :value="old('email', $request->query('email'))" required autocomplete="username" />
        <x-form.input name="password" label="New password" type="password" required autofocus autocomplete="new-password" />
        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />

        <button type="submit" class="btn btn-primary w-full">Reset password</button>
    </form>
</x-layouts.guest>
