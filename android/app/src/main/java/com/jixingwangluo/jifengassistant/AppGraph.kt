package com.jixingwangluo.jifengassistant

import android.app.Activity
import android.content.Context
import com.jixingwangluo.jifengassistant.douyin.DouYinEntryActivity
import com.jixingwangluo.jifengassistant.douyin.DouyinContactShareClient
import com.jixingwangluo.jifengassistant.douyin.DouyinSdkInitializer
import com.jixingwangluo.jifengassistant.douyin.DouyinShareGateway
import com.jixingwangluo.jifengassistant.douyin.SharedPreferencesPendingShareStore
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import com.jixingwangluo.jifengassistant.privacy.PrivacyConsentStore
import com.jixingwangluo.jifengassistant.privacy.SharedPreferencesPrivacyConsentStore

interface PrivacyInitializer {
    fun initialize()

    fun onPrivacyAccepted()
}

class AppGraph(
    val card: ApprovedShareCard?,
    val privacyConsentStore: PrivacyConsentStore,
    val initializer: PrivacyInitializer,
    private val gatewayFactory: (Activity) -> DouyinShareGateway,
    var configurationError: String? = null,
) {
    fun createShareGateway(activity: Activity): DouyinShareGateway = gatewayFactory(activity)

    companion object {
        fun production(context: Context): AppGraph {
            val cardResult = runCatching {
                ApprovedShareCard.create(
                    BuildConfig.DOUYIN_APPROVED_SHARE_URL,
                    BuildConfig.DOUYIN_CARD_TITLE,
                    BuildConfig.DOUYIN_CARD_DESCRIPTION,
                    BuildConfig.DOUYIN_CARD_THUMB_URL,
                )
            }
            val initializer = DouyinInitializerAdapter(
                DouyinSdkInitializer(context, BuildConfig.DOUYIN_CLIENT_KEY),
            )
            val error = cardResult.exceptionOrNull()?.let { "应用卡片配置不可用，当前无法分享" }
                ?: BuildConfig.DOUYIN_CLIENT_KEY.takeIf { it.isBlank() }
                    ?.let { "抖音应用配置不可用，当前无法分享" }
            return AppGraph(
                card = cardResult.getOrNull(),
                privacyConsentStore = SharedPreferencesPrivacyConsentStore(context),
                initializer = initializer,
                gatewayFactory = { activity ->
                    DouyinShareGateway(
                        DouyinContactShareClient(activity),
                        SharedPreferencesPendingShareStore(activity),
                        DouYinEntryActivity::class.java.canonicalName
                            ?: DouYinEntryActivity::class.java.name,
                    )
                },
                configurationError = error,
            )
        }

        fun forTesting(
            card: ApprovedShareCard,
            privacyConsentStore: PrivacyConsentStore,
            initializer: PrivacyInitializer,
            gateway: DouyinShareGateway,
        ): AppGraph = AppGraph(
            card = card,
            privacyConsentStore = privacyConsentStore,
            initializer = initializer,
            gatewayFactory = { gateway },
        )
    }

    private class DouyinInitializerAdapter(
        private val delegate: DouyinSdkInitializer,
    ) : PrivacyInitializer {
        override fun initialize() = delegate.initialize()

        override fun onPrivacyAccepted() = delegate.onPrivacyAccepted()
    }
}
