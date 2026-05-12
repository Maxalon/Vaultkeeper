package com.vaultkeeper.app.data.auth

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class TokenStore(context: Context) {

    private val prefs = EncryptedSharedPreferences.create(
        context,
        "vk_auth",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) {
            prefs.edit().apply {
                if (value == null) remove(KEY_TOKEN) else putString(KEY_TOKEN, value)
            }.apply()
        }

    fun clear() {
        token = null
    }

    private companion object {
        const val KEY_TOKEN = "access_token"
    }
}
