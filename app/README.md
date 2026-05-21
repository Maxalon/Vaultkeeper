# Vaultkeeper Android client

Kotlin / Compose / Material 3 client for the Vaultkeeper API.

## Stack

- compileSdk 35, minSdk 31, targetSdk 35 — JDK 17
- Gradle Kotlin DSL with a version catalog (`gradle/libs.versions.toml`)
- Compose + Material 3, Compose Navigation
- Hilt (DI framework; application class and base activity wired) + Koin (runtime modules)
- Retrofit + OkHttp + kotlinx.serialization
- DataStore + Jetpack Security for JWT storage
- Coil (image loading; pre-wired for card art)

## Layout

```
app/                       Gradle project root (this directory)
├── gradlew, gradlew.bat   wrapper (Gradle 8.11.1)
├── settings.gradle.kts
├── build.gradle.kts
├── gradle.properties
├── gradle/
│   ├── libs.versions.toml
│   └── wrapper/
└── app/                    Android application module
    ├── build.gradle.kts
    └── src/main/kotlin/com/vaultkeeper/app/
        ├── VaultkeeperApp.kt   Application; starts Koin
        ├── MainActivity.kt
        ├── di/AppModule.kt
        ├── data/
        │   ├── api/             Retrofit interfaces + DTOs
        │   └── auth/            TokenStore, AuthInterceptor, AuthRepository
        ├── nav/NavGraph.kt      Compose Navigation host
        └── ui/
            ├── login/           LoginScreen + ViewModel
            ├── home/            HomeScreen + ViewModel
            └── theme/
```

## Build variants

Two product flavors crossed with two build types:

| Variant            | applicationId               | API base URL                                              |
|--------------------|-----------------------------|-----------------------------------------------------------|
| `localDebug`       | `com.vaultkeeper.app.local` | `http://10.0.2.2:8080/api/` (emulator → host dev nginx)   |
| `betaDebug`        | `com.vaultkeeper.app.beta`  | `https://vault-staging.kontrollzentrale.de/api/`          |
| `betaRelease`      | `com.vaultkeeper.app.beta`  | `https://vault-staging.kontrollzentrale.de/api/`          |
| `prodDebug`        | `com.vaultkeeper.app`       | `https://vaultkeeper.cards/api/`                          |
| `prodRelease`      | `com.vaultkeeper.app`       | `https://vaultkeeper.cards/api/`                          |

`API_BASE_URL` lives on the flavor, not the build type — build-type
`buildConfigField` overrides win over flavor values, which silently
clobbers the URL. All three flavors install side-by-side. The
`betaDebug` variant is what the GH Action publishes as a GitHub Release
on every push to the `staging` branch that touched `app/**` (sideload
onto the device).

## Local development

```bash
./gradlew assembleLocalDebug        # build for emulator + local API
./gradlew installLocalDebug         # install on connected device/emulator
./gradlew assembleBetaDebug         # build the sideload APK that ships to testers
./gradlew test                      # unit tests
./gradlew lint                      # Android lint
```

For emulator dev against the local API: build the `local` flavor. The
dev nginx must be reachable on the host's `:8080` (the standard Compose
stack — see `docs/local-dev.md`). The Android emulator hits the host
via `10.0.2.2`.

## Auth flow

`AuthRepository` is the single source of truth. `TokenStore` persists the
JWT in `EncryptedSharedPreferences`. `AuthInterceptor` stamps `Bearer ...`
onto every outbound request. `NavGraph` watches `Session` and routes
between `LoginScreen` and `HomeScreen` accordingly.

The API contract for `/auth/login` is `{username, password}` →
`{access_token, token_type, expires_in, user}` (see `AuthDtos.kt`).
