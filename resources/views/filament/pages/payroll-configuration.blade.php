<x-filament-panels::page>
    <div class="bsi-erp">
    <p>Tambah versi baru tiap ada perubahan. Cek sumber tarif BPJS, PTKP, TER, dan Pasal 17 dengan akuntan, lalu tandai verified sebelum payroll jalan ya. Semua nominal pakai rupiah bulat; tarif pakai basis poin (2% = 200).</p>
    <p>Riwayat di halaman ini menampilkan 100 versi terbaru per bagian; versi lama tetap tersimpan.</p>

    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Cek kalkulasi payroll</h2>
        <p>Preview ini tidak menyimpan atau memposting gaji.</p>
        <form wire:submit="previewPayroll" class="mt-3">
            {{ $this->previewForm }}
            <x-filament::button type="submit">Cek kalkulasi</x-filament::button>
        </form>
        @if ($previewError)
            <p role="alert" class="mt-3 text-red-600">{{ $previewError }}</p>
        @elseif ($previewResult)
            <p class="mt-3">Bruto Rp {{ number_format($previewResult['gross_pay_minor'], 0, ',', '.') }} · Potongan Rp {{ number_format($previewResult['gross_pay_minor'] - $previewResult['take_home_minor'], 0, ',', '.') }} · Dibawa pulang Rp {{ number_format($previewResult['take_home_minor'], 0, ',', '.') }}</p>
        @endif
    </section>

    @php
        $sections = [
            ['title' => 'BPJS', 'state' => 'bpjs', 'method' => 'saveBpjs', 'table' => 'payroll_settings',
                'fields' => [
                    'effective_from' => 'Berlaku mulai', 'jht_employer_bps' => 'JHT perusahaan (bps)', 'jht_employee_bps' => 'JHT karyawan (bps)',
                    'jp_employer_bps' => 'JP perusahaan (bps)', 'jp_employee_bps' => 'JP karyawan (bps)',
                    'jkk_employer_bps' => 'JKK perusahaan (bps)', 'jkk_employee_bps' => 'JKK karyawan (bps)',
                    'jkm_employer_bps' => 'JKM perusahaan (bps)', 'jkm_employee_bps' => 'JKM karyawan (bps)',
                    'health_employer_bps' => 'Kesehatan perusahaan (bps)', 'health_employee_bps' => 'Kesehatan karyawan (bps)',
                    'jp_wage_cap_minor' => 'Batas upah JP (Rp)', 'health_wage_cap_minor' => 'Batas upah Kesehatan (Rp)',
                    'source_note' => 'Sumber regulasi / catatan'],
            ],
            ['title' => 'PTKP', 'state' => 'ptkp', 'method' => 'savePtkp', 'table' => 'ptkp_rates',
                'fields' => ['status' => 'Status', 'ter_category' => 'Kategori TER', 'annual_amount_minor' => 'PTKP tahunan (Rp)',
                    'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber regulasi / catatan'],
                'selects' => ['status' => ['TK/0' => 'TK/0', 'TK/1' => 'TK/1', 'TK/2' => 'TK/2', 'TK/3' => 'TK/3',
                    'K/0' => 'K/0', 'K/1' => 'K/1', 'K/2' => 'K/2', 'K/3' => 'K/3'],
                    'ter_category' => ['A' => 'A', 'B' => 'B', 'C' => 'C']],
            ],
            ['title' => 'TER PPh 21', 'state' => 'ter', 'method' => 'saveTer', 'table' => 'pph21_ter_brackets',
                'fields' => ['category' => 'Kategori', 'lower_gross_minor' => 'Bruto mulai (Rp)', 'upper_gross_minor' => 'Bruto sampai (Rp, kosong = tanpa batas)',
                    'rate_bps' => 'Tarif (bps)', 'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber regulasi / catatan'],
                'selects' => ['category' => ['A' => 'A', 'B' => 'B', 'C' => 'C']],
            ],
            ['title' => 'Insentif Rider', 'state' => 'incentive', 'method' => 'saveIncentive', 'table' => 'rider_incentive_rules',
                'fields' => ['effective_from' => 'Berlaku mulai', 'amount_per_cup_minor' => 'Per cup (Rp)', 'target_cups' => 'Target cup',
                    'bonus_minor' => 'Bonus target (Rp)', 'source_note' => 'Sumber / catatan'],
            ],
            ['title' => 'Jenis Komponen Manual', 'state' => 'component', 'method' => 'saveComponent', 'table' => 'payroll_component_types',
                'fields' => ['code' => 'Kode', 'name' => 'Nama', 'kind' => 'Jenis', 'taxable' => 'Kena pajak', 'account_id' => 'Akun potongan',
                    'effective_from' => 'Berlaku mulai', 'source_note' => 'Sumber / catatan'],
                'selects' => ['kind' => ['EARNING' => 'Tunjangan / biaya', 'DEDUCTION' => 'Potongan']],
            ],
        ];
    @endphp

    @foreach ($sections as $section)
        <section class="rounded-xl border p-4">
            <h2 class="text-lg font-semibold">{{ $section['title'] }}</h2>
            <form wire:submit="{{ $section['method'] }}">
                {{ $this->{$section['state'].'Form'} }}
                <x-filament::button type="submit">Tambah versi</x-filament::button>
            </form>
            <div class="overflow-x-auto">
                <table class="mt-4 w-full text-sm">
                    <thead><tr>@foreach ($section['fields'] as $label)<th class="p-2 text-left">{{ $label }}</th>@endforeach @if (in_array($section['table'], ['payroll_settings', 'ptkp_rates'])) <th>Verifikasi</th> @endif</tr></thead>
                    <tbody>
                        @foreach ($this->rows($section['table']) as $row)
                            <tr class="border-t">
                                @foreach ($section['fields'] as $field => $label)
                                    <td class="p-2 {{ str_ends_with($field, '_minor') ? 'bsi-num' : '' }}">{{ $field === 'taxable' ? ($row->$field ? 'Ya' : 'Tidak') : (str_ends_with($field, '_minor') && $row->$field !== null ? 'Rp '.number_format((int) $row->$field, 0, ',', '.') : ($row->$field ?? '—')) }}</td>
                                @endforeach
                                @if (in_array($section['table'], ['payroll_settings', 'ptkp_rates']))
                                    <td class="p-2">{{ $row->verified_at ? 'Verified oleh #'.$row->verified_by.' pada '.$row->verified_at : 'Belum verified' }}
                                        @if (! $row->verified_at)<button type="button" wire:click="verifyVersion('{{ $section['table'] }}', {{ $row->id }})" class="text-primary-600">Verifikasi</button>@endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($section['state'] === 'ter')
                <p class="mt-3">TER baru dipakai kalkulator setelah satu versi kategori diterbitkan. Rentang harus dimulai dari 0, tanpa celah atau tumpang tindih, dan berakhir tanpa batas atas.</p>
                <form wire:submit="publishTer" class="mt-3">
                    {{ $this->terPublicationForm }}
                    <x-filament::button type="submit">Terbitkan versi TER</x-filament::button>
                </form>
                <table class="mt-3 w-full text-sm">
                    <thead><tr><th>Kategori</th><th>Berlaku mulai</th><th>Status</th><th>Verifikasi</th></tr></thead>
                    <tbody>
                        @foreach ($this->terVersions() as $version)
                            <tr class="border-t"><td>{{ $version->category }}</td><td>{{ $version->effective_from }}</td>
                                <td>{{ $version->published_at ? 'Terbit' : 'Draf' }}</td>
                                <td>{{ $version->verified_at ? 'Verified oleh #'.$version->verified_by.' pada '.$version->verified_at : 'Belum verified' }}
                                    @if ($version->published_at && ! $version->verified_at)<button type="button" wire:click="verifyVersion('payroll_ter_versions', {{ $version->id }})" class="text-primary-600">Verifikasi</button>@endif
                                </td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach

    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Tarif Pasal 17 Tahunan</h2>
        {{ $this->verificationForm }}
        @error('verificationNote') <span class="text-red-600">{{ $message }}</span> @enderror
        <form wire:submit="saveAnnualVersion">
            {{ $this->annualVersionForm }}
            <x-filament::button type="submit">Tambah versi</x-filament::button>
        </form>
        <form wire:submit="saveAnnualBracket">
            {{ $this->annualBracketForm }}
            <x-filament::button type="submit">Tambah rentang</x-filament::button>
        </form>
        @foreach ($this->rows('payroll_annual_tax_versions') as $version)
            <p>{{ $version->effective_from }} · {{ $version->source_note }} · {{ $version->verified_at ? 'Verified oleh #'.$version->verified_by.' pada '.$version->verified_at : 'Belum verified' }}
                @if (! $version->verified_at)<button type="button" wire:click="verifyVersion('payroll_annual_tax_versions', {{ $version->id }})">Verifikasi</button>@endif
            </p>
            <table class="w-full text-sm"><thead><tr><th>Dasar mulai</th><th>Dasar sampai</th><th>Tarif (bps)</th></tr></thead>
                <tbody>@foreach ($this->annualBrackets($version->id) as $bracket)
                    <tr><td class="bsi-num">Rp {{ number_format($bracket->lower_taxable_minor, 0, ',', '.') }}</td>
                        <td class="bsi-num">{{ $bracket->upper_taxable_minor === null ? 'Tanpa batas' : 'Rp '.number_format($bracket->upper_taxable_minor, 0, ',', '.') }}</td>
                        <td>{{ $bracket->rate_bps }}</td></tr>
                @endforeach</tbody>
            </table>
        @endforeach
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Komponen Karyawan per Periode</h2>
        <form wire:submit="saveEntry">
            {{ $this->entryForm }}
            <x-filament::button type="submit">Tambah komponen</x-filament::button>
        </form>
        <table class="mt-4 w-full text-sm">
            <thead><tr><th>Karyawan</th><th>Periode</th><th>Komponen</th><th>Nominal</th><th>Ubah saat DRAFT</th></tr></thead>
            <tbody>
                @foreach ($this->entryRows() as $row)
                    <tr class="border-t"><td>{{ $row->user?->name }}</td><td>{{ $row->period_start->format('Y-m') }}</td>
                        <td>{{ $row->type?->name }}</td><td class="bsi-num">Rp {{ number_format($row->amount_minor, 0, ',', '.') }}</td>
                        <td><input type="number" min="1" wire:model="entryEdits.{{ $row->id }}.amount_minor" placeholder="Nominal baru" class="w-36 rounded border p-1">
                            <input type="text" wire:model="entryEdits.{{ $row->id }}.note" placeholder="Catatan" class="w-32 rounded border p-1">
                            <button type="button" wire:click="saveEditedEntry({{ $row->id }})">Simpan</button>
                        </td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
    </div>
</x-filament-panels::page>
