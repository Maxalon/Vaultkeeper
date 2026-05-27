package com.vaultkeeper.app.ui.deck

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.ScryfallCardDto
import com.vaultkeeper.app.ui.theme.VaultkeeperTheme
import org.junit.Rule
import org.junit.Test

class DeckEntryRowTest {

    @get:Rule
    val rule = createComposeRule()

    @Test
    fun swipe_left_reveals_move_zone_button() {
        val entry = makeEntry(id = 1, zone = "main")
        rule.setContent {
            VaultkeeperTheme {
                SwipeRevealRow(entry = entry, onMoveZone = {})
            }
        }

        rule.onNodeWithTag("entry_row_1").performTouchInput { swipeLeft() }

        rule.onNodeWithTag("move_zone_button_1").assertIsDisplayed()
    }

    @Test
    fun tapping_move_zone_button_invokes_callback() {
        val entry = makeEntry(id = 2, zone = "side")
        var callbackFired = false
        rule.setContent {
            VaultkeeperTheme {
                SwipeRevealRow(
                    entry = entry,
                    onMoveZone = { callbackFired = true },
                )
            }
        }

        rule.onNodeWithTag("entry_row_2").performTouchInput { swipeLeft() }
        rule.onNodeWithTag("move_zone_button_2").performClick()

        assert(callbackFired)
    }

    @Test
    fun zone_picker_sheet_shows_all_zones() {
        rule.setContent {
            VaultkeeperTheme {
                ZonePickerSheet(currentZone = "main", onZoneSelected = {})
            }
        }

        rule.onNodeWithTag("zone_picker_sheet").assertIsDisplayed()
        rule.onNodeWithTag("zone_option_main").assertIsDisplayed()
        rule.onNodeWithTag("zone_option_side").assertIsDisplayed()
        rule.onNodeWithTag("zone_option_maybe").assertIsDisplayed()
    }

    @Test
    fun zone_picker_invokes_callback_on_zone_tap() {
        var selected: String? = null
        rule.setContent {
            VaultkeeperTheme {
                ZonePickerSheet(currentZone = "main", onZoneSelected = { selected = it })
            }
        }

        rule.onNodeWithTag("zone_option_side").performClick()

        assert(selected == "side")
    }

    @Test
    fun zone_picker_current_zone_is_not_clickable() {
        var selected: String? = null
        rule.setContent {
            VaultkeeperTheme {
                ZonePickerSheet(currentZone = "main", onZoneSelected = { selected = it })
            }
        }

        rule.onNodeWithTag("zone_option_main").performClick()

        // callback must not fire for the already-active zone
        assert(selected == null)
    }

    private fun makeEntry(id: Int, zone: String) = DeckEntryDto(
        id = id,
        deckId = 1,
        scryfallId = "scryfall-$id",
        quantity = 1,
        zone = zone,
        isCommander = false,
        scryfallCard = ScryfallCardDto(
            scryfallId = "scryfall-$id",
            name = "Test Card $id",
        ),
    )
}
