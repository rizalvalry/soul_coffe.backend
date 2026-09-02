<?php

namespace App\Filament\Resources\NewsPosts\Pages;

use App\Filament\Resources\NewsPosts\NewsPostResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateNewsPost extends CreateRecord
{
    protected static string $resource = NewsPostResource::class;

    /**
     * Authorship is stamped from the session, never taken from the form: it is a record of who
     * wrote this, and a field the writer could set would not be one.
     *
     * `published_at` is filled in on first publish so a post that is switched to Terbit without a
     * schedule goes live now rather than sitting invisible behind a null date.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = Auth::id();

        if (($data['status'] ?? 'draft') === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        return $data;
    }
}
