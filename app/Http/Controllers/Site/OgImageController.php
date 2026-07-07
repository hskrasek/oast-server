<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Og\OgImageRenderer;
use App\Site\Og\OgTemplate;
use App\Site\PublicationRepository;
use Illuminate\Http\Response;
use Throwable;

final class OgImageController
{
    public function __invoke(
        string $file,
        PublicationRepository $publications,
        OgImageRenderer $renderer,
        OgTemplate $template,
    ): Response {
        if (preg_match('/^(?<slug>.+)-(?<hash>[a-f0-9]{8})$/', $file, $matches) !== 1) {
            abort(404);
        }

        $slug = $matches['slug'];

        // Resolve BEFORE the try so an unknown slug 404s instead of falling back.
        $view = $slug === 'home'
            ? $template->home()
            : $template->review($publications->find($slug) ?? abort(404));

        try {
            $png = $renderer->screenshot($view->render());

            return response($png, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response((string) file_get_contents(public_path('og/fallback.png')), 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=300',
            ]);
        }
    }
}
