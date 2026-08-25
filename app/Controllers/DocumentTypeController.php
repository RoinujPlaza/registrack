<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Http;
use RegisTrack\Repositories\DocumentTypeRepository;

/**
 * Document-type catalogue endpoint (FR2 form dropdown source).
 */
final class DocumentTypeController
{
    /** GET /api/v1/document-types */
    public static function list(array $params): void
    {
        Auth::requireUser();
        Http::json(['items' => (new DocumentTypeRepository())->listActive()]);
    }
}
