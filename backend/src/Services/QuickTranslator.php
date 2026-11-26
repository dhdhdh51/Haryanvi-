<?php

declare(strict_types=1);

namespace Haryanvi\Services;

/**
 * Instant, rule-based Hindi -> Haryanvi conversion. No AI involved.
 *
 * Hindi and Haryanvi share most of their vocabulary and word order, so a
 * table of the high-frequency differences (copula, negation, question
 * words, pronouns, infinitive endings, a few perfective forms) gets simple
 * chat messages most of the way there in well under a millisecond.
 *
 * Limits, stated plainly so callers can tell the user:
 *   * Hindi input only: Devanagari or Hinglish (Hindi typed in Latin letters).
 *     English is not translated; translate() returns null for it.
 *   * It changes words, not grammar. Agreement and idiom are not adjusted
 *     the way the AI engine does, so long or complex sentences come out
 *     closer to "Hindi with Haryanvi words".
 *
 * Output follows the input script: Devanagari input gives a Devanagari
 * translation plus a Roman transliteration; Hinglish input gives Hinglish.
 */
final class QuickTranslator
{
    /**
     * Devanagari rules: Hindi => [Haryanvi, Roman]. Multi-word keys are
     * matched before single words, longest first.
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const DEVANAGARI = [
        // Phrases
        'पता नहीं है'   => ['बेरा कोन्या', 'bera konya'],
        'पता नहीं'      => ['बेरा ना', 'bera na'],
        'नहीं पता'      => ['बेरा ना', 'bera na'],
        'नहीं है'       => ['कोन्या', 'konya'],
        'नहीं हैं'      => ['कोन्या', 'konya'],
        'के लिए'        => ['खातर', 'khatar'],
        'के लिये'       => ['खातर', 'khatar'],
        'के साथ'        => ['गेल्यां', 'gelyan'],
        'के पास'        => ['धोरै', 'dhore'],
        'कैसे हो'       => ['किस्यां सै', 'kisyan se'],
        'कैसे हैं'      => ['किस्यां सैं', 'kisyan sain'],
        'कहाँ हो'       => ['कित सै', 'kit se'],
        'कहां हो'       => ['कित सै', 'kit se'],
        'क्या हाल है'   => ['के हाल सै', 'ke haal se'],
        'क्या कर रहे हो' => ['के कर रह्या सै', 'ke kar rahya se'],
        'क्या कर रही हो' => ['के कर री सै', 'ke kar ri se'],
        'कर रहे हो'     => ['कर रह्या सै', 'kar rahya se'],
        'जा रहे हो'     => ['जा रह्या सै', 'ja rahya se'],
        'आ रहे हो'      => ['आ रह्या सै', 'aa rahya se'],

        // Copula
        'है'   => ['सै', 'se'],
        'हैं'  => ['सैं', 'sain'],
        'हूँ'  => ['सूं', 'su'],
        'हूं'  => ['सूं', 'su'],
        'था'   => ['था', 'tha'],

        // Negation
        'नहीं' => ['ना', 'na'],
        'नही'  => ['ना', 'na'],

        // Question words
        'क्या'   => ['के', 'ke'],
        'क्यों'  => ['क्यूं', 'kyun'],
        'क्यूँ'  => ['क्यूं', 'kyun'],
        'कैसे'   => ['किस्यां', 'kisyan'],
        'कैसा'   => ['किसा', 'kisa'],
        'कैसी'   => ['किसी', 'kisi'],
        'कहाँ'   => ['कित', 'kit'],
        'कहां'   => ['कित', 'kit'],
        'किधर'   => ['कित', 'kit'],
        'कब'     => ['कद', 'kad'],
        'कितना'  => ['कित्ता', 'kitta'],
        'कितनी'  => ['कित्ती', 'kitti'],
        'कितने'  => ['कित्ते', 'kitte'],

        // Time / connectives
        'अब'     => ['इब', 'ib'],
        'अभी'    => ['इब्बे', 'ibbe'],
        'फिर'    => ['फेर', 'fer'],
        'और'     => ['अर', 'ar'],
        'बहुत'   => ['घणा', 'ghana'],
        'पता'    => ['बेरा', 'bera'],

        // Pronouns
        'तुम'      => ['तू', 'tu'],
        'मुझे'     => ['मन्ने', 'manne'],
        'मुझको'    => ['मन्ने', 'manne'],
        'मैंने'    => ['मन्ने', 'manne'],
        'तुझे'     => ['तन्ने', 'tanne'],
        'तुमने'    => ['तन्ने', 'tanne'],
        'तुम्हें'  => ['तन्ने', 'tanne'],
        'तुम्हे'   => ['तन्ने', 'tanne'],
        'उसे'      => ['उसनै', 'usne'],
        'उसको'     => ['उसनै', 'usne'],
        'उसने'     => ['उसनै', 'usne'],
        'हमें'     => ['म्हानै', 'mhane'],
        'हमको'     => ['म्हानै', 'mhane'],
        'हमारा'    => ['म्हारा', 'mhara'],
        'हमारी'    => ['म्हारी', 'mhari'],
        'हमारे'    => ['म्हारे', 'mhare'],
        'तुम्हारा' => ['थारा', 'thara'],
        'तुम्हारी' => ['थारी', 'thari'],
        'तुम्हारे' => ['थारे', 'thare'],

        // Places
        'यहाँ' => ['उरै', 'ure'],
        'यहां' => ['उरै', 'ure'],
        'वहाँ' => ['उड़ै', 'ude'],
        'वहां' => ['उड़ै', 'ude'],
        'साथ'  => ['गेल्यां', 'gelyan'],
        'पास'  => ['धोरै', 'dhore'],

        // People
        'लड़का' => ['छोरा', 'chhora'],
        'लड़की' => ['छोरी', 'chhori'],
        'लड़के' => ['छोरे', 'chhore'],
        'बच्चे' => ['बालक', 'balak'],

        // Verb forms
        'रहा'   => ['रह्या', 'rahya'],
        'रहे'   => ['रह्ये', 'rahye'],
        'गया'   => ['ग्या', 'gya'],
        'गए'    => ['गे', 'ge'],
        'हुआ'   => ['होया', 'hoya'],
        'बैठा'  => ['बैठ्या', 'baithya'],
        'चला'   => ['चाल्या', 'chalya'],
        'लो'    => ['ले', 'le'],
        'जाना'  => ['जाणा', 'jana'],
        'आना'   => ['आणा', 'aana'],
        'करना'  => ['करणा', 'karna'],
        'खाना'  => ['खाणा', 'khana'],
        'पीना'  => ['पीणा', 'pina'],
        'देना'  => ['देणा', 'dena'],
        'लेना'  => ['लेणा', 'lena'],
        'रहना'  => ['रहणा', 'rahna'],
        'सुनना' => ['सुणना', 'sunna'],
        'सुनो'  => ['सुणो', 'suno'],
        'मिलना' => ['मिलणा', 'milna'],
        'मिलने' => ['मिलण', 'milan'],
        'चलो'   => ['चाल', 'chal'],
    ];

    /**
     * Hinglish rules (lowercase Latin): Hindi => Haryanvi.
     *
     * @var array<string,string>
     */
    private const ROMAN = [
        // Phrases
        'pata nahi hai'   => 'bera konya',
        'pata nahi'       => 'bera na',
        'pata nahin'      => 'bera na',
        'nahi pata'       => 'bera na',
        'nahin pata'      => 'bera na',
        'nahi hai'        => 'konya',
        'nahin hai'       => 'konya',
        'nahi hain'       => 'konya',
        'ke liye'         => 'khatar',
        'ke sath'         => 'gelyan',
        'ke saath'        => 'gelyan',
        'ke pas'          => 'dhore',
        'ke paas'         => 'dhore',
        'kaise ho'        => 'kisyan se',
        'kaha ho'         => 'kit se',
        'kahan ho'        => 'kit se',
        'kya haal hai'    => 'ke haal se',
        'kya hal hai'     => 'ke haal se',
        'kya kar rahe ho' => 'ke kar rahya se',
        'kar rahe ho'     => 'kar rahya se',
        'ja rahe ho'      => 'ja rahya se',
        'aa rahe ho'      => 'aa rahya se',

        // Copula
        'hai'  => 'se',
        'he'   => 'se',
        'hain' => 'sain',
        'hu'   => 'su',
        'hun'  => 'su',
        'hoon' => 'su',
        'hoo'  => 'su',

        // Negation
        'nahi'  => 'na',
        'nahin' => 'na',
        'nai'   => 'na',

        // Question words
        'kya'   => 'ke',
        'kyu'   => 'kyun',
        'kyon'  => 'kyun',
        'kaise' => 'kisyan',
        'kaisa' => 'kisa',
        'kaisi' => 'kisi',
        'kahan' => 'kit',
        'kaha'  => 'kit',
        'kidhar'=> 'kit',
        'kab'   => 'kad',

        // Time / connectives
        'ab'    => 'ib',
        'abhi'  => 'ibbe',
        'phir'  => 'fer',
        'fir'   => 'fer',
        'aur'   => 'ar',
        'bahut' => 'ghana',
        'bohot' => 'ghana',
        'bahot' => 'ghana',
        'pata'  => 'bera',

        // Pronouns
        'tum'     => 'tu',
        'mujhe'   => 'manne',
        'mujhko'  => 'manne',
        'maine'   => 'manne',
        'tujhe'   => 'tanne',
        'tumne'   => 'tanne',
        'tumhe'   => 'tanne',
        'use'     => 'usne',
        'usko'    => 'usne',
        'hame'    => 'mhane',
        'hume'    => 'mhane',
        'hamara'  => 'mhara',
        'hamari'  => 'mhari',
        'hamare'  => 'mhare',
        'tumhara' => 'thara',
        'tumhari' => 'thari',
        'tumhare' => 'thare',

        // Places
        'yahan' => 'ure',
        'yaha'  => 'ure',
        'wahan' => 'ude',
        'waha'  => 'ude',
        'vahan' => 'ude',
        'sath'  => 'gelyan',
        'saath' => 'gelyan',

        // People
        'ladka'  => 'chhora',
        'ladki'  => 'chhori',
        'ladke'  => 'chhore',
        'bachche'=> 'balak',
        'bacche' => 'balak',

        // Verb forms
        'raha'  => 'rahya',
        'rahe'  => 'rahye',
        'gaya'  => 'gya',
        'hua'   => 'hoya',
        'baitha'=> 'baithya',
        'chala' => 'chalya',
        'lo'    => 'le',
        'chalo' => 'chal',
        'milne' => 'milan',
    ];

    /** Longest phrase key, in words. */
    private const MAX_PHRASE_WORDS = 4;

    /**
     * @return array{haryanvi:string,roman:?string,script:'devanagari'|'roman'}|null
     *         null when the text is not Hindi/Hinglish (e.g. English).
     */
    public static function translate(string $text, ?string $detectedLang = null): ?array
    {
        $lang = $detectedLang ?? LanguageDetector::detect($text);

        if ($lang === 'en') {
            return null;
        }

        // URLs, emails, handles and long numbers pass through untouched.
        $guard  = TextGuard::protect($text);
        $masked = $guard->maskedText;

        if (LanguageDetector::isDevanagari($masked)) {
            [$dev, $roman] = self::convertDevanagari($masked);

            return [
                'haryanvi' => $guard->restore($dev),
                'roman'    => $guard->restore($roman),
                'script'   => 'devanagari',
            ];
        }

        $roman = $guard->restore(self::convertRoman($masked));

        return ['haryanvi' => $roman, 'roman' => $roman, 'script' => 'roman'];
    }

    /* ----------------------------------------------------------------
     | Devanagari
     * -------------------------------------------------------------- */

    /** @return array{0:string,1:string} [Haryanvi Devanagari, Roman] */
    private static function convertDevanagari(string $text): array
    {
        $rules  = self::devRules();
        $tokens = self::tokenize(self::decomposeNukta($text));
        $dev    = '';
        $roman  = '';
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$value, $isWord] = $tokens[$i];

            if (!$isWord) {
                $dev   .= $value;
                $roman .= str_replace('।', '.', $value);
                continue;
            }

            $match = self::matchPhrase($tokens, $i, static fn (string $k) => $rules[$k] ?? null, false);

            if ($match !== null) {
                [$rule, $consumedTo] = $match;
                $dev   .= $rule[0];
                $roman .= $rule[1];
                $i      = $consumedTo;
                continue;
            }

            $dev   .= $value;
            $roman .= self::transliterate($value);
        }

        return [$dev, self::tidyRoman($roman)];
    }

    /* ----------------------------------------------------------------
     | Hinglish (Latin script)
     * -------------------------------------------------------------- */

    private static function convertRoman(string $text): string
    {
        $tokens = self::tokenize($text);
        $out    = '';
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$value, $isWord] = $tokens[$i];

            if (!$isWord) {
                $out .= $value;
                continue;
            }

            $match = self::matchPhrase($tokens, $i, static fn (string $k) => self::ROMAN[$k] ?? null, true);

            if ($match !== null) {
                [$rule, $consumedTo] = $match;
                $out .= self::matchCase($tokens[$i][0], $rule);
                $i    = $consumedTo;
                continue;
            }

            $out .= $value;
        }

        return $out;
    }

    /** Keep a leading capital ("Kya" -> "Ke"). */
    private static function matchCase(string $original, string $replacement): string
    {
        $first = mb_substr($original, 0, 1);

        if ($first !== mb_strtolower($first)) {
            return mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
        }

        return $replacement;
    }

    /* ----------------------------------------------------------------
     | Shared helpers
     * -------------------------------------------------------------- */

    /**
     * Split into word and non-word runs. A "word" is letters plus combining
     * marks, so Devanagari matras and viramas stay attached.
     *
     * @return list<array{0:string,1:bool}>
     */
    private static function tokenize(string $text): array
    {
        $parts = preg_split('/([\p{L}\p{M}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [[$text, false]];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $tokens[] = [$part, preg_match('/^[\p{L}\p{M}]+$/u', $part) === 1];
        }

        return $tokens;
    }

    /**
     * Try the longest phrase starting at word token $start. Words in a phrase
     * may only be separated by plain spaces.
     *
     * @param list<array{0:string,1:bool}> $tokens
     * @param callable(string):mixed       $lookup
     * @return array{0:mixed,1:int}|null [rule, index of last consumed token]
     */
    private static function matchPhrase(array $tokens, int $start, callable $lookup, bool $lowercase): ?array
    {
        $words   = [];
        $indexes = [];
        $count   = count($tokens);
        $j       = $start;

        while ($j < $count && count($words) < self::MAX_PHRASE_WORDS) {
            if (!$tokens[$j][1]) {
                break;
            }

            $words[]   = $lowercase ? mb_strtolower($tokens[$j][0]) : $tokens[$j][0];
            $indexes[] = $j;

            if ($j + 2 < $count && !$tokens[$j + 1][1] && preg_match('/^ +$/', $tokens[$j + 1][0]) === 1) {
                $j += 2;
                continue;
            }

            break;
        }

        for ($n = count($words); $n >= 1; $n--) {
            $rule = $lookup(implode(' ', array_slice($words, 0, $n)));

            if ($rule !== null) {
                return [$rule, $indexes[$n - 1]];
            }
        }

        return null;
    }

    /**
     * Precomposed nukta letters (U+0958..U+095F) and their two-codepoint
     * forms look identical but compare unequal. Decompose so input typed
     * either way matches the rules. Done by hand so the intl extension is
     * not required.
     */
    private static function decomposeNukta(string $text): string
    {
        return strtr($text, [
            "\u{0958}" => "\u{0915}\u{093C}", "\u{0959}" => "\u{0916}\u{093C}",
            "\u{095A}" => "\u{0917}\u{093C}", "\u{095B}" => "\u{091C}\u{093C}",
            "\u{095C}" => "\u{0921}\u{093C}", "\u{095D}" => "\u{0922}\u{093C}",
            "\u{095E}" => "\u{092B}\u{093C}", "\u{095F}" => "\u{092F}\u{093C}",
        ]);
    }

    /** @var array<string,array{0:string,1:string}>|null */
    private static ?array $devRules = null;

    /** @return array<string,array{0:string,1:string}> */
    private static function devRules(): array
    {
        if (self::$devRules === null) {
            self::$devRules = [];
            foreach (self::DEVANAGARI as $key => $rule) {
                self::$devRules[self::decomposeNukta($key)] = [self::decomposeNukta($rule[0]), $rule[1]];
            }
        }

        return self::$devRules;
    }

    private static function tidyRoman(string $roman): string
    {
        return preg_replace('/ {2,}/', ' ', $roman) ?? $roman;
    }

    /* ----------------------------------------------------------------
     | Devanagari -> Roman transliteration (chat-style spelling)
     * -------------------------------------------------------------- */

    private const CONSONANTS = [
        'क' => 'k', 'ख' => 'kh', 'ग' => 'g', 'घ' => 'gh', 'ङ' => 'n',
        'च' => 'ch', 'छ' => 'chh', 'ज' => 'j', 'झ' => 'jh', 'ञ' => 'n',
        'ट' => 't', 'ठ' => 'th', 'ड' => 'd', 'ढ' => 'dh', 'ण' => 'n',
        'त' => 't', 'थ' => 'th', 'द' => 'd', 'ध' => 'dh', 'न' => 'n',
        'प' => 'p', 'फ' => 'ph', 'ब' => 'b', 'भ' => 'bh', 'म' => 'm',
        'य' => 'y', 'र' => 'r', 'ल' => 'l', 'व' => 'v', 'श' => 'sh',
        'ष' => 'sh', 'स' => 's', 'ह' => 'h', 'ळ' => 'l',
        "\u{0915}\u{093C}" => 'q',  "\u{0916}\u{093C}" => 'kh', "\u{0917}\u{093C}" => 'g',
        "\u{091C}\u{093C}" => 'z',  "\u{0921}\u{093C}" => 'd',  "\u{0922}\u{093C}" => 'dh',
        "\u{092B}\u{093C}" => 'f',  "\u{092F}\u{093C}" => 'y',
    ];

    private const VOWELS = [
        'अ' => 'a', 'आ' => 'aa', 'इ' => 'i', 'ई' => 'i', 'उ' => 'u', 'ऊ' => 'u',
        'ए' => 'e', 'ऐ' => 'ai', 'ओ' => 'o', 'औ' => 'au', 'ऋ' => 'ri', 'ऑ' => 'o',
    ];

    private const MATRAS = [
        'ा' => 'a', 'ि' => 'i', 'ी' => 'i', 'ु' => 'u', 'ू' => 'u', 'े' => 'e',
        'ै' => 'ai', 'ो' => 'o', 'ौ' => 'au', 'ृ' => 'ri', 'ॉ' => 'o', 'ॅ' => 'e',
    ];

    private const VIRAMA = '्';
    private const NUKTA  = '़';

    public static function transliterate(string $word): string
    {
        if (!LanguageDetector::isDevanagari($word)) {
            return $word;
        }

        $word  = self::decomposeNukta($word);
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Build syllable units: ['c' => consonant roman|null, 'v' => vowel roman|null, 'inherent' => bool, 'suffix' => string]
        $units = [];
        $n     = count($chars);

        for ($i = 0; $i < $n; $i++) {
            $ch = $chars[$i];

            if (isset(self::CONSONANTS[$ch])) {
                $key = $ch;
                if ($i + 1 < $n && $chars[$i + 1] === self::NUKTA) {
                    if (isset(self::CONSONANTS[$ch . self::NUKTA])) {
                        $key = $ch . self::NUKTA;
                    }
                    $i++;
                }

                $unit = ['c' => self::CONSONANTS[$key], 'v' => 'a', 'inherent' => true, 'suffix' => ''];

                if ($i + 1 < $n) {
                    $next = $chars[$i + 1];

                    if ($next === self::VIRAMA) {
                        $unit['v'] = '';
                        $unit['inherent'] = false;
                        $i++;
                    } elseif (isset(self::MATRAS[$next])) {
                        $unit['v'] = self::MATRAS[$next];
                        $unit['inherent'] = false;
                        $i++;
                    }
                }

                $units[] = $unit;
                continue;
            }

            if (isset(self::VOWELS[$ch])) {
                $units[] = ['c' => null, 'v' => self::VOWELS[$ch], 'inherent' => false, 'suffix' => ''];
                continue;
            }

            if ($ch === 'ं' || $ch === 'ँ') {
                if ($units !== []) {
                    $units[count($units) - 1]['suffix'] .= 'n';
                }
                continue;
            }

            if ($ch === 'ः') {
                if ($units !== []) {
                    $units[count($units) - 1]['suffix'] .= 'h';
                }
                continue;
            }

            // Anything else (digits, stray marks) passes through.
            $units[] = ['c' => $ch, 'v' => null, 'inherent' => false, 'suffix' => ''];
        }

        $count = count($units);

        // Schwa deletion: drop the word-final inherent "a" (unless it is the
        // only syllable), then the medial one in a V C(a) C V pattern,
        // scanning right to left.
        if ($count > 1 && $units[$count - 1]['inherent'] && $units[$count - 1]['suffix'] === '') {
            $units[$count - 1]['v'] = '';
            $units[$count - 1]['inherent'] = false;
        }

        for ($i = $count - 2; $i >= 1; $i--) {
            $u = $units[$i];

            if (!$u['inherent'] || $u['suffix'] !== '') {
                continue;
            }

            $prevHasVowel = ($units[$i - 1]['v'] ?? '') !== '';
            $next         = $units[$i + 1];
            $nextIsCv     = $next['c'] !== null && ($next['v'] ?? '') !== '';

            if ($prevHasVowel && $nextIsCv) {
                $units[$i]['v'] = '';
                $units[$i]['inherent'] = false;
            }
        }

        $out = '';
        foreach ($units as $u) {
            $out .= ($u['c'] ?? '') . ($u['v'] ?? '') . $u['suffix'];
        }

        return $out;
    }
}
