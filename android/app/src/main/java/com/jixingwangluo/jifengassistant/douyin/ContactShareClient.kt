package com.jixingwangluo.jifengassistant.douyin

import com.jixingwangluo.jifengassistant.model.ApprovedShareCard

interface ContactShareClient {
    fun isInstalled(): Boolean

    fun supportsContactShare(): Boolean

    fun launch(card: ApprovedShareCard, state: String, callbackClassName: String): Boolean
}

sealed interface ShareLaunchResult {
    data class Launched(val state: String) : ShareLaunchResult

    data object DouyinNotInstalled : ShareLaunchResult

    data object ContactShareUnsupported : ShareLaunchResult

    data object SdkRejectedRequest : ShareLaunchResult
}
