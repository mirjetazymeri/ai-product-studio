@php($isEditing = $store->exists)

<x-layouts.app :title="$isEditing ? 'Edit Store' : 'Connect WooCommerce Store'">
    <x-page-header :title="$isEditing ? 'Edit '.$store->name : 'Connect WooCommerce Store'" description="Create a key in WooCommerce under Settings, Advanced, REST API." />

    <form method="POST" action="{{ $isEditing ? route('integrations.update', $store) : route('integrations.store') }}" data-single-submit autocomplete="off" class="card max-w-2xl space-y-4 p-5 sm:p-6">
        @csrf
        @if ($isEditing)
            @method('PUT')
        @endif

        <x-form.input name="name" label="Store Name" :value="old('name', $store->name)" required maxlength="120" />
        <x-form.input name="url" label="Store URL" type="url" :value="old('url', $store->url)" required maxlength="255" placeholder="https://shop.example.com" hint="Must use HTTPS and be publicly reachable." />
        <x-form.input name="consumer_key" label="Consumer Key" type="password" :required="! $isEditing" maxlength="255" autocomplete="new-password" :hint="$isEditing ? 'Leave blank to keep the saved key.' : null" />
        <x-form.input name="consumer_secret" label="Consumer Secret" type="password" :required="! $isEditing" maxlength="255" autocomplete="new-password" :hint="$isEditing ? 'Leave blank to keep the saved secret.' : 'Stored encrypted and never shown again.'" />

        <p class="text-sm text-gray-500">Saving tests the connection by reading from the store. A read-only key is enough to connect and import, and a key with write access is needed to sync content.</p>

        <div class="flex justify-end gap-3">
            <a href="{{ route('integrations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $isEditing ? 'Save and test' : 'Connect and test' }}</button>
        </div>
    </form>
</x-layouts.app>
