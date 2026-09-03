<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\AiSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Where the Gemini key and model live now, instead of .env — the entire reason this exists is
 * that every previous key/model change needed an SSH session and a deploy. An Administrator can
 * change either here and NewsArticleGenerator picks it up on the very next request; no cache to
 * clear, because AiSetting is read from the database, never from config().
 *
 * Administrator-only, deliberately not extended to Content Creator: this is API billing
 * credentials, not editorial content — the same boundary NewsPostResource draws the other way.
 */
class ManageAiSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'AI';

    protected static ?string $title = 'Pengaturan AI';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public function mount(): void
    {
        $setting = AiSetting::current();

        $this->form->fill([
            'gemini_api_key' => $setting->gemini_api_key,
            'gemini_model' => $setting->gemini_model,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('gemini_api_key')
                    ->label('Gemini API Key')
                    ->password()
                    ->revealable()
                    ->helperText('Dari https://aistudio.google.com/apikey ("Create API key"). Kosongkan lalu simpan untuk menghapus key yang tersimpan.')
                    ->maxLength(500),

                TextInput::make('gemini_model')
                    ->label('Model')
                    ->required()
                    ->default('gemini-flash-latest')
                    ->helperText('Nama model Gemini, contoh: gemini-flash-latest.')
                    ->maxLength(100),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Simpan')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AiSetting::current()->update([
            'gemini_api_key' => $data['gemini_api_key'],
            'gemini_model' => $data['gemini_model'],
        ]);

        Notification::make()
            ->title('Pengaturan AI tersimpan')
            ->success()
            ->send();
    }
}
