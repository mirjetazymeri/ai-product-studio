<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($plans as $plan)
        @php($isCurrent = $currentPlan?->key === $plan->key)
        <section @class(['card flex flex-col p-5', 'ring-2 ring-indigo-600' => $isCurrent])>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold">{{ $plan->name }}</h2>
                @if ($isCurrent)
                    <span class="rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Current plan</span>
                @endif
            </div>
            <p class="mt-1 text-sm text-gray-500">{{ $plan->description }}</p>
            <p class="mt-4"><span class="text-3xl font-semibold tracking-tight">{{ $plan->displayPrice() }}</span> <span class="text-sm text-gray-500">/ month</span></p>

            <dl class="mt-4 flex-1 space-y-2 border-t border-gray-100 pt-4 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-gray-500">AI generations</dt><dd class="text-right font-medium tabular-nums">{{ number_format($plan->limit('ai_generations')) }} / month</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500">Products</dt><dd class="text-right font-medium tabular-nums">{{ number_format($plan->limit('products')) }}</dd></div>
                @foreach ($rows as $key => $label)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd @class(['text-right', 'text-gray-400' => $plan->comparison[$key] === 'No', 'text-amber-700' => $plan->comparison[$key] === 'Coming Soon'])>{{ $plan->comparison[$key] }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5">
                @guest
                    <a href="{{ route('register') }}" class="btn btn-secondary w-full">Get started</a>
                @else
                    @if ($isCurrent)
                        <a href="{{ route('billing.index') }}" class="btn btn-secondary w-full">Manage plan</a>
                    @else
                        <a href="{{ route('billing.plans.confirm', $plan->key) }}" @class(['btn w-full', 'btn-primary' => $plan->rank > $currentPlan->rank, 'btn-secondary' => $plan->rank <= $currentPlan->rank])>{{ $plan->rank > $currentPlan->rank ? 'Upgrade' : 'Downgrade' }} to {{ $plan->name }}</a>
                    @endif
                @endguest
            </div>
        </section>
    @endforeach
</div>

<p class="text-sm text-gray-500">
    @if ($mode === App\Enums\BillingMode::StripeLive)
        Prices are charged monthly in euros through Stripe.
    @else
        These prices are illustrative until real Stripe pricing is configured. <x-billing-mode :mode="$mode" />
    @endif
</p>
