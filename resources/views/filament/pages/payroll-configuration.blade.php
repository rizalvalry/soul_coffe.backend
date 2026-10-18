<x-filament-panels::page>
    <p>Tambah versi baru tiap ada perubahan. Cek sumber tarif BPJS, PTKP, TER, dan Pasal 17 dengan akuntan, lalu tandai verified sebelum payroll jalan ya. Semua nominal pakai rupiah bulat; tarif pakai basis poin (2% = 200).</p>
    <p>Riwayat di halaman ini menampilkan 100 versi terbaru per bagian; versi lama tetap tersimpan.</p>

    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Cek kalkulasi payroll</h2>
        <p>Preview ini tidak menyimpan atau memposting gaji.</p>
        <form wire:submit="previewPayroll" class="mt-3 flex flex-wrap gap-3">
            <label>Karyawan
                <select wire:model="preview.user_id" class="rounded border p-2">
                    <option value="">Pilih karyawan</option>
                    @foreach ($this->employeeOptions() as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                    @endforeach
                </select>
                @error('preview.user_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label>Periode
                <input type="month" wire:model="preview.period" class="rounded border p-2">
                @error('preview.period') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Cek kalkulasi</button>
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
            <form wire:submit="{{ $section['method'] }}" class="grid gap-3 md:grid-cols-3">
                @foreach ($section['fields'] as $field => $label)
                    <label class="grid gap-1">
                        <span>{{ $label }}</span>
                        @if (isset($section['selects'][$field]))
                            <select wire:model="{{ $section['state'] }}.{{ $field }}" class="rounded border p-2">
                                <option value="">Pilih</option>
                                @foreach ($section['selects'][$field] as $value => $text)
                                    <option value="{{ $value }}">{{ $text }}</option>
                                @endforeach
                            </select>
                        @elseif ($field === 'taxable')
                            <input type="checkbox" wire:model="{{ $section['state'] }}.{{ $field }}">
                        @elseif ($field === 'account_id')
                            <select wire:model="component.account_id" class="rounded border p-2">
                                <option value="">Pilih akun untuk potongan</option>
                                @foreach ($this->accountOptions() as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                @endforeach
                            </select>
                        @elseif ($field === 'source_note')
                            <textarea wire:model="{{ $section['state'] }}.{{ $field }}" class="rounded border p-2"></textarea>
                        @else
                            <input type="{{ str_ends_with($field, '_from') ? 'date' : (str_contains($field, '_minor') || str_ends_with($field, '_bps') || $field === 'target_cups' ? 'number' : 'text') }}"
                                wire:model="{{ $section['state'] }}.{{ $field }}" class="rounded border p-2">
                        @endif
                        @error($section['state'].'.'.$field) <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="md:col-span-3"><button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Tambah versi</button></div>
            </form>
            <div class="overflow-x-auto">
                <table class="mt-4 w-full text-sm">
                    <thead><tr>@foreach ($section['fields'] as $label)<th class="p-2 text-left">{{ $label }}</th>@endforeach @if (in_array($section['table'], ['payroll_settings', 'ptkp_rates'])) <th>Verifikasi</th> @endif</tr></thead>
                    <tbody>
                        @foreach ($this->rows($section['table']) as $row)
                            <tr class="border-t">
                                @foreach ($section['fields'] as $field => $label)
                                    <td class="p-2">{{ $field === 'taxable' ? ($row->$field ? 'Ya' : 'Tidak') : ($row->$field ?? '—') }}</td>
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
                <form wire:submit="publishTer" class="mt-3 flex flex-wrap gap-3">
                    <label>Kategori
                        <select wire:model="terPublication.category" class="rounded border p-2">
                            <option value="">Pilih</option><option value="A">A</option><option value="B">B</option><option value="C">C</option>
                        </select>
                        @error('terPublication.category') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <label>Berlaku mulai
                        <input type="date" wire:model="terPublication.effective_from" class="rounded border p-2">
                        @error('terPublication.effective_from') <span class="text-red-600">{{ $message }}</span> @enderror
                    </label>
                    <button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Terbitkan versi TER</button>
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
        <label>Catatan verifikasi <input type="text" wire:model="verificationNote" class="rounded border p-2"></label>
        @error('verificationNote') <span class="text-red-600">{{ $message }}</span> @enderror
        <form wire:submit="saveAnnualVersion" class="flex gap-3">
            <label>Berlaku mulai <input type="date" wire:model="annualVersion.effective_from" class="rounded border p-2"></label>
            <label>Sumber regulasi <input type="text" wire:model="annualVersion.source_note" class="rounded border p-2"></label>
            <button type="submit">Tambah versi</button>
        </form>
        <form wire:submit="saveAnnualBracket" class="flex flex-wrap gap-3">
            <label>Versi <select wire:model="annualBracket.version_id" class="rounded border p-2"><option value="">Pilih</option>
                @foreach ($this->rows('payroll_annual_tax_versions') as $version)<option value="{{ $version->id }}">{{ $version->effective_from }}</option>@endforeach
            </select></label>
            <label>Dasar mulai (Rp) <input type="number" wire:model="annualBracket.lower_taxable_minor" class="rounded border p-2"></label>
            <label>Sampai (kosong = tanpa batas) <input type="number" wire:model="annualBracket.upper_taxable_minor" class="rounded border p-2"></label>
            <label>Tarif (bps) <input type="number" wire:model="annualBracket.rate_bps" class="rounded border p-2"></label>
            <button type="submit">Tambah rentang</button>
        </form>
        @foreach ($this->rows('payroll_annual_tax_versions') as $version)
            <p>{{ $version->effective_from }} · {{ $version->source_note }} · {{ $version->verified_at ? 'Verified oleh #'.$version->verified_by.' pada '.$version->verified_at : 'Belum verified' }}
                @if (! $version->verified_at)<button type="button" wire:click="verifyVersion('payroll_annual_tax_versions', {{ $version->id }})">Verifikasi</button>@endif
            </p>
            <table class="w-full text-sm"><thead><tr><th>Dasar mulai</th><th>Dasar sampai</th><th>Tarif (bps)</th></tr></thead>
                <tbody>@foreach ($this->annualBrackets($version->id) as $bracket)
                    <tr><td>{{ number_format($bracket->lower_taxable_minor, 0, ',', '.') }}</td>
                        <td>{{ $bracket->upper_taxable_minor === null ? 'Tanpa batas' : number_format($bracket->upper_taxable_minor, 0, ',', '.') }}</td>
                        <td>{{ $bracket->rate_bps }}</td></tr>
                @endforeach</tbody>
            </table>
        @endforeach
    </section>

    <section class="rounded-xl border p-4">
        <h2 class="text-lg font-semibold">Komponen Karyawan per Periode</h2>
        <form wire:submit="saveEntry" class="grid gap-3 md:grid-cols-3">
            <label class="grid gap-1">Karyawan
                <select wire:model="entry.user_id" class="rounded border p-2">
                    <option value="">Pilih karyawan</option>
                    @foreach ($this->employeeOptions() as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                    @endforeach
                </select>
                @error('entry.user_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Jenis komponen
                <select wire:model="entry.component_type_id" class="rounded border p-2">
                    <option value="">Pilih komponen</option>
                    @foreach ($this->typeOptions() as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->effective_from->format('Y-m-d') }})</option>
                    @endforeach
                </select>
                @error('entry.component_type_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Awal periode (tanggal 1)
                <input type="date" wire:model="entry.period_start" class="rounded border p-2">
                @error('entry.period_start') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Nominal (Rp)
                <input type="number" min="1" wire:model="entry.amount_minor" class="rounded border p-2">
                @error('entry.amount_minor') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <label class="grid gap-1">Catatan
                <input type="text" wire:model="entry.note" class="rounded border p-2">
                @error('entry.note') <span class="text-red-600">{{ $message }}</span> @enderror
            </label>
            <div class="md:col-span-3"><button type="submit" class="rounded bg-primary-600 px-4 py-2 text-white">Tambah komponen</button></div>
        </form>
        <table class="mt-4 w-full text-sm">
            <thead><tr><th>Karyawan</th><th>Periode</th><th>Komponen</th><th>Nominal</th><th>Ubah saat DRAFT</th></tr></thead>
            <tbody>
                @foreach ($this->entryRows() as $row)
                    <tr class="border-t"><td>{{ $row->user?->name }}</td><td>{{ $row->period_start->format('Y-m') }}</td>
                        <td>{{ $row->type?->name }}</td><td>{{ $row->amount_minor }}</td>
                        <td><input type="number" min="1" wire:model="entryEdits.{{ $row->id }}.amount_minor" placeholder="Nominal baru" class="w-36 rounded border p-1">
                            <input type="text" wire:model="entryEdits.{{ $row->id }}.note" placeholder="Catatan" class="w-32 rounded border p-1">
                            <button type="button" wire:click="saveEditedEntry({{ $row->id }})">Simpan</button>
                        </td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-filament-panels::page>
