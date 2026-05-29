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
import com.vaultkeeper.app.ui.deckdetail.DeckDetailScreen
import com.vaultkeeper.app.ui.decklist.DeckListScreen
import com.vaultkeeper.app.ui.login.LoginScreen
import org.koin.compose.koinInject

private const val ROUTE_LOGIN = "login"
private const val ROUTE_DECKS = "decks"
private const val ROUTE_DECK_DETAIL = "decks/{deckId}"

@Composable
fun VaultkeeperNavGraph() {
    val nav = rememberNavController()
    val auth = koinInject<AuthRepository>()
    val session by auth.session.collectAsStateWithLifecycle()

    val target = when (session) {
        is Session.Authenticated -> ROUTE_DECKS
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
        composable(ROUTE_LOGIN) {
            LoginScreen()
        }
        composable(ROUTE_DECKS) {
            DeckListScreen(onDeckClick = { id -> nav.navigate("decks/$id") })
        }
        composable(
            route = ROUTE_DECK_DETAIL,
            arguments = listOf(navArgument("deckId") { type = NavType.LongType }),
        ) { backStack ->
            DeckDetailScreen(
                deckId = backStack.arguments!!.getLong("deckId"),
                onBack = { nav.popBackStack() },
            )
        }
    }
}
