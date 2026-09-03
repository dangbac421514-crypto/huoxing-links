package com.jixingwangluo.jifengassistant.douyin

import android.content.Context
import android.content.SharedPreferences

interface PendingShareStore {
    fun save(state: String)

    fun clear()

    /** Consume [state] exactly once; a mismatch leaves the pending state intact. */
    fun consumeIfMatches(state: String): Boolean

    fun peek(): String?
}

class SharedPreferencesPendingShareStore(
    context: Context,
    private val preferences: SharedPreferences = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE),
) : PendingShareStore {
    override fun save(state: String) {
        require(state.isNotBlank()) { "state must not be blank" }
        check(preferences.edit().putString(STATE_KEY, state).commit()) {
            "Unable to persist pending Douyin share state"
        }
    }

    override fun clear() {
        check(preferences.edit().remove(STATE_KEY).commit()) {
            "Unable to clear pending Douyin share state"
        }
    }

    @Synchronized
    override fun consumeIfMatches(state: String): Boolean {
        if (state.isBlank() || preferences.getString(STATE_KEY, null) != state) {
            return false
        }
        clear()
        return true
    }

    override fun peek(): String? = preferences.getString(STATE_KEY, null)

    private companion object {
        const val PREFERENCES_NAME = "douyin_pending_share_v1"
        const val STATE_KEY = "state"
    }
}

/** Deterministic store used by JVM tests and callers that own their persistence boundary. */
class InMemoryPendingShareStore : PendingShareStore {
    private var pendingState: String? = null

    override fun save(state: String) {
        require(state.isNotBlank()) { "state must not be blank" }
        pendingState = state
    }

    override fun clear() {
        pendingState = null
    }

    @Synchronized
    override fun consumeIfMatches(state: String): Boolean {
        if (state.isBlank() || pendingState != state) {
            return false
        }
        pendingState = null
        return true
    }

    override fun peek(): String? = pendingState
}
