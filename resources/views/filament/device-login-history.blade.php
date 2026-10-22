<div class="space-y-2">
    @forelse ($attempts as $attempt)
        <div class="rounded border p-2 text-sm">
            {{ $attempt->created_at?->format('d M Y H:i') }} · {{ $attempt->succeeded ? 'Berhasil' : 'Ditolak' }}
            · {{ $attempt->device_name ?: 'HP tidak dikenal' }}
            ({{ \App\Models\UserDevice::maskId($attempt->device_id) }})
            · {{ $attempt->reasonLabel() }} · {{ $attempt->ip ?: 'IP tidak diketahui' }}
        </div>
    @empty
        <p>Belum ada riwayat login untuk akun ini.</p>
    @endforelse
</div>
