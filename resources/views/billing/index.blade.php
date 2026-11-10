@php
    use App\Enums\BillingMode;
    use App\Enums\SubscriptionStatus;

    $state = $overview['state'];
    $mode = $overview['mode'];
    $isDemo = $mode === BillingMode::Demo;
@endphp

<x-layouts.app title="Billing & Subscription">
    <x-page-header title="Billing & Subscription" description="Your plan, this month's usage and billing history.">
        <x-slot:actions>
            <x-billing-mode :mode="$mode" />
            <a href="{{ route('pricing') }}" class="btn btn-primary">Compare plans</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('plan_notice'))
        <p role="alert" class="rounded-lg bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 ring-1 ring-amber-200">{{ session('plan_notice') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">{{ $errors->first() }}</div>
    @endif

    @if ($isDemo)
        <p role="note" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200"><span class="font-semibold">Simulation.</span> Demo Billing takes no payments and uses no Stripe account. Plans here only change what this installation allows.</p>
    @elseif ($unavailableReason)
        <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ $unavailableReason }}</p>
    @endif

    @if ($state->status === SubscriptionStatus::PastDue)
        <p role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">
            A payment failed.
            @if ($state->graceEndsAt?->isFuture())
                Your plan stays usable until {{ $state->graceEndsAt->format('M j, Y') }}.
            @else
                The grace period has ended, so your account now has {{ $state->plan->name }} limits.
            @endif
        </p>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-3">
        <section class="card p-5 sm:p-6">
            <h2 class="text-sm font-semibold">Current plan</h2>
            <p class="mt-3 text-2xl font-semibold tracking-tight">{{ $state->plan->name }}</p>

            <dl class="mt-4 space-y-2.5 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Status</dt><dd>{{ $state->status->label() }}</dd></div>
                @if ($state->subscribedPlan && $state->subscribedPlan->key !== $state->plan->key)
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Previous plan</dt><dd>{{ $state->subscribedPlan->name }}</dd></div>
                @endif
                @if ($state->renewsAt)
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $isDemo ? 'Simulated renewal' : 'Renews' }}</dt><dd>{{ $state->renewsAt->format('M j, Y') }}</dd></div>
                @endif
                @if ($state->endsAt)
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $state->status === SubscriptionStatus::Canceling ? 'Ends' : 'Ended' }}</dt><dd>{{ $state->endsAt->format('M j, Y') }}</dd></div>
                @endif
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Usage period</dt><dd>{{ $overview['period']['key'] }} (UTC)</dd></div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-gray-100 pt-4">
                @if ($state->canResume())
                    <form method="POST" action="{{ route('billing.resume') }}" data-single-submit>
                        @csrf
                        <button type="submit" class="btn btn-primary">Resume subscription</button>
                    </form>
                @endif
                @if ($hasPortal)
                    <a href="{{ route('billing.portal') }}" class="btn btn-secondary">Manage payment method</a>
                @endif
                @if ($state->canCancel())
                    <details class="w-full">
                        <summary class="btn btn-danger-soft">Cancel subscription</summary>
                        <form method="POST" action="{{ route('billing.cancel') }}" data-single-submit class="mt-3 space-y-3 rounded-lg bg-red-50 p-3">
                            @csrf
                            <p class="text-sm text-red-800">Your plan stays usable until the end of the period. Products, content and history are kept.</p>
                            <label class="flex items-start gap-2 text-sm text-red-800">
                                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-red-600">
                                {{ $isDemo ? 'Simulate cancellation' : 'Cancel my subscription' }}
                            </label>
                            <button type="submit" class="btn btn-danger">Confirm cancellation</button>
                        </form>
                    </details>
                @endif
            </div>
        </section>

        <section class="card p-5 sm:p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold">Monthly usage</h2>
            <p class="mt-0.5 text-xs text-gray-500">Resets {{ $overview['period']['resets_at']->format('M j, Y') }} at 00:00 UTC. Failed requests and Sample AI Output don't count.</p>

            <div class="mt-4 space-y-5">
                <x-usage-meter label="AI generations" :used="$overview['ai']['used']" :limit="$overview['ai']['limit']" :percent="$overview['ai']['percent']" :hint="number_format($overview['ai']['remaining']).' remaining this month'" />
                <x-usage-meter label="Products" :used="$overview['products']['used']" :limit="$overview['products']['limit']" :percent="$overview['products']['percent']" :hint="$overview['products']['used'] > $overview['products']['limit'] ? 'Over this plan\'s limit. Existing products stay available, but new ones can\'t be added.' : number_format($overview['products']['remaining']).' remaining'" />
            </div>

            <h3 class="mt-6 text-sm font-semibold">Included features</h3>
            <ul class="mt-2 grid gap-1.5 text-sm sm:grid-cols-2">
                @foreach ($overview['features'] as $feature)
                    <li class="flex items-center gap-2">
                        <x-icon name="check" @class(['size-4', 'text-emerald-600' => $feature['available'], 'text-gray-300' => ! $feature['available']]) />
                        {{ $feature['label'] }}
                        @unless ($feature['available'])
                            <span class="text-xs text-amber-700">Coming Soon</span>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    <section class="card">
        <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">Change plan</h2>
        <ul class="divide-y divide-gray-100">
            @foreach ($plans as $plan)
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $plan->name }} <span class="font-normal text-gray-500">· {{ $plan->displayPrice() }} / month</span></p>
                        <p class="text-xs text-gray-500">{{ number_format($plan->limit('ai_generations')) }} AI generations, {{ number_format($plan->limit('products')) }} products</p>
                    </div>
                    @if ($plan->key === $state->plan->key)
                        <span class="rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Current plan</span>
                    @elseif (! $plan->isPaid() && ! $state->canCancel())
                        <span class="text-xs text-gray-500">Not applicable</span>
                    @else
                        <a href="{{ route('billing.plans.confirm', $plan->key) }}" class="btn btn-secondary">{{ $plan->rank > $state->plan->rank ? 'Upgrade' : 'Downgrade' }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    @if ($demoEnabled)
        <section class="card p-5 sm:p-6">
            <h2 class="text-sm font-semibold">Simulation controls</h2>
            <p class="mt-1 text-sm text-gray-500">Try each billing situation. Everything here is simulated.</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('billing.demo.payment-failure') }}" data-single-submit>@csrf<button type="submit" class="btn btn-secondary">Simulate payment failure</button></form>
                @if ($state->status === SubscriptionStatus::PastDue)
                    <form method="POST" action="{{ route('billing.demo.payment-recovery') }}" data-single-submit>@csrf<button type="submit" class="btn btn-secondary">Simulate successful retry</button></form>
                @endif
                <form method="POST" action="{{ route('billing.demo.expire') }}" data-single-submit>@csrf<button type="submit" class="btn btn-secondary">Simulate expiration</button></form>
                <form method="POST" action="{{ route('billing.demo.use-allowance') }}" data-single-submit>@csrf<button type="submit" class="btn btn-secondary">Use up AI allowance</button></form>
                <form method="POST" action="{{ route('billing.demo.restore-free') }}" data-single-submit>@csrf<button type="submit" class="btn btn-secondary">Restore Free plan</button></form>
            </div>

            <details class="mt-3">
                <summary class="btn btn-danger-soft">Reset demo billing</summary>
                <form method="POST" action="{{ route('billing.demo.reset') }}" data-single-submit class="mt-3 max-w-md space-y-3 rounded-lg bg-red-50 p-3">
                    @csrf
                    <p class="text-sm text-red-800">Removes simulated subscriptions, simulated history and simulated usage. Real AI usage, products and content aren't changed.</p>
                    <label class="flex items-start gap-2 text-sm text-red-800">
                        <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 rounded accent-red-600">
                        Reset demo billing
                    </label>
                    <button type="submit" class="btn btn-danger">Confirm reset</button>
                </form>
            </details>
        </section>
    @endif

    <section class="card">
        <h2 class="border-b border-gray-200 px-5 py-4 text-sm font-semibold">{{ $isDemo ? 'Simulated billing history' : 'Invoice history' }}</h2>
        @if ($records === [])
            <x-empty-state icon="document" title="No billing history" description="Billing activity will be listed here." />
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($records as $record)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium break-words">{{ $record->description }}</p>
                            <p class="mt-0.5 text-xs break-all text-gray-500">{{ $record->date->format('M j, Y, H:i') }} UTC · {{ $record->reference }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            @if ($record->simulated)
                                <span class="rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200 ring-inset">Simulation</span>
                            @endif
                            <span class="text-sm text-gray-500 tabular-nums">{{ $record->displayAmount() }}</span>
                            <a href="{{ route('billing.records.show', $record->id) }}" class="btn btn-secondary">View</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
