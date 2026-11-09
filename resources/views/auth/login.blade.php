<x-layouts.guest title="Log in" heading="Welcome back" subheading="Log in to your workspace.">
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <x-form.input name="email" label="Email" type="email" :value="old('email')" required autofocus autocomplete="username" />
        <x-form.input name="password" label="Password" type="password" required autocomplete="current-password" />

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="remember" class="size-4 rounded border-gray-300 accent-indigo-600">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary w-full">Log in</button>
    </form>

    <x-slot:footer>
        New here? <a href="{{ route('register') }}" class="font-medium text-indigo-600 hover:text-indigo-700">Create an account</a>
    </x-slot:footer>
</x-layouts.guest>
