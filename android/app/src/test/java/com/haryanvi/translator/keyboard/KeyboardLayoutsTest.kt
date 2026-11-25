package com.haryanvi.translator.keyboard

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Keyboard layout integrity.
 *
 * A malformed layout shows up as a crash or an unusable row on a real device,
 * which is expensive to find by hand. These checks are cheap.
 */
class KeyboardLayoutsTest {

    private val allLayers = listOf(
        KeyboardLayer.DEVANAGARI,
        KeyboardLayer.QWERTY,
        KeyboardLayer.SYMBOLS,
        KeyboardLayer.EMOJI,
    )

    @Test
    fun `every layer has rows and every row has keys`() {
        allLayers.forEach { layer ->
            val rows = KeyboardLayouts.lettersFor(layer)

            assertTrue("$layer has no rows", rows.isNotEmpty())
            rows.forEachIndexed { index, row ->
                assertTrue("$layer row $index is empty", row.isNotEmpty())
            }
        }
    }

    @Test
    fun `no key has a non-positive width`() {
        allLayers.forEach { layer ->
            KeyboardLayouts.lettersFor(layer).flatten().forEach { key ->
                assertTrue(
                    "Key '${key.label}' in $layer has weight ${key.weight}",
                    key.weight > 0f,
                )
            }
        }
    }

    @Test
    fun `no row is wide enough to squeeze keys off screen`() {
        // Ten unit-width keys is the widest standard row; allow a little more
        // for modifier keys but catch an accidentally overloaded row.
        allLayers.forEach { layer ->
            KeyboardLayouts.lettersFor(layer).forEachIndexed { index, row ->
                val total = row.sumOf { it.weight.toDouble() }

                assertTrue(
                    "$layer row $index totals $total units",
                    total <= 13.0,
                )
            }
        }
    }

    @Test
    fun `qwerty provides the full latin alphabet`() {
        val letters = KeyboardLayouts.qwerty
            .flatten()
            .mapNotNull { (it.action as? KeyAction.Char)?.value }
            .joinToString("")

        ('a'..'z').forEach { letter ->
            assertTrue("QWERTY is missing '$letter'", letters.contains(letter))
        }
    }

    @Test
    fun `qwerty shift produces upper case`() {
        KeyboardLayouts.qwerty.flatten()
            .mapNotNull { it.action as? KeyAction.Char }
            .filter { it.value.single().isLetter() }
            .forEach { key ->
                assertEquals(key.value.uppercase(), key.shifted)
            }
    }

    @Test
    fun `devanagari layer contains the consonants haryanvi needs`() {
        val characters = KeyboardLayouts.devanagari
            .flatten()
            .mapNotNull { (it.action as? KeyAction.Char) }
            .flatMap { listOf(it.value, it.shifted) }
            .toSet()

        // ण and स carry the two most characteristic Haryanvi features:
        // the -णा infinitive and the सै/सूं copula.
        listOf("क", "ग", "त", "द", "न", "ण", "म", "र", "ल", "स", "ह", "य", "व")
            .forEach { consonant ->
                assertTrue("Devanagari layer is missing $consonant", characters.contains(consonant))
            }
    }

    @Test
    fun `bottom row always exposes space, enter and translate`() {
        allLayers.forEach { layer ->
            val actions = KeyboardLayouts
                .bottomRow(layer, translateEnabled = true, canUndo = false)
                .map { it.action }

            assertTrue("$layer bottom row has no space key", actions.any { it is KeyAction.Space })
            assertTrue("$layer bottom row has no enter key", actions.any { it is KeyAction.Enter })
            assertTrue(
                "$layer bottom row has no translate key",
                actions.any { it is KeyAction.Translate },
            )
        }
    }

    @Test
    fun `translate key is rendered inactive when translation is not allowed`() {
        val disabled = KeyboardLayouts
            .bottomRow(KeyboardLayer.DEVANAGARI, translateEnabled = false, canUndo = false)
            .first { it.action is KeyAction.Translate }

        assertEquals(KeyStyle.SPECIAL, disabled.style)

        val enabled = KeyboardLayouts
            .bottomRow(KeyboardLayer.DEVANAGARI, translateEnabled = true, canUndo = false)
            .first { it.action is KeyAction.Translate }

        assertEquals(KeyStyle.ACCENT, enabled.style)
    }

    @Test
    fun `undo replaces paste only when an undo snapshot exists`() {
        val withoutUndo = KeyboardLayouts
            .bottomRow(KeyboardLayer.DEVANAGARI, translateEnabled = true, canUndo = false)
            .map { it.action }

        assertTrue(withoutUndo.any { it is KeyAction.Paste })
        assertFalse(withoutUndo.any { it is KeyAction.Undo })

        val withUndo = KeyboardLayouts
            .bottomRow(KeyboardLayer.DEVANAGARI, translateEnabled = true, canUndo = true)
            .map { it.action }

        assertTrue(withUndo.any { it is KeyAction.Undo })
        assertFalse(withUndo.any { it is KeyAction.Paste })
    }

    @Test
    fun `every layer offers a way back to letters`() {
        listOf(KeyboardLayer.SYMBOLS, KeyboardLayer.EMOJI).forEach { layer ->
            val actions = KeyboardLayouts.lettersFor(layer).flatten().map { it.action } +
                KeyboardLayouts.bottomRow(layer, true, false).map { it.action }

            assertTrue(
                "$layer has no route back to the letter layer",
                actions.any { it is KeyAction.Letters },
            )
        }
    }

    @Test
    fun `every layer has a backspace within reach`() {
        allLayers.forEach { layer ->
            val actions = KeyboardLayouts.lettersFor(layer).flatten().map { it.action }

            assertTrue(
                "$layer has no backspace",
                actions.any { it is KeyAction.Backspace },
            )
        }
    }

    @Test
    fun `emoji layer contains only single graphemes and controls`() {
        KeyboardLayouts.emoji.flatten().forEach { key ->
            val action = key.action

            if (action is KeyAction.Char) {
                assertTrue(
                    "Emoji key '${action.value}' is unexpectedly long",
                    action.value.codePointCount(0, action.value.length) <= 2,
                )
            }
        }
    }
}
