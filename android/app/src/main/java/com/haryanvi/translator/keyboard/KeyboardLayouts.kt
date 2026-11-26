package com.haryanvi.translator.keyboard

/**
 * Key definitions for the Haryanvi keyboard.
 *
 * Kept as plain data rather than XML so the service can build rows
 * programmatically, size them to the available width, and swap layouts
 * without inflating anything.
 */

/** What a key does when tapped. */
sealed interface KeyAction {
    /** Commit a character. */
    data class Char(val value: String, val shifted: String = value) : KeyAction

    data object Shift : KeyAction
    data object Backspace : KeyAction
    data object Space : KeyAction
    data object Enter : KeyAction

    /** Switch between the Devanagari and QWERTY letter layouts. */
    data object SwitchScript : KeyAction

    /** Toggle the symbols layer. */
    data object Symbols : KeyAction

    /** Toggle the emoji layer. */
    data object Emoji : KeyAction

    /** Return from symbols/emoji to letters. */
    data object Letters : KeyAction

    /** The one action that transmits text: translate to Haryanvi. */
    data object Translate : KeyAction

    /** Paste the clipboard — only on an explicit tap. */
    data object Paste : KeyAction

    /** Restore the pre-translation text (in-memory, single step). */
    data object Undo : KeyAction

    /** Open the app's keyboard settings. */
    data object Settings : KeyAction

    /** Hand over to another installed keyboard. */
    data object NextKeyboard : KeyAction
}

/** A single key: a label, an action and a relative width. */
data class Key(
    val label: String,
    val action: KeyAction,
    /** 1.0 is a normal letter key. */
    val weight: Float = 1f,
    val style: KeyStyle = KeyStyle.NORMAL,
    /** Optional smaller hint drawn above the label (long-press character). */
    val hint: String? = null,
    val iconRes: Int? = null,
)

enum class KeyStyle { NORMAL, SPECIAL, ACCENT }

/** Which letter layer is showing. */
enum class KeyboardLayer { DEVANAGARI, QWERTY, SYMBOLS, EMOJI }

object KeyboardLayouts {

    /* ----------------------------------------------------------------
     | Persistent number row, shown above the letters on the Devanagari
     | and QWERTY layers — the way Gboard presents digits.
     * -------------------------------------------------------------- */
    val numberRow: List<Key> = "1234567890".map { charKey(it.toString()) }

    /* ----------------------------------------------------------------
     | Devanagari — arranged for Hindi/Haryanvi typing.
     | The shifted face of each key carries the related aspirated or
     | long-vowel form, which is how Indic phone keyboards behave.
     * -------------------------------------------------------------- */
    val devanagari: List<List<Key>> = listOf(
        listOf(
            charKey("क", "ख"), charKey("ग", "घ"), charKey("च", "छ"), charKey("ज", "झ"),
            charKey("ट", "ठ"), charKey("ड", "ढ"), charKey("त", "थ"), charKey("द", "ध"),
            charKey("प", "फ"), charKey("ब", "भ"),
        ),
        listOf(
            charKey("ा", "आ"), charKey("ि", "ई"), charKey("ी", "इ"), charKey("ु", "ऊ"),
            charKey("ू", "उ"), charKey("े", "ऐ"), charKey("ै", "ए"), charKey("ो", "औ"),
            charKey("ौ", "ओ"), charKey("ं", "ँ"),
        ),
        listOf(
            charKey("न", "ण"), charKey("म", "ङ"), charKey("य", "य़"), charKey("र", "ऋ"),
            charKey("ल", "ळ"), charKey("व", "ॅ"), charKey("स", "श"), charKey("ष", "ज़"),
            charKey("ह", "ः"),
        ),
        listOf(
            Key("⇧", KeyAction.Shift, weight = 1.4f, style = KeyStyle.SPECIAL),
            charKey("़", "्"), charKey("अ", "ऑ"), charKey("ई", "औ"),
            charKey("ओ", "औ"), charKey("ऌ", "ॠ"),
            Key("⌫", KeyAction.Backspace, weight = 1.4f, style = KeyStyle.SPECIAL),
        ),
    )

    /* ----------------------------------------------------------------
     | Standard QWERTY for English and Hinglish input
     * -------------------------------------------------------------- */
    val qwerty: List<List<Key>> = listOf(
        "qwertyuiop".map { charKey(it.toString(), it.uppercase()) },
        "asdfghjkl".map { charKey(it.toString(), it.uppercase()) },
        buildList {
            add(Key("⇧", KeyAction.Shift, weight = 1.4f, style = KeyStyle.SPECIAL))
            addAll("zxcvbnm".map { charKey(it.toString(), it.uppercase()) })
            add(Key("⌫", KeyAction.Backspace, weight = 1.4f, style = KeyStyle.SPECIAL))
        },
    )

    /* ----------------------------------------------------------------
     | Symbols and punctuation
     * -------------------------------------------------------------- */
    val symbols: List<List<Key>> = listOf(
        "1234567890".map { charKey(it.toString()) },
        listOf("@", "#", "₹", "%", "&", "*", "-", "+", "(", ")").map { charKey(it) },
        buildList {
            add(Key("=\\<", KeyAction.Symbols, weight = 1.4f, style = KeyStyle.SPECIAL))
            addAll(listOf("!", "\"", "'", ":", ";", "/", "?").map { charKey(it) })
            add(Key("⌫", KeyAction.Backspace, weight = 1.4f, style = KeyStyle.SPECIAL))
        },
        listOf(
            Key("ABC", KeyAction.Letters, weight = 1.6f, style = KeyStyle.SPECIAL),
            charKey("।"), charKey(","), charKey("."), charKey("…"),
            charKey("_"), charKey("~"),
            Key("⌫", KeyAction.Backspace, weight = 1.4f, style = KeyStyle.SPECIAL),
        ),
    )

    /* ----------------------------------------------------------------
     | Emoji — the set people actually use in chat
     * -------------------------------------------------------------- */
    val emoji: List<List<Key>> = listOf(
        listOf("😀", "😂", "🙂", "😉", "😍", "😘", "🤣", "😎").map { charKey(it) },
        listOf("👍", "🙏", "👏", "💪", "🤝", "✌", "🫡", "🙌").map { charKey(it) },
        listOf("❤", "🔥", "✨", "🎉", "💯", "😢", "😡", "🤔").map { charKey(it) },
        listOf(
            Key("ABC", KeyAction.Letters, weight = 1.6f, style = KeyStyle.SPECIAL),
            charKey("🚜"), charKey("🐃"), charKey("🌾"), charKey("🏏"), charKey("🥛"),
            Key("⌫", KeyAction.Backspace, weight = 1.4f, style = KeyStyle.SPECIAL),
        ),
    )

    /**
     * The bottom row, which is the same on every layer and carries the
     * translate key — the only control that sends text anywhere.
     */
    fun bottomRow(
        layer: KeyboardLayer,
        translateEnabled: Boolean,
        canUndo: Boolean,
    ): List<Key> = buildList {
        add(
            Key(
                label = if (layer == KeyboardLayer.SYMBOLS) "ABC" else "?123",
                action = if (layer == KeyboardLayer.SYMBOLS) KeyAction.Letters else KeyAction.Symbols,
                weight = 1.3f,
                style = KeyStyle.SPECIAL,
            )
        )

        add(
            Key(
                label = if (layer == KeyboardLayer.DEVANAGARI) "EN" else "हि",
                action = KeyAction.SwitchScript,
                weight = 1.1f,
                style = KeyStyle.SPECIAL,
            )
        )

        add(Key("☺", KeyAction.Emoji, weight = 1f, style = KeyStyle.SPECIAL))

        add(Key("", KeyAction.Space, weight = 3.4f))

        if (canUndo) {
            add(Key("↶", KeyAction.Undo, weight = 1.1f, style = KeyStyle.SPECIAL))
        } else {
            add(Key("📋", KeyAction.Paste, weight = 1.1f, style = KeyStyle.SPECIAL))
        }

        add(
            Key(
                label = "हरियाणवी",
                action = KeyAction.Translate,
                weight = 2.4f,
                style = if (translateEnabled) KeyStyle.ACCENT else KeyStyle.SPECIAL,
            )
        )

        add(Key("⏎", KeyAction.Enter, weight = 1.4f, style = KeyStyle.ACCENT))
    }

    fun lettersFor(layer: KeyboardLayer): List<List<Key>> = when (layer) {
        KeyboardLayer.DEVANAGARI -> devanagari
        KeyboardLayer.QWERTY -> qwerty
        KeyboardLayer.SYMBOLS -> symbols
        KeyboardLayer.EMOJI -> emoji
    }

    private fun charKey(value: String, shifted: String = value) = Key(
        label = value,
        action = KeyAction.Char(value, shifted),
        hint = shifted.takeIf { it != value },
    )
}
