{{--
    Members-only sign-in prompt. Rendered open by the server only when the
    viewer asked for member-only products they are not eligible to see (the
    controller never sent any of that product data). Without JavaScript it is
    simply visible; with it, it becomes a modal dialog. The sign-in link goes
    to the normal login page, which returns to the e-shop afterwards (the
    destination was stored server-side as the intended URL).
--}}
@php($signedIn = auth()->check())
<dialog id="members-only-dialog" open data-members-only-dialog aria-labelledby="members-only-title" aria-describedby="members-only-text"
    class="fixed inset-0 z-50 m-auto w-[min(28rem,calc(100%-2rem))] rounded-xl border border-border bg-background p-6 text-foreground shadow-elegant backdrop:bg-black/50">
    <div class="flex items-start gap-3">
        <span class="grid h-10 w-10 flex-shrink-0 place-items-center rounded-lg bg-[#CC001F]/10 text-[#CC001F]">
            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </span>
        <div>
            <h2 id="members-only-title" class="font-display text-lg font-bold text-primary">Members-only products</h2>
            <p id="members-only-text" class="mt-1 text-sm text-muted-foreground">
                @if ($signedIn)
                    Members-only products can be viewed and purchased only with an eligible Aviation Club membership — an active membership that is currently valid. Your account does not have one at the moment.
                @else
                    Members-only products are for Aviation Club members. To view and purchase them, please sign in with an account that has an eligible (current) membership — you will come straight back to the shop.
                @endif
            </p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap justify-end gap-3">
        <form method="dialog">
            <button type="submit" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">{{ $signedIn ? 'Close' : 'Not now' }}</button>
        </form>
        @if ($signedIn)
            <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Membership</a>
        @else
            <a href="{{ route('login') }}" class="btn btn-lg btn-brand" data-members-sign-in>Sign in</a>
        @endif
    </div>
</dialog>

<script>
    (function () {
        var dialog = document.querySelector('[data-members-only-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') { return; }
        // Re-open as a true modal (focus trapped, Esc closes, page inert).
        dialog.close();
        dialog.showModal();
    })();
</script>
