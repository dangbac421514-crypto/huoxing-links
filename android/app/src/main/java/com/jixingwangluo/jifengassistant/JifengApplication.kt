package com.jixingwangluo.jifengassistant

import android.app.Application

class JifengApplication : Application() {
    lateinit var appGraph: AppGraph
        private set

    override fun onCreate() {
        super.onCreate()
        appGraph = AppGraph.production(this)
        if (appGraph.privacyConsentStore.isAccepted()) {
            runCatching { appGraph.initializer.initialize() }
                .onFailure { appGraph.configurationError = "抖音应用配置不可用，当前无法分享" }
        }
    }
}
