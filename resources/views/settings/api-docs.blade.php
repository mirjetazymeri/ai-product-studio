<x-layouts.app :title="$title">
    <a href="{{ route('settings.edit') }}#api-access" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-gray-900">
        <x-icon name="arrow-left" class="size-4" /> Settings
    </a>

    <article class="card max-w-4xl p-5 text-sm leading-relaxed break-words sm:p-6 [&_a]:font-medium [&_a]:text-indigo-600 [&_code]:font-mono [&_code]:text-xs [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:mt-6 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:font-semibold [&_li]:mt-1 [&_ol]:mt-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mt-2 [&_pre]:mt-2 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-gray-100 [&_pre]:p-3 [&_table]:mt-2 [&_table]:block [&_table]:overflow-x-auto [&_td]:border-t [&_td]:border-gray-200 [&_td]:py-1.5 [&_td]:pr-4 [&_td]:align-top [&_th]:py-1.5 [&_th]:pr-4 [&_th]:text-left [&_ul]:mt-2 [&_ul]:list-disc [&_ul]:pl-5">
        {!! $html !!}
    </article>
</x-layouts.app>
