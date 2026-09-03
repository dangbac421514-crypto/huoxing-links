package com.jixingwangluo.jifengassistant

import android.app.Application
import android.content.Context
import androidx.test.runner.AndroidJUnitRunner
import com.jixingwangluo.jifengassistant.douyin.ContactShareClient
import com.jixingwangluo.jifengassistant.douyin.DouyinShareGateway
import com.jixingwangluo.jifengassistant.douyin.InMemoryPendingShareStore
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import com.jixingwangluo.jifengassistant.privacy.PrivacyConsentStore

object JifengTestStartupState {
    var initializeCalls: Int = 0
}

object JifengTestStartupGraph {
    fun install(@Suppress("UNUSED_PARAMETER") context: Context) {
        AppGraph.testFactory = {
            AppGraph.forTesting(
                card = null,
                privacyConsentStore = NoConsentStore,
                initializer = StartupInitializer,
                gateway = DouyinShareGateway(NoContactShareClient, InMemoryPendingShareStore()),
            )
        }
    }
}

class JifengTestRunner : AndroidJUnitRunner() {
    override fun newApplication(
        cl: ClassLoader,
        className: String?,
        context: Context,
    ): Application = super.newApplication(cl, JifengTestApplication::class.java.name, context)
}

class JifengTestApplication : JifengApplication() {
    override fun onCreate() {
        JifengTestStartupGraph.install(this)
        super.onCreate()
    }
}

private object NoConsentStore : PrivacyConsentStore {
    override fun isAccepted(): Boolean = false

    override fun setAccepted() = Unit
}

private object StartupInitializer : PrivacyInitializer {
    override fun initialize() {
        JifengTestStartupState.initializeCalls += 1
    }

    override fun onPrivacyAccepted() = Unit
}

private object NoContactShareClient : ContactShareClient {
    override fun isInstalled(): Boolean = false

    override fun supportsContactShare(): Boolean = false

    override fun launch(card: ApprovedShareCard, state: String, callbackClassName: String): Boolean = false
}
