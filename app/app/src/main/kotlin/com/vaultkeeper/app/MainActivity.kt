package com.vaultkeeper.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.vaultkeeper.app.nav.VaultkeeperNavGraph
import com.vaultkeeper.app.ui.theme.VaultkeeperTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            VaultkeeperTheme {
                VaultkeeperNavGraph()
            }
        }
    }
}
