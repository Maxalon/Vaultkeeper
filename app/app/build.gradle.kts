import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

// Release signing credentials. Read from a gitignored
// release-signing.properties next to settings.gradle.kts for local builds,
// falling back to env vars for CI. Never commit the keystore or its
// passwords. When neither source is present (e.g. a debug-only checkout)
// the release build type is left unsigned so configuration still succeeds.
val releaseSigningProps = Properties().apply {
    val f = rootProject.file("release-signing.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}

fun signingValue(key: String, env: String): String? =
    releaseSigningProps.getProperty(key) ?: System.getenv(env)

val hasReleaseSigning = signingValue("storeFile", "ANDROID_KEYSTORE_FILE") != null

android {
    namespace = "com.vaultkeeper.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.vaultkeeper.app"
        minSdk = 31
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables { useSupportLibrary = true }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    flavorDimensions += "env"
    productFlavors {
        // Emulator / local-dev build. 10.0.2.2 is the emulator's alias
        // for the host machine. The dev nginx listens on :8080
        // (see /docs/local-dev.md).
        create("local") {
            dimension = "env"
            applicationIdSuffix = ".local"
            versionNameSuffix = "-local"
            buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:8080/api/\"")
            resValue("string", "app_name", "Vaultkeeper Local")
        }
        create("beta") {
            dimension = "env"
            applicationIdSuffix = ".beta"
            versionNameSuffix = "-beta"
            buildConfigField("String", "API_BASE_URL", "\"https://vault-staging.kontrollzentrale.de/api/\"")
            resValue("string", "app_name", "Vaultkeeper Beta")
        }
        create("prod") {
            dimension = "env"
            buildConfigField("String", "API_BASE_URL", "\"https://vaultkeeper.cards/api/\"")
            resValue("string", "app_name", "Vaultkeeper")
        }
    }

    signingConfigs {
        create("release") {
            if (hasReleaseSigning) {
                storeFile = rootProject.file(signingValue("storeFile", "ANDROID_KEYSTORE_FILE")!!)
                storePassword = signingValue("storePassword", "ANDROID_KEYSTORE_PASSWORD")
                keyAlias = signingValue("keyAlias", "ANDROID_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "ANDROID_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        debug {
            isMinifyEnabled = false
        }
        release {
            if (hasReleaseSigning) {
                signingConfig = signingConfigs.getByName("release")
            }
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
            // Bundle native debug symbols (from dependencies like Tink/Conscrypt)
            // into the AAB so Play can symbolicate crashes/ANRs.
            ndk {
                debugSymbolLevel = "FULL"
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.navigation.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons.extended)
    debugImplementation(libs.androidx.compose.ui.tooling)

    implementation(libs.koin.android)
    implementation(libs.koin.compose)

    implementation(libs.retrofit)
    implementation(libs.retrofit.kotlinx.serialization)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.kotlinx.serialization.json)

    implementation(libs.coil.compose)

    implementation(libs.androidx.datastore.preferences)
    implementation(libs.androidx.security.crypto)

    testImplementation(libs.junit)
    testImplementation(libs.mockk)
    testImplementation(libs.okhttp.mockwebserver)
    testImplementation(libs.kotlinx.coroutines.test)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso)
}
