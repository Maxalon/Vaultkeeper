package com.vaultkeeper.app.ui.game

import android.view.Window
import android.view.WindowManager
import io.mockk.mockk
import io.mockk.verify
import org.junit.Test

class WakeLockManagerTest {

    private val window = mockk<Window>(relaxed = true)

    @Test
    fun `acquire sets FLAG_KEEP_SCREEN_ON on window`() {
        WakeLockManager.acquire(window)
        verify { window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
    }

    @Test
    fun `release clears FLAG_KEEP_SCREEN_ON from window`() {
        WakeLockManager.release(window)
        verify { window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
    }
}
