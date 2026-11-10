<!DOCTYPE html>
<html lang="en">
    <x-head title="Pricing" />
    <body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
        <header class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
            <x-brand href="{{ route('home') }}" />

            <nav class="flex items-center gap-2">
                <a href="{{ route('login') }}" class="btn btn-secondary">Log in</a>
                <a href="{{ route('register') }}" class="btn btn-primary">Get started</a>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl space-y-6 px-4 py-8 sm:px-6">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">Pricing</h1>
                <p class="mt-2 text-gray-500">Simple monthly plans. Start free, with no payment details.</p>
            </div>

            @include('billing.partials.plans')
        </main>
    </body>
</html>
