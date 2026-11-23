<?php

namespace App\Filament\Resources\Privacy\LegalDocuments\Pages;

use App\Contracts\ConsentServiceInterface;
use App\Filament\Resources\Privacy\LegalDocuments\LegalDocumentResource;
use App\Filament\Support\Failure;
use App\Models\LegalDocument;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateLegalDocument extends CreateRecord
{
    protected static string $resource = LegalDocumentResource::class;

    /** Publica por el servicio (hash, `effective_at` heredado de la versión ya publicada, inmutabilidad). */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var array{key: string, version: string, locale: string, title: string, body: string, effective_at?: string|null} $data */
        $data = array_filter($data, fn (mixed $value): bool => $value !== null && $value !== '');
        $author = auth('web')->user();

        $document = Failure::guard(fn (): LegalDocument => app(ConsentServiceInterface::class)->publish($data, $author instanceof User ? $author : null));

        return $document ?? throw new Halt;
    }
}
