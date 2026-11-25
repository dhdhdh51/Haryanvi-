<?php

declare(strict_types=1);

namespace Haryanvi\Http\Controllers;

use Haryanvi\Core\Request;
use Haryanvi\Core\Response;
use Haryanvi\Core\RouteContext;
use Haryanvi\Models\Dialect;
use Haryanvi\Models\TonePreset;
use Haryanvi\Repositories\DialectRepository;

/**
 * GET /api/v1/dialects
 *
 * Public: the app fetches this on first launch to populate the dialect and
 * tone pickers, before the user has an account. `prompt_notes` is internal
 * prompt engineering and is deliberately not exposed.
 */
final class DialectController
{
    public function __construct(private readonly DialectRepository $dialects = new DialectRepository())
    {
    }

    public function index(Request $request, RouteContext $context): void
    {
        $dialects = $this->dialects->activeDialects();
        $tones    = $this->dialects->activeTones();

        Response::success([
            'dialects' => array_map(static fn (Dialect $d): array => $d->toArray(), $dialects),
            'tones'    => array_map(static fn (TonePreset $t): array => $t->toArray(), $tones),
            'target_language' => [
                'code'    => 'bgc',
                'name_en' => 'Haryanvi',
                'name_hi' => 'हरियाणवी',
            ],
            'source_languages' => [
                ['code' => 'auto', 'name_en' => 'Auto detect', 'name_hi' => 'स्वतः पहचान'],
                ['code' => 'hi',   'name_en' => 'Hindi',       'name_hi' => 'हिन्दी'],
                ['code' => 'en',   'name_en' => 'English',     'name_hi' => 'अंग्रेज़ी'],
            ],
            'output_scripts' => [
                ['code' => 'devanagari', 'name_en' => 'Devanagari', 'name_hi' => 'देवनागरी'],
                ['code' => 'roman',      'name_en' => 'Roman',      'name_hi' => 'रोमन'],
            ],
        ]);
    }
}
