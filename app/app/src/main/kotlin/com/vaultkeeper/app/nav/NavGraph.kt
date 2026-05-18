package com.vaultkeeper.app.nav

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.auth.Session
import com.vaultkeeper.app.ui.decks.DeckDetailScreen
import com.vaultkeeper.app.ui.login.LoginScreen
import org.koin.compose.koinInject

private const val ROUTE_LOGIN = "login"
private const val ROUTE_HOME = "home"
private const val ROUTE_DECK = "deck/{deckId}"

@Composable
fun VaultkeeperNavGraph() {
    val nav = rememberNavController()
    val auth = koinInject<AuthRepository>()
    val session by auth.session.collectAsStateWithLifecycle()

    val target = when (session) {
        is Session.Authenticated -> ROUTE_HOME
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
        composable(ROUTE_HOME) {
            BottomNavScaffold(onNavigateToDeck = { id -> nav.navigate("deck/$id") })
        }
        composable(
            route = ROUTE_DECK,
            arguments = listOf(navArgument("deckId") { type = NavType.LongType }),
        ) { backStackEntry ->
            val deckId = backStackEntry.arguments?.getLong("deckId") ?: 0L
            DeckDetailScreen(deckId = deckId, onBack = { nav.popBackStack() })
        }
    }
}
