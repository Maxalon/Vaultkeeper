package com.vaultkeeper.app.di

import com.vaultkeeper.app.BuildConfig
import com.vaultkeeper.app.data.api.AuthApi
import com.vaultkeeper.app.data.api.AuthRefreshApi
import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.auth.AuthInterceptor
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.auth.TokenRefreshInterceptor
import com.vaultkeeper.app.data.auth.TokenStore
import com.vaultkeeper.app.data.auth.UnauthenticatedEvent
import com.vaultkeeper.app.data.deck.DeckRepository
import com.vaultkeeper.app.ui.deckentry.DeckEntryViewModel
import com.vaultkeeper.app.ui.decklist.DeckListViewModel
import com.vaultkeeper.app.ui.home.HomeViewModel
import com.vaultkeeper.app.ui.login.LoginViewModel
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import org.koin.android.ext.koin.androidContext
import org.koin.androidx.viewmodel.dsl.viewModel
import org.koin.dsl.module
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

val appModule = module {

    single { TokenStore(androidContext()) }

    single { UnauthenticatedEvent() }

    single {
        Json {
            ignoreUnknownKeys = true
            explicitNulls = false
        }
    }

    // Bare Retrofit used only by TokenRefreshInterceptor to call /auth/refresh
    // without going through the auth interceptor stack (avoids a circular dep).
    single<AuthRefreshApi> {
        val json: Json = get()
        Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(OkHttpClient())
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(AuthRefreshApi::class.java)
    }

    single<OkHttpClient> {
        OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor(get()))
            .addInterceptor(TokenRefreshInterceptor(get(), get(), get()))
            .apply {
                if (BuildConfig.DEBUG) {
                    addInterceptor(HttpLoggingInterceptor().apply {
                        level = HttpLoggingInterceptor.Level.BODY
                    })
                }
            }
            .build()
    }

    single<Retrofit> {
        val json: Json = get()
        Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(get())
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
    }

    single<AuthApi> { get<Retrofit>().create(AuthApi::class.java) }
    single<DeckApi> { get<Retrofit>().create(DeckApi::class.java) }

    single { AuthRepository(get(), get(), get()) }
    single { DeckRepository(get()) }

    viewModel { LoginViewModel(get()) }
    viewModel { HomeViewModel(get()) }
    viewModel { DeckListViewModel(get(), get()) }
    viewModel { params -> DeckEntryViewModel(deckId = params.get<Long>(), repo = get()) }
}
