# Soul Coffeemate — Akses Demo & Cara Instalasi

Semua yang dibutuhkan untuk memasang APK dan login, tanpa perlu membuka workspace di komputer.

---

## ⚠️ Baca ini lebih dulu

**Password di dokumen ini ada di dalam repositori, dan juga ada di dalam APK demo.** Kalau
repositori ini publik, siapa pun yang menemukannya bisa login ke API yang sudah ter-deploy —
karena akun-akun ini persis akun yang dibuat seeder di server.

Pilih salah satu, jangan diabaikan:

1. **Jadikan repositori ini private**, atau
2. **Ganti semua password setelah demo selesai**, atau
3. Kalau API belum diaktifkan (lihat *Setup server*), belum ada yang bisa dipakai orang lain —
   risikonya nol sampai API itu benar-benar hidup.

Ini akun demo. Jangan dipakai staf sungguhan.

---

## Akun login

| Role | Nomor HP | Password |
|---|---|---|
| Administrator | `+6281100000001` | `admin123` |
| Finance | `+6281100000002` | `finance123` |
| Barista | `+6281100000003` | `barista123` |
| Rider | `+6281100000004` | `rider123` |
| Staff (Maufu) | `+6281100000005` | `staff123` |

**PIN staff:** `123456` — dipakai Rider saat HP staff mati sehingga tanda tangan tidak bisa
diambil (jalur cadangan, supaya pengiriman tidak menggantung).

Nomor boleh diketik `08...`, `62...`, atau `+62...` — semuanya dinormalkan.

**Staff Maufu bertugas di gerobak `0018`.** Staff hanya bisa request refill untuk gerobak yang
ditugaskan kepadanya pada hari itu.

---

## APK

**Berkas:** `dist/soul-coffeemate-DEMO-v1.0.0.apk`

| Properti | Nilai |
|---|---|
| Ukuran | 56 MB |
| Package | `id.soulcoffeemate.ops.demo` |
| Versi | 1.0.0 |
| Min Android | **7.0** (API 24) |
| Target | Android 16 (API 36) |
| Arsitektur | `arm64-v8a`, `armeabi-v7a` |
| SHA-256 | `053b243c3bd265fdf29ff923b3565288fb4deeba04d626839bb341e2db455924` |

Package-nya diberi akhiran `.demo` supaya bisa terpasang berdampingan dengan build produksi nanti,
tanpa saling menimpa.

### Yang sudah diverifikasi, dan yang belum

Sudah diperiksa langsung pada berkas APK-nya:

- Tanda tangan valid — APK bisa dipasang
- Bundle JS ada di dalamnya (3,4 MB), berisi data demo dan seluruh teks Indonesia
- Izin berlebih sudah hilang (lihat di bawah)
- `demoMode: true` benar ter-bake di konfigurasi build

**Yang belum:** APK ini **belum pernah dijalankan** di HP atau emulator. Mesin build tidak
memiliki emulator terpasang dan tidak ada perangkat terhubung. Logika alur dan seluruh guard-nya
sudah dibuktikan lewat eksekusi nyata di level state machine, tetapi tampilan UI-nya sendiri —
kamera, kanvas tanda tangan, tombol ganti role — belum pernah disentuh tangan. Anda akan menjadi
yang pertama menjalankannya. Kalau ada yang aneh, itu bukan hal yang mustahil.

### Izin yang diminta

| Izin | Alasan |
|---|---|
| Kamera | **Wajib.** Foto bukti kondisi frozen gerobak. Aplikasi hanya membuka kamera, tidak pernah galeri, supaya foto lama tidak bisa dipakai ulang. |
| Lokasi | **Opsional.** Kalau ditolak, request tetap terkirim dan hanya ditandai "tanpa GPS". Staff di lapangan tidak boleh terhalang karena GPS mati. |
| Internet, status jaringan | Deteksi koneksi |
| Biometrik | Dipakai penyimpanan token yang aman |

Build pertama sempat ikut meminta **mikrofon** dan **"tampilkan di atas aplikasi lain"** — dibawa
otomatis oleh pustaka pemilih gambar dan tooling React Native. Aplikasi ini hanya memotret, jadi
keduanya sudah diblokir dan sudah dipastikan hilang dari APK ini.

### Cara memasang

1. Buka repositori ini dari browser HP → folder `dist/` → unduh berkas `.apk`.
2. Izinkan **Install unknown apps** untuk browser yang dipakai
   (Settings → Apps → [browser] → Install unknown apps).
3. Buka berkas yang terunduh → **Install**.
4. Play Protect akan memperingatkan karena APK ini tidak ditandatangani sertifikat Play Store —
   pilih **Install anyway**. Wajar untuk APK yang dibagikan langsung.

---

## Mode demo — apa yang nyata dan apa yang tidak

APK ini berjalan **tanpa server, tanpa database, tanpa internet**. Datanya ada di dalam aplikasi.
Banner **MODE DEMO — DATA TIDAK NYATA** muncul di setiap layar dan tidak bisa ditutup.

Di banner itu ada pengalih role — tekan untuk berpindah antar kelima role tanpa login ulang.
Itulah cara satu orang menjalankan seluruh alur di satu HP.

**Batasnya, dan ini harus jelas:** demo mode mensimulasikan realtime karena satu aplikasi berbagi
satu store di dalam proses yang sama. Perubahan muncul seketika saat berganti role, dan tampak
identik dengan realtime — tetapi itu **bukan bukti** bahwa notifikasi antar-HP bekerja. Untuk itu
dibutuhkan API online plus server WebSocket yang hidup.

---

## Alur demo (8 langkah)

| # | Role | Lakukan |
|---|---|---|
| 1 | **Barista** | *Alokasi Harian* → pilih Maufu → jumlah terisi otomatis dari target → kirim |
| 2 | **Staff** | *Request Refill* → isi jumlah → **ambil foto** → kirim |
| 3 | **Barista** | *Permintaan Refill* → request terlihat, tapi tombol **Siapkan mati**, berlabel "Menunggu Approval Finance" |
| 4 | **Finance** | *Approval Refill* → lihat rincian dan **nilai total** → Approve |
| 5 | **Barista** | Tombol **Siapkan** kini aktif → isi jumlah siap → **Siap Diambil** |
| 6 | **Rider** | *Siap Diambil* → **Ambil** |
| 7 | **Rider** | *Pengiriman Saya* → isi jumlah diterima → **staff tanda tangan di HP rider** → kirim |
| 8 | — | Status **Selesai**, stok gerobak bertambah |

**Langkah 3 dan 5 adalah inti seluruh sistem ini.** Barista bisa *melihat* permintaan sejak
detik pertama, tapi *tidak bisa* menyiapkannya sebelum Finance menyetujui. Tombol yang mati itu
sekadar kenyamanan — yang benar-benar menjaga aturan adalah server, yang menolak dengan `409`
kalau tetap dipaksa. Di API sungguhan hal ini dibuktikan oleh test otomatis.

Yang menarik untuk dicoba menggagalkannya:

- Kirim request kedua untuk gerobak yang sama → ditolak, satu gerobak hanya boleh punya satu
  permintaan terbuka
- Finance menyetujui lebih banyak dari yang diminta → ditolak
- Tanda tangan berupa satu titik → ditolak, minimal 3 goresan
- Pilih "Staff tidak bisa paraf?" → masukkan PIN `123456`
- Alokasi pagi melebihi target lebih dari 20% → naik ke Finance, stok belum berpindah

Yang terakhir itu penting: tanpa aturan tersebut, gerbang approval Finance bisa dilewati begitu
saja dengan melebihkan alokasi di pagi hari.

---

## Setup server (hanya perlu untuk demo lintas HP)

API sudah ter-deploy dan **sudah hidup** di
`https://rafancloud.com/soulcoffee/public/api/v1` — 32 endpoint, Laravel 12.69.

Perhatikan `/public` di URL itu. Laravel berada di subdirektori, dan mencoba menyembunyikan
`/public` lewat rewrite justru membuat Laravel salah menghitung base path sehingga semua route
404. Untuk URL bersih, buat subdomain `api.rafancloud.com` dengan document root langsung ke
`soulcoffee/public` — sekaligus memindahkan root project ke luar web root, sehingga keamanannya
struktural, bukan bergantung pada aturan `.htaccess` yang bisa salah tulis.

Tiga langkah yang tersisa:

**1. Buat database dan impor data.** hPanel → MySQL Databases → buat database. Lalu phpMyAdmin →
pilih database itu → Import → unggah `dist/database/soul_coffee_full.sql`. Hasilnya 29 tabel.

**2. Isi tiga baris di `.env` server** (`~/domains/rafancloud.com/public_html/soulcoffee/.env`).
Semua sudah terisi termasuk `APP_KEY`; yang kosong hanya:

```
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Sengaja saya kosongkan — kredensial database itu milik Anda, dan menebaknya hanya akan gagal
dengan cara yang membingungkan.

**3. Bersihkan cache config.**

```bash
cd ~/domains/rafancloud.com/public_html/soulcoffee
php artisan config:clear && php artisan config:cache
```

Tidak perlu `php artisan migrate` kalau langkah 1 sudah dilakukan.

Cara memastikan berhasil: `https://rafancloud.com/soulcoffee/public/up` harus mengembalikan 200
(ini **sudah** 200 sekarang), dan `.../public/api/v1/products` harus berhenti mengembalikan 500.
Saat ini masih 500, dan penyebabnya sudah dipastikan dari log server:
`SQLSTATE[HY000] [1045] Access denied for user ''@'127.0.0.1'` — persis tiga baris yang kosong itu.

### Realtime di hosting ini tidak akan aktif

Saya sudah memeriksa server langsung: tidak ada `supervisorctl`, `systemctl`, `screen`, maupun
`tmux`. Tidak ada satu pun cara menjaga proses WebSocket tetap hidup di shared hosting ini.
Aplikasi otomatis turun ke pembaruan berkala setiap 10 detik dan tetap berfungsi — hanya saja ada
jeda beberapa detik, bukan seketika. Kalau notifikasi seketika memang wajib, yang dibutuhkan VPS
kecil, bukan shared hosting.

---

## Kalau login gagal

| Gejala | Kemungkinan |
|---|---|
| Bisa login tanpa internet | Normal — ini APK demo, memang tidak memakai server |
| "Tidak dapat menghubungi server" (pada build hosting) | Tiga baris `DB_*` belum diisi |
| "Nomor HP atau kata sandi salah" | `soul_coffee_full.sql` belum diimpor, jadi belum ada user |
| Daftar kosong setelah login | Normal untuk database bersih — mulai dari langkah 1 |
| "Anda tidak bertugas di gerobak ini hari ini" | Penugasan staff hanya untuk tanggal seed. Administrator perlu membuat penugasan hari ini. |
