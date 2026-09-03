package com.jixingwangluo.jifengassistant.privacy

import android.content.Context
import android.content.SharedPreferences

interface PrivacyConsentStore {
    fun isAccepted(): Boolean

    fun setAccepted()
}

class SharedPreferencesPrivacyConsentStore(
    context: Context,
    private val preferences: SharedPreferences = context.getSharedPreferences(
        PREFERENCES_NAME,
        Context.MODE_PRIVATE,
    ),
) : PrivacyConsentStore {
    override fun isAccepted(): Boolean = preferences.getBoolean(PRIVACY_ACCEPTED_KEY, false)

    override fun setAccepted() {
        check(preferences.edit().putBoolean(PRIVACY_ACCEPTED_KEY, true).commit()) {
            "Unable to persist privacy consent"
        }
    }

    private companion object {
        const val PREFERENCES_NAME = "jifeng_privacy_v1"
        const val PRIVACY_ACCEPTED_KEY = "privacy_accepted_v1"
    }
}
