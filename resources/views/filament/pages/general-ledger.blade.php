<x-filament-panels::page>
    <div class="bsi">
        {{ $this->accountForm }}
        @if ($account = $this->selectedAccount())
            <div class="bsi-sheet">
                <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $account->code }} — {{ $account->name }}</h2></div>
                <div class="bsi-scroll">{{ $this->table }}</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
