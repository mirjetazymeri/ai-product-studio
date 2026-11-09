<x-layouts.guest title="Register" heading="Create your account" subheading="Start organising your product content.">
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <x-form.input name="name" label="Name" :value="old('name')" required autofocus autocomplete="name" />
        <x-form.input name="email" label="Email" type="email" :value="old('email')" required autocomplete="username" />
        <x-form.input name="password" label="Password" type="password" required autocomplete="new-password" hint="At least 8 characters." />
        <x-form.input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

        <button type="submit" class="btn btn-primary w-full">Create account</button>
    </form>

    <x-slot:footer>
        Already registered? <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-700">Log in</a>
    </x-slot:footer>
</x-layouts.guest>
