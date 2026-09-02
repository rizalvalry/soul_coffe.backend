# Soul Coffeemate — Akses Build Produksi (v1.0.2)

Ini **bukan** build demo. `dist/soul-coffeemate-v1.0.2.apk` bicara langsung ke API produksi di
`https://soulcoffee.rafancloud.com/api/v1` — setiap alokasi, refill request, foto, dan tanda
tangan yang dibuat lewat APK ini **tersimpan sungguhan** di database live. Kalau butuh alur yang
aman diulang-ulang tanpa konsekuensi, pakai `dist/soul-coffeemate-DEMO-v1.0.1.apk` (lihat
`DEMO-ACCESS.md`) — itu berjalan offline dengan data fiktif.

---

## ⚠️ Kredensial di bawah ini publik

Kelima akun di tabel berikut dibuat oleh `database/seeders/UserSeeder.php`, yang ada di
repositori ini — dan repositori ini **publik**. Saya sudah membuktikan langsung: kredensial
Administrator di bawah berhasil login ke API produksi live pada 2026-09-02, lalu saya logout
token itu segera setelah verifikasi. Siapa pun yang menemukan repo ini punya akses yang sama.

Ini bukan potensi risiko — ini eksposur aktif, sejak API-nya hidup. Rotasi password kelima akun
ini (`php artisan tinker` atau lewat query langsung) adalah langkah yang disarankan sebelum akun
ini dipakai untuk operasional sungguhan, atau jadikan repo ini private.

## Akun login

| Role | Nomor HP | Password |
|---|---|---|
| Administrator | `+6281100000001` | `admin123` |
| Finance | `+6281100000002` | `finance123` |
| Barista | `+6281100000003` | `barista123` |
| Rider | `+6281100000004` | `rider123` |
| Staff (Maufu) | `+6281100000005` | `staff123` |

**PIN staff:** `123456`. Nomor boleh diketik `08...`, `62...`, atau `+62...`.

**Staff Maufu bertugas di gerobak `0018`, lokasi Sudirman** — dikonfirmasi langsung dari
`GET /me` pada akun ini, bukan asumsi dari data seed.

Kelima akun ini dikonfirmasi bisa login ke API produksi pada 2026-09-02 (`POST /auth/login`
mengembalikan 200 dan role yang benar untuk semuanya; setiap token probe langsung di-revoke lewat
`POST /auth/logout` setelah diperiksa). Tidak ada akun lain — belum ada API untuk membuat user
baru (lihat bagian "Menambah akun" di bawah).

---

## APK

**Berkas:** `dist/soul-coffeemate-v1.0.2.apk`

| Properti | Nilai |
|---|---|
| Ukuran | 22.7 MB |
| Package | `id.soulcoffeemate.ops.demo` |
| Versi | 1.0.2 (versionCode 3) |
| Min Android | **7.0** (API 24) |
| Target | Android 16 (API 36) |
| Arsitektur | `arm64-v8a`, `armeabi-v7a` |
| SHA-256 | `d6e6d0eb4474e1fd66b158fd339ca429db55c46e514370fb25fa97fffbd463d8` |
| Tanda tangan | Sama dengan v1.0.0/v1.0.1 — kalau sudah pasang salah satu, tinggal install ini, tidak perlu uninstall dulu |

Package masih diberi akhiran `.demo` (peninggalan penamaan awal) meski build ini sudah bicara ke
data nyata — akan diganti sebelum rilis Play Store yang sesungguhnya.

### Yang sudah diverifikasi, dan yang belum

Sudah dikonfirmasi langsung terhadap API live sebelum APK ini dibuild:

- `POST /auth/login` mengembalikan bentuk `{ data: { token, user } }` yang persis dibaca kode
  mobile — bug sebelumnya (`auth/api.ts` tidak pernah unwrap `data`) sudah diperbaiki dan
  terbukti benar terhadap server sungguhan, bukan cuma lolos pengecekan tipe.
- `GET /products`, `GET /refills`, `GET /badges`, `GET /me`, `GET /me/allocation/today` semuanya
  mengembalikan bentuk JSON yang sudah dipetakan `src/lib/mappers.ts` di `soul_coffe.mobile`.
- Kelima akun seed berhasil login dan mendapat `role` yang benar.
- Sertifikat APK sama dengan build sebelumnya (upgrade in-place), R8/shrinkResources tidak
  menghapus apa pun yang dibutuhkan runtime (`npm run apk:verify` — 10/10 lolos).

**Yang belum:** APK ini **belum pernah dijalankan** di HP atau emulator fisik — mesin build tidak
punya perangkat, dan satu-satunya image emulator yang terpasang adalah x86_64, arsitektur yang
sengaja dibuang dari APK ini. Semua verifikasi di atas dilakukan lewat `curl` langsung ke API dan
lewat pembongkaran isi APK, bukan dengan menjalankan aplikasinya. Yang paling perlu ditekan saat
smoke test pertama, karena menyentuh modul native yang tidak tersentuh oleh verifikasi API:
login, foto refill (kamera), tanda tangan serah terima (WebView), lokasi rider (GPS).

Dua keterbatasan yang sudah diketahui dan didokumentasikan di `soul_coffe.mobile` README ("Real
backend"): `RefillRequest.location_name` dan `Allocation.barista_name` akan selalu tampil
"Tidak diketahui" — backend tidak pernah mengembalikan kedua field ini sama sekali, ini bukan
bug di APK.

### Izin yang diminta

Sama seperti build demo sebelumnya — lihat `DEMO-ACCESS.md` bagian "Izin yang diminta".

### Cara memasang

1. Buka repositori ini dari browser HP → folder `dist/` → unduh `soul-coffeemate-v1.0.2.apk`.
2. Izinkan **Install unknown apps** untuk browser yang dipakai.
3. Buka berkas yang terunduh → **Install**.
4. Play Protect akan memperingatkan karena APK ini tidak ditandatangani sertifikat Play Store —
   pilih **Install anyway**.

---

## Menambah akun

Belum ada API atau layar admin untuk membuat user baru — ini sengaja tidak dibangun pada
iterasi ini. Untuk menambah akun sekarang, satu-satunya jalan adalah langsung ke database
(`php artisan tinker` atau `INSERT` manual ke tabel `users`, mengikuti pola
`database/seeders/UserSeeder.php`: `phone_e164` format `+62...`, `password` di-hash lewat
`Hash::make()`, `role` salah satu dari `ADMINISTRATOR/FINANCE/BARISTA/RIDER/STAFF`).

---

## Kalau login gagal

| Gejala | Kemungkinan |
|---|---|
| "Tidak dapat menghubungi server" | Cek `https://soulcoffee.rafancloud.com/api/v1/auth/login` masih hidup |
| "Nomor HP atau kata sandi salah" | Password sudah dirotasi (lihat peringatan di atas) — pakai password baru |
| Daftar refill/alokasi kosong | Normal — belum ada aktivitas ditulis lewat akun ini |
| "Anda tidak bertugas di gerobak ini hari ini" | Penugasan staff hanya berlaku untuk tanggal yang di-seed; perlu penugasan baru untuk hari ini |
