package com.jixingwangluo.jifengassistant.douyin

import android.app.Activity
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import java.security.SecureRandom

class DouyinShareGateway(
    private val client: ContactShareClient,
    private val pendingShareStore: PendingShareStore,
    private val callbackClassName: String = DouYinEntryActivity::class.java.canonicalName
        ?: DouYinEntryActivity::class.java.name,
    private val stateGenerator: () -> String = ::generateState,
) {
    fun launch(@Suppress("UNUSED_PARAMETER") activity: Activity, card: ApprovedShareCard): ShareLaunchResult {
        if (!client.isInstalled()) {
            return ShareLaunchResult.DouyinNotInstalled
        }
        if (!client.supportsContactShare()) {
            return ShareLaunchResult.ContactShareUnsupported
        }

        val state = stateGenerator().also { require(it.isNotBlank()) { "state must not be blank" } }
        pendingShareStore.save(state)
        if (!client.launch(card, state, callbackClassName)) {
            pendingShareStore.clear()
            return ShareLaunchResult.SdkRejectedRequest
        }
        return ShareLaunchResult.Launched(state)
    }

    private companion object {
        private val secureRandom = SecureRandom()

        fun generateState(): String {
            val bytes = ByteArray(32)
            secureRandom.nextBytes(bytes)
            return buildString(bytes.size * 2) {
                bytes.forEach { byte ->
                    append(HEX_DIGITS[(byte.toInt() ushr 4) and 0x0f])
                    append(HEX_DIGITS[byte.toInt() and 0x0f])
                }
            }
        }

        private const val HEX_DIGITS = "0123456789abcdef"
    }
}
