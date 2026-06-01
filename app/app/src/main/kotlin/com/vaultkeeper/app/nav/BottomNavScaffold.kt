package com.vaultkeeper.app.nav

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Collections
import androidx.compose.material.icons.filled.Group
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.RateReview
import androidx.compose.material.icons.filled.Style
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.vaultkeeper.app.ui.screens.CollectionScreen
import com.vaultkeeper.app.ui.screens.DecksScreen
import com.vaultkeeper.app.ui.screens.FriendsScreen
import com.vaultkeeper.app.ui.screens.NotificationsScreen
import com.vaultkeeper.app.ui.screens.ReviewScreen

private sealed class Tab(
    val route: String,
    val label: String,
    val icon: ImageVector,
) {
    data object Collection : Tab("collection", "Collection", Icons.Default.Collections)
    data object Decks : Tab("decks", "Decks", Icons.Default.Style)
    data object Review : Tab("review", "Review", Icons.Default.RateReview)
    data object Friends : Tab("friends", "Friends", Icons.Default.Group)
}

private val tabs = listOf(Tab.Collection, Tab.Decks, Tab.Review, Tab.Friends)

private const val ROUTE_NOTIFICATIONS = "notifications"

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BottomNavScaffold() {
    val navController = rememberNavController()
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentDestination = navBackStackEntry?.destination

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Vaultkeeper") },
                actions = {
                    IconButton(onClick = { navController.navigate(ROUTE_NOTIFICATIONS) }) {
                        Icon(
                            imageVector = Icons.Default.Notifications,
                            contentDescription = "Notifications",
                        )
                    }
                },
            )
        },
        bottomBar = {
            NavigationBar {
                tabs.forEach { tab ->
                    NavigationBarItem(
                        icon = { Icon(tab.icon, contentDescription = tab.label) },
                        label = { Text(tab.label) },
                        selected = currentDestination?.hierarchy
                            ?.any { it.route == tab.route } == true,
                        onClick = {
                            navController.navigate(tab.route) {
                                popUpTo(navController.graph.findStartDestination().id) {
                                    saveState = true
                                }
                                launchSingleTop = true
                                restoreState = true
                            }
                        },
                    )
                }
            }
        },
    ) { innerPadding ->
        NavHost(
            navController = navController,
            startDestination = Tab.Collection.route,
            modifier = Modifier.padding(innerPadding),
        ) {
            composable(Tab.Collection.route) { CollectionScreen() }
            composable(Tab.Decks.route) { DecksScreen() }
            composable(Tab.Review.route) { ReviewScreen() }
            composable(Tab.Friends.route) { FriendsScreen() }
            composable(ROUTE_NOTIFICATIONS) { NotificationsScreen() }
        }
    }
}
