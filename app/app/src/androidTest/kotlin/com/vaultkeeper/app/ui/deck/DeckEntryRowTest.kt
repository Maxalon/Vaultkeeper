package com.vaultkeeper.app.ui.deck

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeRight
import androidx.compose.ui.unit.dp
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.ui.theme.VaultkeeperTheme
import org.junit.Rule
import org.junit.Test

class DeckEntryRowTest {

    @get:Rule
    val composeTestRule = createComposeRule()

    private val entry = DeckEntryDto(id = 42L, scryfall_id = "test-scryfall-id", zone = "main", quantity = 1)

    @Test
    fun swipeRight_revealsActionButton() {
        composeTestRule.setContent {
            VaultkeeperTheme {
                DeckEntryRow(entry = entry, onMoveZone = {})
            }
        }

        composeTestRule.onNodeWithTag("entry_row_42")
            .performTouchInput { swipeRight(startX = 0f, endX = 200f) }

        composeTestRule.onNodeWithTag("move_zone_action_42").assertIsDisplayed()
    }

    @Test
    fun tapMoveZoneAction_opensZonePickerSheet() {
        composeTestRule.setContent {
            VaultkeeperTheme {
                DeckEntryRow(entry = entry, onMoveZone = {})
            }
        }

        // Reveal the action first.
        composeTestRule.onNodeWithTag("entry_row_42")
            .performTouchInput { swipeRight(startX = 0f, endX = 200f) }

        composeTestRule.onNodeWithTag("move_zone_action_42").performClick()

        composeTestRule.onNodeWithTag("zone_picker_sheet").assertIsDisplayed()
    }

    @Test
    fun selectingZone_invokesCallbackAndDismissesSheet() {
        var selectedZone: DeckZone? = null
        composeTestRule.setContent {
            VaultkeeperTheme {
                DeckEntryRow(entry = entry, onMoveZone = { selectedZone = it })
            }
        }

        composeTestRule.onNodeWithTag("entry_row_42")
            .performTouchInput { swipeRight(startX = 0f, endX = 200f) }
        composeTestRule.onNodeWithTag("move_zone_action_42").performClick()

        composeTestRule.onNodeWithTag("zone_option_side").performClick()

        assert(selectedZone == DeckZone.SIDE)
        composeTestRule.onNodeWithTag("zone_picker_sheet").assertDoesNotExist()
    }
}
