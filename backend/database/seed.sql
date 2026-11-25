-- =====================================================================
--  Haryanvi AI Translator — seed data
-- ---------------------------------------------------------------------
--  Load AFTER schema.sql:
--      mysql -u USER -p DBNAME < schema.sql
--      mysql -u USER -p DBNAME < seed.sql
--
--  Contents
--    * 5 regional dialect profiles with linguistic prompt guidance
--    * 6 tone presets
--    * ~130 curated vocabulary / idiom / grammar entries
--    * application settings
--    * one bootstrap admin account (password change forced on first login)
--
--  NOTE ON THE ADMIN ACCOUNT
--    username : admin
--    password : ChangeMe!2026     <-- change immediately after first login
--    The row below stores only a bcrypt hash and sets
--    must_change_password = 1, so the dashboard refuses to do anything
--    else until the password has been rotated.
--    Prefer `php bin/create-admin.php` and then delete this account.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
-- Dialect profiles
-- ---------------------------------------------------------------------
-- `prompt_notes` is injected verbatim into the AI system prompt, so it is
-- written as instructions for a translator, not as prose documentation.
-- =====================================================================
INSERT INTO `dialects`
  (`code`, `name_en`, `name_hi`, `region_label`, `description`, `prompt_notes`, `is_active`, `is_default`, `sort_order`)
VALUES
(
  'general',
  'General Haryanvi',
  'सामान्य हरियाणवी',
  'All Haryana',
  'Neutral, widely understood Haryanvi. Safe default when the user has not picked a home region.',
  'Use neutral Haryanvi (Bangru) that is understood across all of Haryana.\n- Copula: हूँ→सूं, है→सै, हैं→सैं. Example: "मैं ठीक हूँ" → "मैं ठीक सूं".\n- Negation: नहीं→ना; "नहीं है"/"नहीं हैं"→कोन्या. Example: "पता नहीं" → "बेरा ना".\n- Core function words: क्या→के, क्यों→क्यूं, कैसे→किस्यां, कहाँ→कित, कब→कद, कितना→कित्ता, अब→इब, फिर→फेर, और→अर.\n- Verb stems take ण instead of न in the infinitive: जाना→जाणा, खाना→खाणा, करना→करणा, आना→आणा, सुनना→सुणना.\n- Progressive रहा/रही/रहे → रह्या/रही/रहे. Example: "मैं जा रहा हूँ" → "मैं जा रह्या सूं".\n- Perfective गया→ग्या, हुआ→होया, दिया→दिया/दे दिया→दे द्या.\n- Oblique pronouns: मुझे→मन्ने, तुझे→तन्ने, उसे→उसनै.\n- Keep it clean and conversational. Avoid heavy district-specific forms here.',
  1, 1, 10
),
(
  'rohtak_jhajjar',
  'Rohtak & Jhajjar',
  'रोहतक व झज्जर',
  'Rohtak, Jhajjar, Bahadurgarh belt',
  'Core Deswali/Bangru Haryanvi — the most recognisable form of the language.',
  'Use core Deswali (Bangru) Haryanvi as spoken in the Rohtak–Jhajjar belt. This is the most prototypical Haryanvi; lean into it confidently.\n- Copula सै/सूं/सैं is used consistently; do not fall back to Hindi है.\n- कोन्या is the natural negative existential: "मेरे पास पैसे नहीं हैं" → "मेरे धोरै पईसे कोन्या".\n- बेरा = पता/मालूम. "मुझे नहीं पता" → "मन्ने बेरा ना".\n- घणा = बहुत (घणा बढ़िया, घणा काम).\n- Locatives: यहाँ→उरै, वहाँ→उड़े, कहाँ→कित, किधर→किम्मै.\n- के लिए→खातर, साथ→गेल्यां, पास→धोरै.\n- छोरा/छोरी for boy/girl in informal speech; ताऊ/भाई as friendly address.\n- Strong ड़/ण articulation; use -या perfective endings (चल्या, बैठ्या, होया).\n- Typical sentence shape: "तू कित सै?" / "के कर रह्या सै?" / "इब चाल".',
  1, 0, 20
),
(
  'hisar_bhiwani',
  'Hisar & Bhiwani',
  'हिसार व भिवानी',
  'Hisar, Bhiwani, Fatehabad, Charkhi Dadri',
  'Western Haryana, on the Bagri transition belt. Slightly Rajasthani-leaning morphology with Haryanvi vocabulary.',
  'Use western Haryanvi from the Hisar–Bhiwani belt, which sits on the Bagri transition zone.\n- First person मैं is often म्हैं; हूँ may surface as स्यूं alongside सूं.\n- Possessives: हमारा→म्हारा, तुम्हारा→थारा, तुम→थे/तम in plural or polite use.\n- कहाँ→कठै (alongside कित); कब→जद/कद; ऐसा→असा/इसा.\n- Vowels are more open and drawn out; perfective -यो forms occur (गयो/ग्यो) but keep ग्या as the default so it still reads as Haryanvi.\n- IMPORTANT: morphology may lean Bagri, but the LEXICON must stay Haryanvi. Do not import Marwari/Rajasthani words that a Haryana speaker would not use.\n- Keep बेरा, घणा, कोन्या, छोरा/छोरी — these are shared with the Deswali core.',
  1, 0, 30
),
(
  'sonipat_panipat',
  'Sonipat & Panipat',
  'सोनीपत व पानीपत',
  'Sonipat, Panipat, Samalkha, Ganaur (upper Yamuna / Khadar)',
  'Eastern Haryana near the Delhi–Yamuna corridor. Softer, Khari Boli-adjacent Haryanvi.',
  'Use eastern Haryanvi from the Sonipat–Panipat (Khadar / upper Yamuna) belt. This variety is closer to Khari Boli and sounds softer and more urban.\n- सै is used, but है is also natural in mixed urban speech — a light touch is correct here. Do not over-ruralise.\n- Prefer ना over कोन्या for ordinary negation; कोन्या is used but less often.\n- Function words stay Haryanvi (के, क्यूं, कित, कद, इब, फेर) while sentence rhythm stays closer to standard Hindi.\n- Modern/urban loanwords (ऑफिस, मोबाइल, बस, ट्रेन, कॉलेज) are perfectly natural; keep them rather than inventing rustic substitutes.\n- Good for messages to city friends, colleagues and family in the NCR belt.',
  1, 0, 40
),
(
  'jind_kaithal',
  'Jind & Kaithal',
  'जींद व कैथल',
  'Jind, Kaithal, Narwana, Kalayat',
  'Northern Haryana in contact with Punjabi. Deswali grammar with a distinct northern cadence.',
  'Use northern Haryanvi from the Jind–Kaithal belt. Grammar follows the Deswali core, with a northern cadence shaped by contact with Punjabi.\n- Keep सै/सूं/सैं, कोन्या, बेरा, घणा, कित, कद, इब, फेर — the Deswali backbone is intact.\n- The Punjabi influence is in rhythm, intonation and emphasis, NOT in vocabulary.\n- CRITICAL: do NOT insert Punjabi words (no ਕੀ/की for क्या, no हैगा, no तुसी, no असी, no "ਜੀ"-style tags). The output must read as Haryanvi to a Haryana speaker.\n- Slightly more emphatic sentence-final particles are natural: "चाल ना", "सुण ले".',
  1, 0, 50
);

-- =====================================================================
-- Tone presets
-- =====================================================================
INSERT INTO `tone_presets`
  (`code`, `name_en`, `name_hi`, `description`, `prompt_instruction`, `is_active`, `is_default`, `sort_order`)
VALUES
('natural',   'Natural Conversational', 'सहज बातचीत',  'Everyday spoken Haryanvi. The safe default.',
 'Write the way people actually speak in everyday conversation: relaxed, clear and unforced. Neither formal nor exaggerated.', 1, 1, 10),

('youth',     'Modern Youth',           'आजकल के छोरे', 'How younger speakers text today — Haryanvi mixed with modern words.',
 'Write like a young Haryanvi speaker texting a friend today. Short, punchy sentences. Keep modern English/Hindi words that young people genuinely use (phone, plan, exam, party) instead of forcing rural substitutes. Do not add slang that is not already implied by the message.', 1, 0, 20),

('village',   'Traditional Village',    'गाम की बोली',  'Older, rooted village speech.',
 'Write in older, rooted village Haryanvi. Favour traditional vocabulary and full regional forms. Avoid English loanwords unless the source text contains a proper noun or technical term that must be preserved.', 1, 0, 30),

('respectful','Respectful',             'आदर भाव',      'For elders, teachers, officials and strangers.',
 'Use respectful address throughout. Prefer आप over तू, and plural/honorific verb agreement. Keep the warmth of Haryanvi while sounding polite and well-mannered. Never sound sarcastic.', 1, 0, 40),

('friendly',  'Friendly',               'यार-दोस्ती',   'Warm, close, for friends and siblings.',
 'Write warmly, the way you would speak to a close friend or sibling. Informal तू is appropriate. Friendly address terms (भाई, यार) may be used only if the source message already has that familiar tone.', 1, 0, 50),

('funny',     'Funny',                  'मजाकिया',      'Light and playful — keeps the original meaning intact.',
 'Add a light, playful edge while keeping the original meaning fully intact. Humour must come from natural Haryanvi phrasing and rhythm, not from mocking the dialect or its speakers. Never turn the message into a caricature, and never add jokes that change what the sender meant.', 1, 0, 60);

-- =====================================================================
-- Vocabulary, idioms and grammar notes
-- ---------------------------------------------------------------------
-- dialect_id = NULL  => pan-Haryanvi (applies to every profile)
-- These rows are supplied to the model as *reference*; the engine never
-- performs blind word-for-word substitution with them.
-- =====================================================================

-- ---- Grammar backbone (pan-Haryanvi) --------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'grammar', 'hi', 'हूँ',        'सूं',    'soon',    'neutral',   'First person singular copula.',            'मैं ठीक हूँ',            'मैं ठीक सूं',             90),
(NULL, 'grammar', 'hi', 'है',         'सै',     'sai',     'neutral',   'Third person singular copula.',            'वो घर पर है',            'वो घर पै सै',             90),
(NULL, 'grammar', 'hi', 'हैं',        'सैं',    'sain',    'neutral',   'Plural copula.',                           'वे आ रहे हैं',           'वे आ रहे सैं',            90),
(NULL, 'grammar', 'hi', 'नहीं है',    'कोन्या', 'konya',   'neutral',   'Negative existential — very characteristic.', 'घर में कोई नहीं है',   'घर मैं कोई कोन्या',       90),
(NULL, 'grammar', 'hi', 'नहीं',       'ना',     'na',      'neutral',   'Plain negation.',                          'मैं नहीं जाऊँगा',        'मैं ना जाऊंगा',           85),
(NULL, 'grammar', 'hi', 'रहा है',     'रह्या सै','rahya sai','neutral',  'Progressive aspect.',                      'वो खा रहा है',           'वो खा रह्या सै',          85),
(NULL, 'grammar', 'hi', 'गया',        'ग्या',   'gya',     'neutral',   'Perfective of जाना.',                      'वो चला गया',             'वो चल्या ग्या',           85),
(NULL, 'grammar', 'hi', 'हुआ',        'होया',   'hoya',    'neutral',   'Perfective of होना.',                      'क्या हुआ',               'के होया',                 85),
(NULL, 'grammar', 'hi', 'मुझे',       'मन्ने',  'manne',   'neutral',   'Oblique first person.',                    'मुझे पता नहीं',          'मन्ने बेरा ना',           85),
(NULL, 'grammar', 'hi', 'तुझे',       'तन्ने',  'tanne',   'informal',  'Oblique second person, informal.',         'तुझे क्या चाहिए',        'तन्ने के चाहिए',          80),
(NULL, 'grammar', 'hi', 'जाना',       'जाणा',   'jaana',   'neutral',   'Infinitive -ना becomes -णा.',              'मुझे जाना है',           'मन्ने जाणा सै',           80),
(NULL, 'grammar', 'hi', 'करना',       'करणा',   'karna',   'neutral',   'Infinitive -ना becomes -णा.',              'काम करना है',            'काम करणा सै',             80),
(NULL, 'grammar', 'hi', 'खाना',       'खाणा',   'khaana',  'neutral',   'Infinitive -ना becomes -णा.',              'खाना खा लिया',           'खाणा खा लिया',            80),
(NULL, 'grammar', 'hi', 'सुनना',      'सुणना',  'sunna',   'neutral',   'न becomes ण in verb stems.',               'मेरी बात सुनो',          'मेरी बात सुण',            80);

-- ---- Core function words (pan-Haryanvi) -----------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'word', 'hi', 'क्या',    'के',      'ke',      'neutral', 'Interrogative "what".',        'क्या कर रहे हो',   'के कर रह्या सै',   95),
(NULL, 'word', 'hi', 'क्यों',   'क्यूं',   'kyun',    'neutral', 'Interrogative "why".',         'क्यों नहीं आया',   'क्यूं ना आया',     85),
(NULL, 'word', 'hi', 'कैसे',    'किस्यां', 'kisyan',  'neutral', 'Interrogative "how".',         'यह कैसे हुआ',      'ये किस्यां होया',  85),
(NULL, 'word', 'hi', 'कहाँ',    'कित',     'kit',     'neutral', 'Interrogative "where".',       'तुम कहाँ हो',      'तू कित सै',        90),
(NULL, 'word', 'hi', 'कब',      'कद',      'kad',     'neutral', 'Interrogative "when".',        'कब आओगे',          'कद आवैगा',         85),
(NULL, 'word', 'hi', 'कितना',   'कित्ता',  'kitta',   'neutral', 'Quantity question.',           'कितना पैसा लगेगा', 'कित्ता पईसा लगैगा',80),
(NULL, 'word', 'hi', 'अब',      'इब',      'ib',      'neutral', 'Now.',                         'अब चलो',           'इब चाल',           90),
(NULL, 'word', 'hi', 'फिर',     'फेर',     'pher',    'neutral', 'Then / again.',                'फिर क्या हुआ',     'फेर के होया',      85),
(NULL, 'word', 'hi', 'और',      'अर',      'ar',      'neutral', 'Conjunction "and".',           'तुम और मैं',       'तू अर मैं',        75),
(NULL, 'word', 'hi', 'यहाँ',    'उरै',     'urai',    'neutral', 'Here.',                        'यहाँ आओ',          'उरै आ',            80),
(NULL, 'word', 'hi', 'वहाँ',    'उड़े',    'ude',     'neutral', 'There.',                       'वहाँ मत जाओ',      'उड़े मतना जाइये',  80),
(NULL, 'word', 'hi', 'ऐसा',     'इस्या',   'isya',    'neutral', 'Like this / such.',            'ऐसा मत करो',       'इस्या मतना कर',    75),
(NULL, 'word', 'hi', 'के लिए',  'खातर',    'khaatar', 'neutral', 'Purposive postposition.',      'तुम्हारे लिए लाया','तेरे खातर ल्याया', 75),
(NULL, 'word', 'hi', 'साथ',     'गेल्यां', 'gelyaan', 'neutral', 'With / along with.',           'मेरे साथ चलो',     'मेरे गेल्यां चाल',  75),
(NULL, 'word', 'hi', 'पास',     'धोरै',    'dhorai',  'neutral', 'Near / in possession of.',     'मेरे पास नहीं है', 'मेरे धोरै कोन्या', 75),
(NULL, 'word', 'hi', 'बहुत',    'घणा',     'ghana',   'neutral', 'Very / a lot. Extremely common.','बहुत अच्छा',      'घणा बढ़िया',       95),
(NULL, 'word', 'hi', 'थोड़ा',   'जरा',     'jara',    'neutral', 'A little.',                    'थोड़ा रुको',       'जरा रुक ज्या',     70),
(NULL, 'word', 'hi', 'जल्दी',   'तावला',   'taawla',  'neutral', 'Quickly / in a hurry.',        'जल्दी आओ',         'तावला आ ज्या',     70),
(NULL, 'word', 'hi', 'बिल्कुल', 'एकदम',    'ekdam',   'neutral', 'Absolutely.',                  'बिल्कुल ठीक',      'एकदम ठीक',         65),
(NULL, 'word', 'hi', 'शायद',    'हो सकै',  'ho sakai','neutral', 'Maybe / possibly.',            'शायद आ जाऊँ',      'हो सकै आ ज्याऊं',  65);

-- ---- Everyday nouns and people (pan-Haryanvi) -----------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'word', 'hi', 'पता',      'बेरा',    'bera',    'neutral',  'Knowledge / information. "बेरा ना" = don''t know.', 'मुझे नहीं पता', 'मन्ने बेरा ना', 95),
(NULL, 'word', 'hi', 'लड़का',    'छोरा',    'chhora',  'informal', 'Boy. Informal, very common.',      'वो लड़का कौन है',   'वो छोरा कौण सै',   85),
(NULL, 'word', 'hi', 'लड़की',    'छोरी',    'chhori',  'informal', 'Girl. Informal, very common.',     'वो लड़की मेरी बहन है','वा छोरी मेरी बहण सै',85),
(NULL, 'word', 'hi', 'पानी',     'पाणी',    'paani',   'neutral',  'Water (न→ण).',                     'पानी पिला दो',      'पाणी पिला दे',     80),
(NULL, 'word', 'hi', 'रोटी',     'रोटी',    'roti',    'neutral',  'Also stands in for "a meal".',     'खाना खाया',         'रोटी खाई',         75),
(NULL, 'word', 'hi', 'दूध',      'दूद',     'dood',    'neutral',  'Milk.',                            'दूध ले आओ',         'दूद ले आ',         70),
(NULL, 'word', 'hi', 'पैसा',     'पईसा',    'paisa',   'neutral',  'Money.',                           'पैसे भेज दो',       'पईसे भेज दे',      75),
(NULL, 'word', 'hi', 'समय',      'बखत',     'bakhat',  'neutral',  'Time.',                            'समय नहीं है',       'बखत कोन्या',       75),
(NULL, 'word', 'hi', 'सुबह',     'तड़के',   'tadke',   'neutral',  'Early morning.',                   'सुबह निकलना है',    'तड़के चालणा सै',   70),
(NULL, 'word', 'hi', 'कल',       'काल',     'kaal',    'neutral',  'Yesterday or tomorrow, per context.','कल मिलते हैं',     'काल मिलांगे',      75),
(NULL, 'word', 'hi', 'परसों',    'परसूं',   'parsoon', 'neutral',  'Day before/after tomorrow.',       'परसों आऊँगा',       'परसूं आऊंगा',      60),
(NULL, 'word', 'hi', 'बड़ा',     'बड्डा',   'badda',   'neutral',  'Big.',                             'बड़ा घर',           'बड्डा घर',         70),
(NULL, 'word', 'hi', 'छोटा',     'छोट्टा',  'chhotta', 'neutral',  'Small.',                           'छोटा भाई',          'छोट्टा भाई',       70),
(NULL, 'word', 'hi', 'अच्छा',    'बढ़िया',  'badhiya', 'neutral',  'Good / fine.',                     'अच्छा लगा',         'बढ़िया लाग्या',    80),
(NULL, 'word', 'hi', 'सच',       'साच',     'saach',   'neutral',  'Truth.',                           'सच बताओ',           'साच बता',          65),
(NULL, 'word', 'hi', 'दोस्त',    'यार',     'yaar',    'informal', 'Friend. Informal address.',        'मेरा दोस्त है',     'मेरा यार सै',      75),
(NULL, 'word', 'hi', 'माँ',      'अम्मा',   'amma',    'neutral',  'Mother (address form).',           'माँ ने बुलाया है',  'अम्मा नै बुलाया सै',70),
(NULL, 'word', 'hi', 'पिता',     'बाप',     'baap',    'neutral',  'Father. Not rude in Haryanvi.',    'पिता जी आए हैं',    'बाबू जी आए सैं',   65),
(NULL, 'word', 'hi', 'भैंस',     'भैंस',    'bhains',  'neutral',  'Buffalo. Rural staple noun.',      'भैंस का दूध',       'भैंस का दूद',      55),
(NULL, 'word', 'hi', 'गाँव',     'गाम',     'gaam',    'neutral',  'Village.',                         'गाँव जा रहा हूँ',   'गाम जा रह्या सूं', 80);

-- ---- Common conversational phrases ----------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'phrase', 'hi', 'नमस्ते',             'राम राम',             'raam raam',            'respectful','Standard greeting, any time of day.', 'नमस्ते भाई साहब', 'राम राम भाई',      95),
(NULL, 'phrase', 'hi', 'कैसे हो',            'के हाल सै',           'ke haal sai',          'neutral',  'How are you.',                        'कैसे हो भाई',     'के हाल सै भाई',    95),
(NULL, 'phrase', 'hi', 'क्या कर रहे हो',     'के कर रह्या सै',      'ke kar rahya sai',     'informal', 'What are you doing.',                 'क्या कर रहे हो',  'के कर रह्या सै',   95),
(NULL, 'phrase', 'hi', 'मैं ठीक हूँ',        'मैं ठीक सूं',         'main theek soon',      'neutral',  'I am fine.',                          'मैं ठीक हूँ',     'मैं ठीक सूं',      90),
(NULL, 'phrase', 'hi', 'कोई बात नहीं',       'कोई बात ना',          'koi baat na',          'neutral',  'No problem / never mind.',            'कोई बात नहीं',    'कोई बात ना',       85),
(NULL, 'phrase', 'hi', 'मुझे नहीं पता',      'मन्ने बेरा ना',       'manne bera na',        'neutral',  'I do not know.',                      'मुझे नहीं पता',   'मन्ने बेरा ना',    90),
(NULL, 'phrase', 'hi', 'ठीक है',             'ठीक सै',              'theek sai',            'neutral',  'OK / agreed.',                        'ठीक है भाई',      'ठीक सै भाई',       85),
(NULL, 'phrase', 'hi', 'चलो',                'चाल',                 'chaal',                'informal', 'Let us go.',                          'चलो चलें',        'चाल चालां',        80),
(NULL, 'phrase', 'hi', 'सुनो',               'सुण',                 'sun',                  'informal', 'Listen.',                             'सुनो भाई',        'सुण भाई',          80),
(NULL, 'phrase', 'hi', 'बैठो',               'बैठ ज्या',            'baith jya',            'informal', 'Sit down.',                           'बैठो यहाँ',       'उरै बैठ ज्या',     75),
(NULL, 'phrase', 'hi', 'क्या बात है',        'के बात सै',           'ke baat sai',          'neutral',  'What is the matter / nice!',          'क्या बात है',     'के बात सै',        80),
(NULL, 'phrase', 'hi', 'मैं आ रहा हूँ',      'मैं आ रह्या सूं',     'main aa rahya soon',   'neutral',  'I am coming.',                        'मैं आ रहा हूँ',   'मैं आ रह्या सूं',  90),
(NULL, 'phrase', 'hi', 'काम हो गया',         'काम हो ग्या',         'kaam ho gya',          'neutral',  'The work is done.',                   'काम हो गया',      'काम हो ग्या',      85),
(NULL, 'phrase', 'hi', 'फोन करो',            'फोन कर ले',           'phone kar le',         'neutral',  'Make a call.',                        'मुझे फोन करो',    'मन्ने फोन कर ले',  75),
(NULL, 'phrase', 'hi', 'खाना खा लिया',       'रोटी खा ली',          'roti kha li',          'neutral',  'Have eaten.',                         'खाना खा लिया',    'रोटी खा ली',       75),
(NULL, 'phrase', 'hi', 'थोड़ी देर में',      'जरा सी देर मैं',      'jara si der main',     'neutral',  'In a little while.',                  'थोड़ी देर में आता हूँ','जरा सी देर मैं आऊं सूं',70),
(NULL, 'phrase', 'hi', 'मत करो',             'मतना कर',             'matna kar',            'informal', 'Do not do it.',                       'ऐसा मत करो',      'इस्या मतना कर',    75),
(NULL, 'phrase', 'hi', 'कहाँ जा रहे हो',     'कित जा रह्या सै',     'kit ja rahya sai',     'informal', 'Where are you going.',                'कहाँ जा रहे हो',  'कित जा रह्या सै',  85),
(NULL, 'phrase', 'hi', 'मिलते हैं',          'मिलांगे',             'milaange',             'neutral',  'See you / we will meet.',             'कल मिलते हैं',    'काल मिलांगे',      75),
(NULL, 'phrase', 'hi', 'धन्यवाद',            'घणी मेहरबानी',        'ghani meharbani',      'respectful','Thanks. Warmer than "धन्यवाद".',      'आपका धन्यवाद',    'आपकी घणी मेहरबानी',75),
(NULL, 'phrase', 'hi', 'माफ़ करना',          'माफ कर दिये',         'maaf kar diye',        'respectful','Apology.',                            'माफ़ करना भाई',   'माफ कर दिये भाई',  65),
(NULL, 'phrase', 'hi', 'जरूर',               'जरूर',                'zaroor',               'neutral',  'Certainly. Unchanged.',               'जरूर आऊँगा',      'जरूर आऊंगा',       60);

-- ---- English → Haryanvi (direct, for EN source text) ----------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'phrase', 'en', 'how are you',        'के हाल सै',           'ke haal sai',          'neutral',  'Greeting question.',          'How are you brother?',   'के हाल सै भाई?',        90),
(NULL, 'phrase', 'en', 'what are you doing', 'के कर रह्या सै',      'ke kar rahya sai',     'informal', 'Common opener.',              'What are you doing?',    'के कर रह्या सै?',       90),
(NULL, 'phrase', 'en', 'i am fine',          'मैं ठीक सूं',         'main theek soon',      'neutral',  'Standard reply.',             'I am fine, thanks',      'मैं ठीक सूं, घणी मेहरबानी',85),
(NULL, 'phrase', 'en', 'i am coming',        'मैं आ रह्या सूं',     'main aa rahya soon',   'neutral',  'Movement statement.',         'I am coming now',        'मैं इब आ रह्या सूं',    85),
(NULL, 'phrase', 'en', 'where are you',      'तू कित सै',           'tu kit sai',           'informal', 'Location question.',          'Where are you?',         'तू कित सै?',            85),
(NULL, 'phrase', 'en', 'call me',            'मन्ने फोन कर ले',     'manne phone kar le',   'neutral',  'Request a call.',             'Call me later',          'बाद मैं मन्ने फोन कर ले',75),
(NULL, 'phrase', 'en', 'i will come',        'मैं आ ज्याऊंगा',      'main aa jyaunga',      'neutral',  'Future intent.',              'I will come tomorrow',   'मैं काल आ ज्याऊंगा',    80),
(NULL, 'phrase', 'en', 'see you tomorrow',   'काल मिलांगे',         'kaal milaange',        'neutral',  'Parting line.',               'See you tomorrow',       'काल मिलांगे',           75),
(NULL, 'phrase', 'en', 'no problem',         'कोई बात ना',          'koi baat na',          'neutral',  'Reassurance.',                'No problem at all',      'कोई बात ना',            75),
(NULL, 'phrase', 'en', 'i do not know',      'मन्ने बेरा ना',       'manne bera na',        'neutral',  'Lack of knowledge.',          'I do not know',          'मन्ने बेरा ना',         85),
(NULL, 'phrase', 'en', 'very good',          'घणा बढ़िया',          'ghana badhiya',        'neutral',  'Praise.',                     'Very good work',         'घणा बढ़िया काम',        80),
(NULL, 'phrase', 'en', 'thank you',          'घणी मेहरबानी',        'ghani meharbani',      'respectful','Thanks.',                     'Thank you so much',      'आपकी घणी मेहरबानी',     75),
(NULL, 'phrase', 'en', 'please listen',      'जरा सुण ले',          'jara sun le',          'neutral',  'Polite attention request.',   'Please listen to me',    'जरा मेरी बात सुण ले',   65),
(NULL, 'phrase', 'en', 'the work is done',   'काम हो ग्या',         'kaam ho gya',          'neutral',  'Completion.',                 'The work is done',       'काम हो ग्या',           75);

-- ---- Hinglish → Haryanvi --------------------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'phrase', 'hinglish', 'kya kar raha hai',  'के कर रह्या सै',   'ke kar rahya sai',  'informal', 'Roman Hindi input.',           'bhai kya kar raha hai',   'भाई के कर रह्या सै',     85),
(NULL, 'phrase', 'hinglish', 'kaise ho',          'के हाल सै',        'ke haal sai',       'neutral',  'Roman Hindi input.',           'kaise ho yaar',           'के हाल सै यार',          85),
(NULL, 'phrase', 'hinglish', 'mujhe nahi pata',   'मन्ने बेरा ना',    'manne bera na',     'neutral',  'Roman Hindi input.',           'mujhe nahi pata',         'मन्ने बेरा ना',          80),
(NULL, 'phrase', 'hinglish', 'main aa raha hu',   'मैं आ रह्या सूं',  'main aa rahya soon','neutral',  'Roman Hindi input.',           'main aa raha hu',         'मैं आ रह्या सूं',        80),
(NULL, 'phrase', 'hinglish', 'thik hai',          'ठीक सै',           'theek sai',         'neutral',  'Roman Hindi input.',           'thik hai bhai',           'ठीक सै भाई',             75),
(NULL, 'phrase', 'hinglish', 'bahut badhiya',     'घणा बढ़िया',       'ghana badhiya',     'neutral',  'Roman Hindi input.',           'bahut badhiya kaam',      'घणा बढ़िया काम',         75),
(NULL, 'phrase', 'hinglish', 'kahan ho',          'कित सै',           'kit sai',           'informal', 'Roman Hindi input.',           'tu kahan ho',             'तू कित सै',              75),
(NULL, 'phrase', 'hinglish', 'kal milte hai',     'काल मिलांगे',      'kaal milaange',     'neutral',  'Roman Hindi input.',           'kal milte hai',           'काल मिलांगे',            70);

-- ---- Idioms and expressions -----------------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
VALUES
(NULL, 'idiom', 'hi', 'बहुत बढ़िया काम किया',   'घणा बढ़िया काम करया',      'ghana badhiya kaam karya','neutral', 'Standard praise.',                   'तुमने बहुत बढ़िया काम किया','तन्ने घणा बढ़िया काम करया',70),
(NULL, 'idiom', 'hi', 'कुछ नहीं हुआ',           'कुछ कोन्या होया',          'kuchh konya hoya',       'neutral',  'Nothing happened.',                  'चिंता मत करो, कुछ नहीं हुआ','फिकर मतना कर, कुछ कोन्या होया',65),
(NULL, 'idiom', 'hi', 'चिंता मत करो',           'फिकर मतना कर',             'fikar matna kar',        'neutral',  'Reassurance.',                       'चिंता मत करो',             'फिकर मतना कर',             70),
(NULL, 'idiom', 'hi', 'मन लगाकर काम करो',       'दिल लगा कै काम कर',        'dil laga kai kaam kar',  'neutral',  'Encouragement.',                     'मन लगाकर काम करो',         'दिल लगा कै काम कर',        60),
(NULL, 'idiom', 'hi', 'बात बन गई',              'बात बण गई',                'baat ban gayi',          'neutral',  'It worked out.',                     'बात बन गई भाई',            'बात बण गई भाई',            60),
(NULL, 'idiom', 'hi', 'हिम्मत रखो',             'हिम्मत राख',               'himmat raakh',           'neutral',  'Stay strong.',                       'हिम्मत रखो भाई',           'हिम्मत राख भाई',           60),
(NULL, 'idiom', 'hi', 'जैसा चल रहा है',         'ज्यूं चाल रह्या सै',       'jyun chaal rahya sai',   'neutral',  'As things are going.',               'जैसा चल रहा है चलने दो',   'ज्यूं चाल रह्या सै चालण दे',55),
(NULL, 'slang', 'hi', 'कमाल कर दिया',           'धमाका कर दिया',            'dhamaka kar diya',       'informal', 'Did something impressive.',          'तूने कमाल कर दिया',        'तन्ने धमाका कर दिया',      55),
(NULL, 'slang', 'hi', 'मजा आ गया',              'मजा आ ग्या',               'maza aa gya',            'informal', 'Enjoyed it.',                        'बहुत मजा आ गया',           'घणा मजा आ ग्या',           65),
(NULL, 'slang', 'hi', 'बकवास',                  'बकवास',                    'bakwaas',                'informal', 'Nonsense. Unchanged.',               'ये बकवास है',              'ये बकवास सै',              50);

-- ---- Rohtak & Jhajjar specific --------------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
SELECT d.id, v.entry_type, v.source_lang, v.source_term, v.haryanvi_term, v.roman_term, v.register, v.usage_note, v.example_source, v.example_haryanvi, v.weight
FROM `dialects` d
JOIN (
  SELECT 'word'   AS entry_type, 'hi' AS source_lang, 'किधर'      AS source_term, 'किम्मै'   AS haryanvi_term, 'kimmai'    AS roman_term, 'neutral'  AS register, 'Which direction.'                     AS usage_note, 'किधर जा रहे हो'   AS example_source, 'किम्मै जा रह्या सै'   AS example_haryanvi, 60 AS weight
  UNION ALL SELECT 'word','hi','चाचा','ताऊ','taau','informal','Friendly address for an older man.','सुनो चाचा','सुण ताऊ',60
  UNION ALL SELECT 'phrase','hi','बहुत दिन हो गए','घणे दिन हो गे','ghane din ho ge','neutral','It has been a long time.','बहुत दिन हो गए मिले नहीं','घणे दिन हो गे मिले कोन्या',60
  UNION ALL SELECT 'phrase','hi','मेरे पास पैसे नहीं हैं','मेरे धोरै पईसे कोन्या','mere dhorai paise konya','neutral','Possession negative.','मेरे पास पैसे नहीं हैं','मेरे धोरै पईसे कोन्या',65
  UNION ALL SELECT 'grammar','hi','बैठा','बैठ्या','baithya','neutral','Perfective -या ending.','वो बैठा है','वो बैठ्या सै',60
) v
WHERE d.code = 'rohtak_jhajjar';

-- ---- Hisar & Bhiwani specific ---------------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
SELECT d.id, v.entry_type, v.source_lang, v.source_term, v.haryanvi_term, v.roman_term, v.register, v.usage_note, v.example_source, v.example_haryanvi, v.weight
FROM `dialects` d
JOIN (
  SELECT 'grammar' AS entry_type, 'hi' AS source_lang, 'मैं' AS source_term, 'म्हैं' AS haryanvi_term, 'mhain' AS roman_term, 'neutral' AS register, 'Western first person form.' AS usage_note, 'मैं जाऊँगा' AS example_source, 'म्हैं जाऊंगा' AS example_haryanvi, 70 AS weight
  UNION ALL SELECT 'grammar','hi','तुम्हारा','थारा','thaara','neutral','Western second person possessive.','तुम्हारा नाम क्या है','थारा नाम के सै',70
  UNION ALL SELECT 'grammar','hi','हमारा','म्हारा','mhaara','neutral','Western first person plural possessive.','हमारा गाँव','म्हारा गाम',70
  UNION ALL SELECT 'word','hi','कहाँ','कठै','kathai','neutral','Western "where", alongside कित.','कहाँ हो','कठै सै',65
  UNION ALL SELECT 'word','hi','जब','जद','jad','neutral','When (relative).','जब आओ तो बताओ','जद आवै तो बता दिये',60
) v
WHERE d.code = 'hisar_bhiwani';

-- ---- Sonipat & Panipat specific -------------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
SELECT d.id, v.entry_type, v.source_lang, v.source_term, v.haryanvi_term, v.roman_term, v.register, v.usage_note, v.example_source, v.example_haryanvi, v.weight
FROM `dialects` d
JOIN (
  SELECT 'grammar' AS entry_type, 'hi' AS source_lang, 'नहीं है' AS source_term, 'ना सै' AS haryanvi_term, 'na sai' AS roman_term, 'neutral' AS register, 'Softer negative; कोन्या used less often here.' AS usage_note, 'घर पर नहीं है' AS example_source, 'घर पै ना सै' AS example_haryanvi, 65 AS weight
  UNION ALL SELECT 'word','en','office','ऑफिस','office','neutral','Urban loanword — keep as is.','I am going to office','मैं ऑफिस जा रह्या सूं',60
  UNION ALL SELECT 'word','en','train','ट्रेन','train','neutral','Urban loanword — keep as is.','The train is late','ट्रेन लेट सै',55
  UNION ALL SELECT 'phrase','hi','दिल्ली जाना है','दिल्ली जाणा सै','dilli jaana sai','neutral','NCR-belt routine.','मुझे दिल्ली जाना है','मन्ने दिल्ली जाणा सै',60
) v
WHERE d.code = 'sonipat_panipat';

-- ---- Jind & Kaithal specific ----------------------------------------
INSERT INTO `dialect_vocabulary`
  (`dialect_id`, `entry_type`, `source_lang`, `source_term`, `haryanvi_term`, `roman_term`, `register`, `usage_note`, `example_source`, `example_haryanvi`, `weight`)
SELECT d.id, v.entry_type, v.source_lang, v.source_term, v.haryanvi_term, v.roman_term, v.register, v.usage_note, v.example_source, v.example_haryanvi, v.weight
FROM `dialects` d
JOIN (
  SELECT 'phrase' AS entry_type, 'hi' AS source_lang, 'चलो ना' AS source_term, 'चाल ना' AS haryanvi_term, 'chaal na' AS roman_term, 'informal' AS register, 'Emphatic sentence-final particle is natural here.' AS usage_note, 'चलो ना भाई' AS example_source, 'चाल ना भाई' AS example_haryanvi, 60 AS weight
  UNION ALL SELECT 'phrase','hi','सुन लो','सुण ले','sun le','informal','Emphatic imperative.','मेरी बात सुन लो','मेरी बात सुण ले',60
  UNION ALL SELECT 'grammar','hi','करूँगा','कर द्यूंगा','kar dyunga','neutral','Northern future with द्यूं.','मैं काम करूँगा','मैं काम कर द्यूंगा',60
) v
WHERE d.code = 'jind_kaithal';

-- =====================================================================
-- Application settings
-- =====================================================================
INSERT INTO `app_settings`
  (`setting_key`, `setting_value`, `value_type`, `group_name`, `label`, `description`, `is_public`)
VALUES
('app_name',             'Haryanvi AI',  'string', 'general',  'Application name',            'Shown in the app and admin header.', 1),
('app_logo_url',         '',             'string', 'general',  'Logo URL',                    'Absolute HTTPS URL to the app logo.', 1),
('support_email',        '',             'string', 'general',  'Support email',               'Displayed on the About & Support screen.', 1),
('maintenance_mode',     '0',            'bool',   'general',  'Maintenance mode',            'When on, the API returns HTTP 503 for everything except /settings.', 1),
('maintenance_message',  'We are performing scheduled maintenance. Please try again shortly.', 'text', 'general', 'Maintenance message', 'Shown by the app while maintenance mode is on.', 1),

('free_daily_limit',     '50',           'int',    'limits',   'Free daily translations',     'Per-user daily cap. Per-user overrides take precedence.', 1),
('free_monthly_limit',   '800',          'int',    'limits',   'Free monthly translations',   'Per-user monthly cap.', 1),
('max_input_chars',      '1200',         'int',    'limits',   'Max characters per request',  'Requests longer than this are rejected with HTTP 422.', 1),
('rate_limit_per_minute','20',           'int',    'limits',   'Requests / minute',           'Per-user sliding cap on /translate.', 0),
('rate_limit_per_hour',  '300',          'int',    'limits',   'Requests / hour',             'Per-user hourly cap on /translate.', 0),
('auth_attempts_per_hour','10',          'int',    'limits',   'Login attempts / hour / IP',  'Brute-force throttle for the auth endpoints.', 0),

('allow_registration',   '1',            'bool',   'auth',     'Allow new registrations',     'Turn off to close signups.', 1),
('token_ttl_days',       '90',           'int',    'auth',     'API token lifetime (days)',   'Bearer tokens expire after this many days.', 0),

('default_dialect_code', 'general',      'string', 'engine',   'Default dialect',             'Used when the user has not chosen one.', 1),
('default_tone_code',    'natural',      'string', 'engine',   'Default tone',                'Used when the user has not chosen one.', 1),
('vocab_context_limit',  '40',           'int',    'engine',   'Vocabulary rows per prompt',  'Upper bound on reference lexicon entries injected into a prompt.', 0),

('cors_allowed_origins', '',             'text',   'security', 'Allowed CORS origins',        'Comma-separated list. Empty = block all browser cross-origin calls (the Android app is unaffected).', 0),
('force_https',          '1',            'bool',   'security', 'Force HTTPS',                 'Reject plain HTTP requests.', 0),
('min_app_version',      '1',            'int',    'security', 'Minimum Android versionCode', 'Older clients are asked to update.', 1),

('privacy_policy',
 'Haryanvi AI translates only the text you explicitly submit.\n\nWhat we process: the text you type and press Translate on, plus your chosen dialect and tone.\n\nWhat we never do: we do not read your chats, we do not log keystrokes, we do not read your clipboard in the background, and we do not send messages on your behalf.\n\nHistory: translations are stored only while "Save history" is enabled in Privacy settings. Turning it off stops storage immediately, and you can delete individual entries, clear everything, or delete your account at any time.\n\nSensitive fields: the Haryanvi keyboard is disabled for password, PIN, email-address and other sensitive input fields. Text from those fields is never transmitted.\n\nAI processing: translation runs on a server-side AI provider. Only the text of the single message being translated is sent — never your history, contacts or other personal data. We do not sell translation data.',
 'text', 'legal', 'Privacy policy', 'Rendered on the in-app Privacy screen.', 1),

('terms_conditions',
 'By using Haryanvi AI you agree to the following.\n\n1. Service. Haryanvi AI converts Hindi, English and Hinglish text into Haryanvi. Translation is machine generated and may contain mistakes; review output before sending it.\n\n2. Acceptable use. Do not use the service for unlawful, abusive or harassing content, and do not attempt to overload or reverse-engineer the API.\n\n3. Usage limits. Free accounts are subject to daily and monthly caps enforced on the server. Limits may change.\n\n4. Your content. You retain ownership of the text you submit. You grant us only the permission needed to perform the translation you requested.\n\n5. Availability. The service is provided "as is" without warranty of uninterrupted availability.\n\n6. Termination. Accounts that violate these terms may be suspended.\n\n7. Changes. Material changes to these terms will be published in the app.',
 'text', 'legal', 'Terms & conditions', 'Rendered on the in-app About screen.', 1);

-- =====================================================================
-- Bootstrap admin account
-- ---------------------------------------------------------------------
--  password : ChangeMe!2026   (bcrypt, cost 12)
--  must_change_password = 1 forces rotation at first login.
-- =====================================================================
INSERT INTO `admin_users`
  (`username`, `email`, `password_hash`, `full_name`, `role`, `is_active`, `must_change_password`)
VALUES
('admin', 'admin@example.com',
 '$2y$12$u3pWMv7MpA62katTwPmxyeoIJ8nyNOhPaplCwbO80cguwgYdUHMOa',
 'Site Administrator', 'super_admin', 1, 1);

-- =====================================================================
-- Record the baseline migration so bin/migrate.php stays consistent
-- =====================================================================
INSERT INTO `schema_migrations` (`version`) VALUES ('001_initial_schema')
  ON DUPLICATE KEY UPDATE `version` = `version`;
INSERT INTO `schema_migrations` (`version`) VALUES ('002_seed_reference_data')
  ON DUPLICATE KEY UPDATE `version` = `version`;
