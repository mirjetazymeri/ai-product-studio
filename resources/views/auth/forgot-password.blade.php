<x-layouts.guest title="Forgot password" heading="Reset your password" subheading="Enter your email and we'll send you a reset link.">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf
        <x-form.input name="email" label="Email" type="email" :value="old('email')" required autofocus autocomplete="username" />

        <button type="submit" class="btn btn-primary w-full">Email reset link</button>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-700">Back to log in</a>
    </x-slot:footer>
</x-layouts.guest>
