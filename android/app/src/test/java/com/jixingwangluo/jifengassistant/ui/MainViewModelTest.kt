package com.jixingwangluo.jifengassistant.ui

import com.jixingwangluo.jifengassistant.douyin.ShareLaunchResult
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MainViewModelTest {
    private val card = ApprovedShareCard.create(
        url = "https://link.bjaajsdad.xyz/douyin/jifeng-assistant",
        title = "极风小助手",
        description = "已审核链接的管理与分享工具",
        thumbUrl = null,
    )

    @Test
    fun shareIsDisabledBeforePrivacyConsent() {
        val viewModel = MainViewModel(card, privacyAccepted = false)

        assertFalse(viewModel.state.value.shareEnabled)
        assertFalse(viewModel.onShareRequested())
        assertFalse(viewModel.state.value.inProgress)
    }

    @Test
    fun consentEnablesShareWhenCardIsValid() {
        val viewModel = MainViewModel(card, privacyAccepted = false)

        viewModel.onPrivacyAccepted()

        assertTrue(viewModel.state.value.privacyAccepted)
        assertTrue(viewModel.state.value.shareEnabled)
        assertTrue(viewModel.onShareRequested())
        assertTrue(viewModel.state.value.inProgress)
    }

    @Test
    fun launchedStateShowsWaitingForDouyin() {
        val viewModel = MainViewModel(card, privacyAccepted = true)

        viewModel.onShareRequested()
        viewModel.onShareLaunchResult(ShareLaunchResult.Launched("state"))

        assertFalse(viewModel.state.value.shareEnabled)
        assertTrue(viewModel.state.value.statusText.contains("已打开抖音"))
    }

    @Test
    fun callbackResultReplacesWaitingState() {
        val viewModel = MainViewModel(card, privacyAccepted = true)

        viewModel.onShareRequested()
        viewModel.onShareLaunchResult(ShareLaunchResult.Launched("state"))
        viewModel.onCallback("CANCELLED", 20004, "token=do-not-show")

        assertTrue(viewModel.state.value.shareEnabled)
        assertTrue(viewModel.state.value.statusText.contains("取消"))
        assertFalse(viewModel.state.value.statusText.contains("token"))
    }

    @Test
    fun launchFailuresAreExplicitAndDoNotExposeRawMessages() {
        val expected = listOf(
            ShareLaunchResult.DouyinNotInstalled to "未检测到抖音",
            ShareLaunchResult.ContactShareUnsupported to "不支持",
            ShareLaunchResult.SdkRejectedRequest to "拒绝",
        )

        expected.forEach { (result, text) ->
            val viewModel = MainViewModel(card, privacyAccepted = true)
            viewModel.onShareRequested()
            viewModel.onShareLaunchResult(result)
            assertTrue(viewModel.state.value.statusText.contains(text))
            assertTrue(viewModel.state.value.shareEnabled)
        }
    }

    @Test
    fun callbackBranchesUseUnderstandableSafeStatuses() {
        val expected = listOf(
            "SUCCESS" to "分享结果",
            "CANCELLED" to "取消",
            "URL_NOT_APPROVED" to "审核",
            "NETWORK_ERROR" to "网络",
            "PERMISSION_OR_PACKAGE_MISMATCH" to "权限或应用配置",
            "UNKNOWN_ERROR" to "未识别",
        )

        expected.forEach { (type, text) ->
            val viewModel = MainViewModel(card, privacyAccepted = true)
            viewModel.onShareRequested()
            viewModel.onCallback(type, 20017, "secret=should-not-appear")
            assertTrue("$type: ${viewModel.state.value.statusText}", viewModel.state.value.statusText.contains(text))
            assertFalse(viewModel.state.value.statusText.contains("secret"))
            assertFalse(viewModel.state.value.inProgress)
        }
    }
}
