@props(['title' => null, 'heading', 'subheading' => null])

<!DOCTYPE html>
<html lang="en">
    <x-head :title="$title ?? $heading" />
    <body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-12">
            <x-brand href="{{ route('home') }}" class="mb-8 self-center" />

            <div class="card p-6 sm:p-8">
                <h1 class="text-xl font-semibold tracking-tight">{{ $heading }}</h1>

                @if ($subheading)
                    <p class="mt-1 text-sm text-gray-500">{{ $subheading }}</p>
                @endif

                <x-flash class="mt-5" />

                <div class="mt-6">
                    {{ $slot }}
                </div>
            </div>

            @isset($footer)
                <p class="mt-6 text-center text-sm text-gray-500">{{ $footer }}</p>
            @endisset
        </main>
    </body>
</html>
