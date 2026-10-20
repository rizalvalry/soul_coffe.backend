<x-filament-panels::page>
    <div class="bsi-erp space-y-5">
        <p>Tarif pajak diisi Management setelah dicek dengan akuntan. Kalau belum diaktifkan, jurnal penjualan tetap seperti biasa.</p>
        <div class="grid gap-4 md:grid-cols-2">
            @foreach (['PBJT' => 'Pajak Restoran (PBJT)', 'PPH' => 'Pajak Penghasilan Badan'] as $type => $title)
                @php $active = app(\App\Services\TaxService::class)->settingAt($type, today()->toDateString()); @endphp
                <section class="rounded-xl border p-4">
                    <h2 class="text-lg font-semibold">{{ $title }}</h2>
                    <p class="mt-2"><strong>{{ $active?->is_active ? 'Aktif' : 'Nonaktif' }}</strong>
                        @if ($active) · Tarif {{ rtrim(rtrim(sprintf('%d.%02d', intdiv($active->rate_bps, 100), $active->rate_bps % 100), '0'), '.') }}% · Berlaku sejak {{ $active->effective_from->format('d M Y') }} @endif
                    </p>
                    @if ($type === 'PBJT')
                        <p class="mt-2">Harga sudah termasuk pajak: dari Rp 11.000 dengan tarif 10%, Rp 10.000 jadi penjualan dan Rp 1.000 jadi utang PBJT.</p>
                        <x-filament::button type="button" class="mt-3" wire:click="openPbjtForm">Ubah tarif</x-filament::button>
                        @if ($showPbjtForm)<form wire:submit="savePbjt" class="mt-4">{{ $this->pbjtForm }}<x-filament::button type="submit" class="mt-3">Simpan versi baru</x-filament::button></form>@endif
                    @else
                        <p class="mt-2">{{ $active?->scheme === 'GENERAL' ? 'Tarif umum: angka di laporan cuma perkiraan, koreksi fiskal dicatat akuntan lewat Jurnal Manual.' : 'Skema Final atas omzet penjualan setelah PBJT.' }}</p>
                        <x-filament::button type="button" class="mt-3" wire:click="openPphForm">Ubah tarif</x-filament::button>
                        @if ($showPphForm)<form wire:submit="savePph" class="mt-4">{{ $this->pphForm }}<x-filament::button type="submit" class="mt-3">Simpan versi baru</x-filament::button></form>@endif
                    @endif
                    <h3 class="mt-5 font-semibold">Riwayat tarif</h3>
                    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Mulai</th><th>Sampai</th><th>Status</th><th>Tarif</th><th>Oleh / alasan</th></tr></thead><tbody>
                        @forelse ($this->versions($type) as $version)
                            <tr><td>{{ $version->effective_from->format('d M Y') }}</td><td>{{ $version->effective_until?->format('d M Y') ?? '—' }}</td><td>{{ $version->is_active ? 'Aktif' : 'Nonaktif' }}</td><td>{{ rtrim(rtrim(sprintf('%d.%02d', intdiv($version->rate_bps, 100), $version->rate_bps % 100), '0'), '.') }}%</td><td>{{ $version->creator?->name }} · {{ $version->reason }}</td></tr>
                        @empty <tr><td colspan="5">Belum ada versi tarif.</td></tr> @endforelse
                    </tbody></table></div>
                </section>
            @endforeach
        </div>

        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">Laporan pajak bulanan</h2>
            <label for="tax-month">Bulan</label><input id="tax-month" type="month" wire:model.live="month" class="rounded border p-2">
            <div class="grid gap-4 md:grid-cols-2 mt-3">
                @foreach (['PBJT' => 'PBJT', 'PPH' => 'PPh Badan'] as $type => $title)
                    @php $report = $this->report($type); @endphp
                    <div class="rounded border p-3"><h3 class="font-semibold">{{ $title }}</h3>
                        <p>Omzet bruto: Rp {{ number_format($report['gross_minor'], 0, ',', '.') }}</p>
                        <p>Dasar pengenaan: Rp {{ number_format($report['base_minor'], 0, ',', '.') }}</p>
                        <p>Utang dari jurnal: Rp {{ number_format($report['due_minor'], 0, ',', '.') }}</p>
                        <p>Setoran bulan ini: Rp {{ number_format($report['paid_minor'], 0, ',', '.') }}</p>
                        @if ($type === 'PBJT' && $report['threshold_minor'] !== null)
                            <p>Ambang omzet: Rp {{ number_format($report['threshold_minor'], 0, ',', '.') }} · {{ $report['gross_minor'] > $report['threshold_minor'] ? 'Di atas ambang' : 'Belum lewat ambang' }} (informasi saja)</p>
                        @endif
                        @if ($type === 'PPH')
                            <p>Perkiraan PPh Final: Rp {{ number_format($report['final_estimate_minor'], 0, ',', '.') }}</p>
                            <p>Perkiraan PPh tarif umum: Rp {{ number_format($report['general_estimate_minor'], 0, ',', '.') }}</p>
                            @if ($report['general_estimate_minor'] > 0)<p>Tarif umum cuma perkiraan dari laba bersih. Koreksi fiskal dan jurnal akhir tahun lewat Jurnal Manual, ya.</p>@endif
                            @if ($report['final_estimate_minor'] !== $report['final_posted_minor'])
                                <x-filament::button type="button" wire:click="recordFinalPph" class="mt-2">{{ $report['final_estimate_minor'] < 0 ? 'Catat koreksi PPh Final' : ($report['final_posted_minor'] === 0 ? 'Catat utang PPh final bulan ini' : 'Sesuaikan utang PPh final bulan ini') }}</x-filament::button>
                            @endif
                        @endif
                        <div class="mt-2 flex gap-2"><x-filament::button type="button" wire:click="exportExcel('{{ $type }}')" color="gray">Excel</x-filament::button><x-filament::button type="button" wire:click="exportPdf('{{ $type }}')" color="gray">PDF</x-filament::button></div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border p-4">
            <form wire:submit="saveRemittance">{{ $this->remittanceForm }}<x-filament::button type="submit" class="mt-3">Catat setoran pajak</x-filament::button></form>
            <h3 class="mt-5 font-semibold">Riwayat setor</h3>
            <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Tanggal</th><th>Pajak</th><th>Nominal</th><th>Bukti</th><th>Akun</th><th>Status</th></tr></thead><tbody>
                @forelse ($this->remittances() as $remittance)
                    <tr><td>{{ $remittance->paid_on->format('d M Y') }}</td><td>{{ $remittance->tax_type }}</td><td>Rp {{ number_format($remittance->amount_minor, 0, ',', '.') }}</td><td>{{ $remittance->receipt_number }}</td><td>{{ $remittance->cashAccount?->name }}</td><td>{{ $remittance->status === 'VOID' ? 'Dibatalkan' : 'Tercatat' }}</td></tr>
                @empty <tr><td colspan="6">Belum ada setoran pajak.</td></tr> @endforelse
            </tbody></table></div>
            <div class="mt-4"><label for="void-remittance">Batalkan setoran</label>
                <select id="void-remittance" wire:model="voidRemittanceId" class="rounded border p-2"><option value="">Pilih setoran</option>@foreach ($this->remittances()->where('status', 'POSTED') as $remittance)<option value="{{ $remittance->id }}">{{ $remittance->receipt_number }} · Rp {{ number_format($remittance->amount_minor, 0, ',', '.') }}</option>@endforeach</select>
                <input type="text" wire:model="voidReason" placeholder="Alasan pembatalan" class="rounded border p-2">
                <x-filament::button type="button" color="danger" wire:click="voidRemittance">Batalkan setoran</x-filament::button>
            </div>
        </section>
    </div>
</x-filament-panels::page>
