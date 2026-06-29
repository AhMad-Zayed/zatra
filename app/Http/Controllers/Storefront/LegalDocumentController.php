<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LegalDocumentController extends Controller
{
    /**
     * Display a legal document dynamically.
     */
    public function show(Tenant $tenant, string $document)
    {
        $content = match ($document) {
            'terms' => $tenant->terms_conditions,
            'privacy' => $tenant->privacy_policy,
            'refund' => $tenant->refund_policy,
            default => null,
        };

        if (!$content) {
            throw new NotFoundHttpException('Legal document not found or empty.');
        }

        $title = match ($document) {
            'terms' => 'الشروط والأحكام',
            'privacy' => 'سياسة الخصوصية',
            'refund' => 'سياسة الاسترجاع والإلغاء',
        };

        return view('storefront.legal-document', [
            'tenant' => $tenant,
            'title' => $title,
            'content' => $content,
        ]);
    }
}
