<x-filament-panels::page>
    <div class="bsi">
        <div class="bsi-sheet">
            <div class="bsi-toolbar">
                <div class="bsi-field">
                    <label class="bsi-label" for="ledger-account">Akun</label>
                    <select id="ledger-account" class="bsi-select" wire:model.live="accountId">
                        <option value="">Pilih akun</option>
                        @foreach ($this->accounts() as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        @if ($account = $this->selectedAccount())
            @php($rows = $this->ledgerRows($account))
            <div class="bsi-sheet">
                <div class="bsi-sheet__head"><h2 class="bsi-title">{{ $account->code }} — {{ $account->name }}</h2></div>
                <div class="bsi-scroll">
                    <table class="bsi-grid">
                        <thead><tr><th>Tanggal</th><th>Jurnal</th><th>Catatan</th><th>Debit</th><th>Kredit</th><th>Saldo berjalan</th></tr></thead>
                        <tbody>
                            @forelse ($rows as $line)
                                <tr>
                                    <td>{{ $line->entry_date }}</td>
                                    <td><a href="{{ \App\Filament\Resources\Journals\JournalResource::getUrl('view', ['record' => $line->entry_id]) }}">{{ $line->number }}</a></td>
                                    <td>{{ $line->memo ?? '-' }}</td>
                                    <td class="bsi-num">Rp {{ number_format($line->debit_minor, 0, ',', '.') }}</td>
                                    <td class="bsi-num">Rp {{ number_format($line->credit_minor, 0, ',', '.') }}</td>
                                    <td class="bsi-num">{{ (int) $line->running_minor < 0 ? '-' : '' }}Rp {{ number_format(abs((int) $line->running_minor), 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6">Belum ada jurnal untuk akun ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $rows->links() }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
