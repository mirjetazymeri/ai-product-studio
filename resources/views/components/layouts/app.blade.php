@props(['title' => null])

<!DOCTYPE html>
<html lang="en">
    <x-head :title="$title" />
    <body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased">
        <div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-gray-900/40 lg:hidden"></div>

        <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-gray-200 bg-white transition-transform lg:translate-x-0">
            <div class="flex h-16 items-center justify-between px-5">
                <x-brand href="{{ route('dashboard') }}" />
                <button type="button" data-sidebar-close class="rounded-md p-1 text-gray-500 hover:bg-gray-100 lg:hidden" aria-label="Close navigation">
                    <x-icon name="close" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3" aria-label="Main">
                <x-nav-link :href="route('dashboard')" icon="dashboard" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
                <x-nav-link :href="route('products.index')" icon="cube" :active="request()->routeIs('products.*')">Products</x-nav-link>
                <x-nav-link :href="route('ai-studio')" icon="sparkles" :active="request()->routeIs('ai-studio')">AI Studio</x-nav-link>
                <x-nav-link :href="route('generation-history')" icon="clock" :active="request()->routeIs('generation-history')">Generation History</x-nav-link>
                <x-nav-link :href="route('ai.reviews.index')" icon="check" :active="request()->routeIs('ai.reviews.*')">Content Review</x-nav-link>
                <x-nav-link :href="route('catalog.history')" icon="document" :active="request()->routeIs('catalog.*')">Import &amp; Export</x-nav-link>
                <x-nav-link :href="route('integrations.index')" icon="cube" :active="request()->routeIs('integrations.*')">Integrations</x-nav-link>
                <x-nav-link :href="route('settings.edit')" icon="settings" :active="request()->routeIs('settings.*')">Settings</x-nav-link>
            </nav>

            <div class="flex items-center gap-3 border-t border-gray-200 p-4">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-gray-100 text-sm font-semibold text-gray-600" aria-hidden="true">
                    {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-gray-500">{{ auth()->user()->email }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700" aria-label="Log out" title="Log out">
                        <x-icon name="logout" />
                    </button>
                </form>
            </div>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-gray-200 bg-white px-4 lg:hidden">
                <button type="button" data-sidebar-open aria-expanded="false" aria-controls="sidebar" class="-ml-1 rounded-md p-1 text-gray-600 hover:bg-gray-100" aria-label="Open navigation">
                    <x-icon name="menu" class="size-6" />
                </button>
                <x-brand href="{{ route('dashboard') }}" />
            </header>

            <main class="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-10">
                <x-flash />
                {{ $slot }}
            </main>
        </div>

        <dialog id="confirm-dialog" class="m-auto w-[calc(100%-2rem)] max-w-sm rounded-xl bg-white p-6 shadow-xl">
            <h2 class="text-base font-semibold text-gray-900">Are you sure?</h2>
            <p data-confirm-message class="mt-1.5 text-sm text-gray-500"></p>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" data-confirm-cancel class="btn btn-secondary">Cancel</button>
                <button type="button" data-confirm-accept class="btn btn-danger">Delete</button>
            </div>
        </dialog>
    </body>
</html>
