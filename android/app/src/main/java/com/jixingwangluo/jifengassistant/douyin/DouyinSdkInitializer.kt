package com.jixingwangluo.jifengassistant.douyin

import android.content.Context
import com.bytedance.sdk.open.aweme.adapter.openevent.OpenTrackerManager
import com.bytedance.sdk.open.aweme.init.DouYinOpenSDKConfig
import com.bytedance.sdk.open.douyin.DouYinOpenApiFactory

class DouyinSdkInitializer(
    context: Context,
    private val clientKey: String,
    private val hostInfoService: DouyinHostInfoService = DouyinHostInfoService(context),
    private val logService: DouyinLogService = DouyinLogService(),
) {
    private val appContext = context.applicationContext
    private var initialized = false

    @Synchronized
    fun initialize() {
        require(clientKey.isNotBlank()) { "Douyin Client Key must not be blank" }
        if (initialized) {
            return
        }
        val config = DouYinOpenSDKConfig.Builder()
            .context(appContext)
            .clientKey(clientKey)
            .hostInfoService(hostInfoService)
            .logService(logService)
            .autoStartTracker(false)
            .build()
        DouYinOpenApiFactory.initConfig(config)
        initialized = true
    }

    /** Called only after the host has obtained privacy consent. */
    @Synchronized
    fun onPrivacyAccepted() {
        initialize()
        OpenTrackerManager.start()
    }
}
