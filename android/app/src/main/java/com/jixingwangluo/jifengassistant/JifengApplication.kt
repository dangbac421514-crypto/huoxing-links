package com.jixingwangluo.jifengassistant

import android.app.Application

class JifengApplication : Application() {
    lateinit var appGraph: AppGraph
        private set

    override fun onCreate() {
        super.onCreate()
        val testFactory = AppGraph.testFactory
        appGraph = testFactory?.invoke(this) ?: AppGraph.production(this)
        if (testFactory == null && appGraph.privacyConsentStore.isAccepted()) {
            runCatching { appGraph.initializer.initialize() }
                .onFailure { appGraph.configurationError = "抖音应用配置不可用，当前无法分享" }
        }
    }
}
