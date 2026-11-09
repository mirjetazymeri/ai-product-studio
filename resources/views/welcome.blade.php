<!DOCTYPE html>
<html lang="en">
    <x-head />
    <body class="flex min-h-screen flex-col bg-white font-sans text-gray-900 antialiased">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
            <x-brand href="{{ route('home') }}" />

            <nav class="flex items-center gap-2">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary">Open dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-secondary">Log in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary">Get started</a>
                @endauth
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-4 py-16 sm:px-6">
            <h1 class="max-w-2xl text-4xl font-semibold tracking-tight sm:text-5xl">Product content, ready for every market.</h1>
            <p class="mt-5 max-w-xl text-lg text-gray-500">
                Keep your catalogue in one place and manage titles, descriptions and SEO metadata in English, Albanian and German.
            </p>

            <div class="mt-8">
                <a href="{{ auth()->check() ? route('products.index') : route('register') }}" class="btn btn-primary px-5 py-2.5">
                    {{ auth()->check() ? 'Go to products' : 'Create your account' }}
                </a>
            </div>

            <dl class="mt-16 grid gap-8 border-t border-gray-200 pt-8 sm:grid-cols-3">
                <div>
                    <dt class="text-sm font-semibold">One catalogue</dt>
                    <dd class="mt-1 text-sm text-gray-500">Search, filter and track every product from draft to published.</dd>
                </div>
                <div>
                    <dt class="text-sm font-semibold">Three languages</dt>
                    <dd class="mt-1 text-sm text-gray-500">One current version of each product's content per language.</dd>
                </div>
                <div>
                    <dt class="text-sm font-semibold">AI drafts you control</dt>
                    <dd class="mt-1 text-sm text-gray-500">Generate content with Gemini, then review and edit it before anything is saved.</dd>
                </div>
            </dl>
        </main>
    </body>
</html>
