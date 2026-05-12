package com.vaultkeeper.app.di

import com.vaultkeeper.app.BuildConfig
import com.vaultkeeper.app.data.api.AuthApi
import com.vaultkeeper.app.data.auth.AuthInterceptor
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.auth.TokenStore
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

    single {
        Json {
            ignoreUnknownKeys = true
            explicitNulls = false
        }
    }

    single<OkHttpClient> {
        OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor(get()))
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

    single { AuthRepository(get(), get()) }

    viewModel { LoginViewModel(get()) }
    viewModel { HomeViewModel(get()) }
}
