<?php

namespace App\Filament\Resources\NewsPosts\Schemas;

use App\Enums\Role;
use App\Filament\RichEditorPlugins\InlineImagePastePlugin;
use App\Models\NewsPost;
use App\Services\NewsArticleGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The writer's workspace.
 *
 * The brief was explicit that the form must not box in the creator's ideas, so `body` is a full
 * rich editor with no length cap and the only required fields are the three a card cannot be
 * rendered without: a title, a body, and a status. Everything that shapes how the post LOOKS —
 * kicker, tags, accent colour, cover — is optional, and the app degrades gracefully when each is
 * absent.
 *
 * The fields that are NOT free-form are the ones that decide who sees what and when, because
 * those are the difference between a feed people read and a noticeboard they learn to ignore.
 */
class NewsPostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tulisan')
                    ->description('Bebas berekspresi — hanya judul dan isi yang wajib.')
                    ->headerActions([
                        static::generateWithAiAction(),
                    ])
                    ->schema([
                        TextInput::make('kicker')
                            ->label('Kata Pembuka (gaul)')
                            ->maxLength(60)
                            ->placeholder('BARU NIH!')
                            ->helperText('Baris pendek di atas judul. Dibuat mencolok di aplikasi.'),

                        TextInput::make('title')
                            ->label('Judul')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // The slug is generated but stays editable: an author renaming a post
                            // should not silently break a link someone already shared.
                            ->afterStateUpdated(function (string $state, callable $set, ?NewsPost $record): void {
                                if ($record === null) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Dibuat otomatis dari judul. Ubah hanya bila perlu.'),

                        Textarea::make('excerpt')
                            ->label('Ringkasan')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Muncul di kartu slider dan daftar. Kosongkan untuk memakai awal isi.'),

                        RichEditor::make('body')
                            ->label('Isi Artikel')
                            ->required()
                            // Lets a drag from another browser tab, or a paste of a copied web
                            // image, actually upload instead of falling through to the browser's
                            // own default of opening the image in a new tab — see
                            // InlineImagePastePlugin's docblock.
                            ->plugins([new InlineImagePastePlugin])
                            ->fileAttachmentsMaxSize(8192)
                            ->columnSpanFull(),
                    ]),

                Section::make('Tampilan')
                    ->description('Menentukan wajah kartu di aplikasi.')
                    ->schema([
                        FileUpload::make('cover_path')
                            ->label('Gambar Sampul')
                            ->image()
                            ->imageEditor()
                            // 16:9 matches the slider card, so what the writer crops here is what
                            // they get in the app rather than a surprise centre-crop.
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('16:9')
                            ->imageResizeTargetWidth(1280)
                            ->imageResizeTargetHeight(720)
                            // A real phone/camera JPEG straight off the device is routinely
                            // 5-8MB; the old 5MB cap rejected exactly the photos a content
                            // creator was most likely to use. Kept below the server's raised
                            // upload ceiling (see public/.user.ini) with headroom to spare.
                            ->maxSize(8192)
                            ->helperText('JPG, PNG, atau WEBP. Maksimal 8MB.')
                            ->disk('public')
                            ->directory('news')
                            ->visibility('public'),

                        TagsInput::make('tags')
                            ->label('Tags')
                            ->placeholder('promo, tips, semangat')
                            ->helperText('Ditampilkan sebagai chip kecil di kartu.'),

                        ColorPicker::make('accent_color')
                            ->label('Warna Aksen')
                            ->helperText('Mewarnai gradasi kartu. Kosong = warna brand.'),
                    ])
                    ->columns(2),

                Section::make('Penayangan')
                    ->description('Siapa melihatnya, kapan, dan di urutan berapa.')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Draft — belum terlihat siapa pun',
                                'published' => 'Terbit',
                                'archived' => 'Arsip — ditarik dari feed',
                            ])
                            ->default('draft')
                            ->required()
                            ->native(false),

                        Select::make('audience_roles')
                            ->label('Untuk Role')
                            ->multiple()
                            ->options(collect(Role::cases())
                                ->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])
                                ->all())
                            ->helperText('Kosongkan untuk semua orang. Artikel yang ditujukan ke role tertentu jauh lebih dibaca.')
                            ->native(false),

                        Toggle::make('is_highlighted')
                            ->label('Tampilkan di Slider Beranda')
                            ->helperText('Artikel sorotan muncul paling depan di beranda staff.'),

                        TextInput::make('sort_order')
                            ->label('Urutan')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->helperText('Angka kecil tampil lebih dulu.'),

                        DateTimePicker::make('published_at')
                            ->label('Tayang Mulai')
                            ->seconds(false)
                            ->helperText('Kosong = langsung tayang begitu status Terbit.'),

                        DateTimePicker::make('expires_at')
                            ->label('Berakhir')
                            ->seconds(false)
                            ->helperText('Kosong = tidak pernah berakhir. Isi untuk promo bertenggat.'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Fills the whole "Tulisan" section from a one-line brief. Never touches Penayangan — status,
     * audience, scheduling, and highlighting stay an editorial decision the writer makes
     * afterwards, not something a model gets to default.
     */
    protected static function generateWithAiAction(): Action
    {
        return Action::make('generateWithAi')
            ->label('Generate dengan AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading('Generate draft artikel')
            ->modalSubmitActionLabel('Generate')
            ->schema([
                Textarea::make('prompt')
                    ->label('Ide atau brief singkat')
                    ->placeholder('Contoh: promo matcha baru minggu ini, nada ceria, ajak staff coba')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data, callable $set): void {
                try {
                    $draft = app(NewsArticleGenerator::class)->generate($data['prompt']);
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title('Gagal generate draft')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $set('kicker', $draft['kicker']);
                $set('title', $draft['title']);
                $set('slug', $draft['slug']);
                $set('excerpt', $draft['excerpt']);
                $set('body', $draft['body']);
                $set('tags', $draft['tags']);

                if (filled($draft['accent_color'])) {
                    $set('accent_color', $draft['accent_color']);
                }

                Notification::make()
                    ->title('Draft AI sudah masuk ke form')
                    ->body('Cek dan sunting sebelum menyimpan — terutama fakta dan harga.')
                    ->success()
                    ->send();
            });
    }
}
