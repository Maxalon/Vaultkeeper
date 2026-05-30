package com.vaultkeeper.app.nav

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.auth.Session
import com.vaultkeeper.app.ui.home.HomeScreen
import com.vaultkeeper.app.ui.login.LoginScreen
import com.vaultkeeper.app.ui.onboarding.OnboardingScreen
import org.koin.compose.koinInject

private const val ROUTE_LOGIN = "login"
private const val ROUTE_ONBOARDING = "onboarding"
private const val ROUTE_HOME = "home"

@Composable
fun VaultkeeperNavGraph() {
    val nav = rememberNavController()
    val auth = koinInject<AuthRepository>()
    val session by auth.session.collectAsStateWithLifecycle()

    val authenticated = session as? Session.Authenticated
    val target = when {
        authenticated?.user?.needsOnboarding == true -> ROUTE_ONBOARDING
        // HomeScreen is the app's main landing screen — the Collection equivalent on Android.
        authenticated != null -> ROUTE_HOME
        else -> ROUTE_LOGIN
    }

    LaunchedEffect(target) {
        if (nav.currentDestination?.route != target) {
            nav.navigate(target) {
                popUpTo(nav.graph.startDestinationId) { inclusive = true }
                launchSingleTop = true
            }
        }
    }

    NavHost(navController = nav, startDestination = ROUTE_LOGIN) {
        composable(ROUTE_LOGIN) { LoginScreen() }
        composable(ROUTE_ONBOARDING) { OnboardingScreen() }
        composable(ROUTE_HOME) { HomeScreen() }
    }
}
