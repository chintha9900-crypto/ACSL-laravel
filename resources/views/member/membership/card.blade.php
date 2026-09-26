@php($hasLogo = file_exists(public_path('images/aci-logo.png')))

<x-layouts.member :title="'Digital membership card'">
    <style>
        @media print {
            header, nav, #card-actions { display: none !important; }
            main { padding: 0 !important; }
        }
    </style>

    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Your digital membership card</h1>

    @unless ($isCurrentlyValid)
        <p class="mt-3 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive" role="alert">
            This card is not currently valid — your membership has no active term. Renew to restore it.
        </p>
    @endunless

    <div class="mx-auto mt-6 max-w-md rounded-2xl bg-gradient-to-br from-primary to-primary/80 p-6 text-primary-foreground shadow-card">
        <div class="flex items-center justify-between">
            @if ($hasLogo)
                <img src="{{ asset('images/aci-logo.png') }}" alt="Aviation Club International" class="h-8 w-auto">
            @else
                <span class="font-display text-base font-bold">Aviation Club International</span>
            @endif

            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $isCurrentlyValid ? 'bg-secondary/20 text-secondary' : 'bg-white/10 text-primary-foreground/70' }}">
                {{ $isCurrentlyValid ? 'Active' : 'Not valid' }}
            </span>
        </div>

        <p class="mt-6 text-lg font-semibold">{{ $user->name }}</p>
        <p class="mt-1 font-mono text-sm tracking-wide text-primary-foreground/80">{{ $membership->membership_number }}</p>

        <div class="mt-4 grid grid-cols-2 gap-4 text-xs text-primary-foreground/80">
            <div>
                <p class="uppercase tracking-wide">Joined</p>
                <p class="mt-1 text-sm font-medium text-primary-foreground">{{ $membership->activated_on->format('j M Y') }}</p>
            </div>
            <div>
                <p class="uppercase tracking-wide">Valid until</p>
                <p class="mt-1 text-sm font-medium text-primary-foreground">{{ $validUntil?->format('j M Y') ?? '—' }}</p>
            </div>
        </div>

        <div class="mt-6 flex justify-center rounded-lg bg-white p-3">
            <div id="qr-code" data-url="{{ $verificationUrl }}" aria-label="Membership verification QR code"></div>
        </div>
    </div>

    <div id="card-actions" class="mx-auto mt-6 max-w-md text-center">
        <button type="button" onclick="window.print()" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">
            Print / Save as PDF
        </button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('qr-code');
            if (el && window.QRCode) {
                new QRCode(el, { text: el.dataset.url, width: 160, height: 160 });
            }
        });
    </script>
</x-layouts.member>
