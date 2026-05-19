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
import androidx.navigation.navDeepLink
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.auth.Session
import com.vaultkeeper.app.ui.forgotpassword.ForgotPasswordScreen
import com.vaultkeeper.app.ui.home.HomeScreen
import com.vaultkeeper.app.ui.login.LoginScreen
import com.vaultkeeper.app.ui.register.RegisterScreen
import com.vaultkeeper.app.ui.resetpassword.ResetPasswordScreen
import org.koin.compose.koinInject

private const val ROUTE_LOGIN = "login"
private const val ROUTE_REGISTER = "register"
private const val ROUTE_FORGOT_PASSWORD = "forgot-password"
private const val ROUTE_RESET_PASSWORD = "reset-password/{token}"
private const val ROUTE_HOME = "home"

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
        val current = nav.currentDestination?.route
        if (current != target && current != ROUTE_REGISTER &&
            current != ROUTE_FORGOT_PASSWORD && !current.orEmpty().startsWith("reset-password")
        ) {
            nav.navigate(target) {
                popUpTo(nav.graph.startDestinationId) { inclusive = true }
                launchSingleTop = true
            }
        }
    }

    NavHost(navController = nav, startDestination = ROUTE_LOGIN) {
        composable(ROUTE_LOGIN) {
            LoginScreen(
                onNavigateToRegister = { nav.navigate(ROUTE_REGISTER) },
                onNavigateToForgotPassword = { nav.navigate(ROUTE_FORGOT_PASSWORD) },
            )
        }
        composable(ROUTE_REGISTER) {
            RegisterScreen(
                onNavigateToLogin = { nav.popBackStack() },
            )
        }
        composable(ROUTE_FORGOT_PASSWORD) {
            ForgotPasswordScreen(
                onNavigateToLogin = { nav.popBackStack() },
            )
        }
        composable(
            route = ROUTE_RESET_PASSWORD,
            arguments = listOf(navArgument("token") { type = NavType.StringType }),
            deepLinks = listOf(
                navDeepLink { uriPattern = "vaultkeeper://reset-password/{token}" },
            ),
        ) { backStackEntry ->
            val token = backStackEntry.arguments?.getString("token").orEmpty()
            ResetPasswordScreen(
                token = token,
                onNavigateToLogin = {
                    nav.navigate(ROUTE_LOGIN) {
                        popUpTo(nav.graph.startDestinationId) { inclusive = true }
                        launchSingleTop = true
                    }
                },
            )
        }
        composable(ROUTE_HOME) { HomeScreen() }
    }
}
