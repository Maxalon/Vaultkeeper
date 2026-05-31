package com.vaultkeeper.app.ui.game

import android.view.Window
import android.view.WindowManager

object WakeLockManager {
    fun acquire(window: Window) {
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    fun release(window: Window) {
        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }
}
