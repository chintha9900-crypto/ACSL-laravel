{{--
    Members-only prompt. Rendered open by the server only when the viewer
    asked for member-only products they are not eligible to see (the
    controller never sent any of that product data). Without JavaScript it is
    simply visible; with it, it becomes a modal dialog (Esc, Cancel or a click
    outside closes it).

    Guests get a login form that posts to the existing login route
    (AuthenticatedSessionController + LoginRequest, throttled). After a
    successful login they return to the stored intended URL (the e-shop),
    where the normal membership-eligibility rules decide what they see. A
    failed login comes back to the same e-shop URL, so the dialog reopens with
    the error. Signed-in visitors who are not eligible keep the existing
    access-denied message — never a login form.
--}}
@php($signedIn = auth()->check())
@if ($signedIn)
    <dialog id="members-only-dialog" open data-members-only-dialog aria-labelledby="members-only-title" aria-describedby="members-only-text"
        class="fixed inset-0 z-50 m-auto w-[min(28rem,calc(100%-2rem))] rounded-xl border border-border bg-background p-6 text-foreground shadow-elegant backdrop:bg-black/50">
        <div class="flex items-start gap-3">
            <span class="grid h-10 w-10 flex-shrink-0 place-items-center rounded-lg bg-[#CC001F]/10 text-[#CC001F]">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <div>
                <h2 id="members-only-title" class="font-display text-lg font-bold text-primary">Members-only products</h2>
                <p id="members-only-text" class="mt-1 text-sm text-muted-foreground">
                    Members-only products can be viewed and purchased only with an eligible Aviation Club membership — an active membership that is currently valid. Your account does not have one at the moment.
                </p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <form method="dialog">
                <button type="submit" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Close</button>
            </form>
            <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Membership</a>
        </div>
    </dialog>
@else
    <dialog id="members-only-dialog" open data-members-only-dialog aria-labelledby="members-only-title"
        class="fixed inset-0 z-50 m-auto w-[min(26rem,calc(100%-2rem))] rounded-xl border border-border bg-background p-6 text-foreground shadow-elegant backdrop:bg-black/50">
        <h2 id="members-only-title" class="font-display text-lg font-bold text-primary">Please log in to your account</h2>

        <form method="POST" action="{{ route('login') }}" class="mt-5 space-y-4" data-members-login-form>
            @csrf
            <x-form.input name="email" label="Email" type="email" :value="old('email')" autocomplete="username" autofocus />
            <x-form.input name="password" label="Password" type="password" autocomplete="current-password" />

            <a href="{{ route('password.request') }}" class="inline-block text-sm font-semibold text-[#CC001F] hover:underline">Forgot password?</a>

            <div class="flex flex-col gap-3 pt-1">
                <button type="submit" class="btn btn-lg btn-brand w-full">Login</button>
                <button type="submit" form="members-only-cancel" class="btn btn-lg w-full border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground" data-members-login-cancel>Cancel</button>
            </div>
        </form>
        {{-- Closes the dialog in place (no navigation, no sign-in). --}}
        <form method="dialog" id="members-only-cancel"></form>
    </dialog>
@endif

<script>
    (function () {
        var dialog = document.querySelector('[data-members-only-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') { return; }
        // Re-open as a true modal (focus moves inside and is trapped, Esc closes, page inert).
        dialog.close();
        dialog.showModal();
        // A click on the backdrop (outside the dialog box) closes it, like Cancel.
        dialog.addEventListener('click', function (event) {
            if (event.target !== dialog) { return; }
            var box = dialog.getBoundingClientRect();
            var inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
            if (!inside) { dialog.close(); }
        });
    })();
</script>
