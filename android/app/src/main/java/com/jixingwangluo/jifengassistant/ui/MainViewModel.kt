package com.jixingwangluo.jifengassistant.ui

import androidx.lifecycle.ViewModel
import com.jixingwangluo.jifengassistant.douyin.ShareLaunchResult
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

class MainViewModel(
    card: ApprovedShareCard,
    privacyAccepted: Boolean,
) : ViewModel() {
    private val _state = MutableStateFlow(MainUiState(card, privacyAccepted))
    val state: StateFlow<MainUiState> = _state.asStateFlow()

    fun onPrivacyAccepted() {
        _state.value = _state.value.copy(privacyAccepted = true, statusText = "")
    }

    fun onPrivacyDeclined() {
        _state.value = _state.value.copy(
            inProgress = false,
            statusText = "请先同意隐私提示后再分享",
        )
    }

    fun onShareRequested(): Boolean {
        if (!_state.value.shareEnabled) {
            return false
        }
        _state.value = _state.value.copy(
            inProgress = true,
            statusText = "正在打开抖音，请在抖音中选择好友或群",
        )
        return true
    }

    fun onShareLaunchResult(result: ShareLaunchResult) {
        _state.value = when (result) {
            is ShareLaunchResult.Launched -> _state.value.copy(
                inProgress = true,
                statusText = "已打开抖音，请选择好友或群并确认发送",
            )
            ShareLaunchResult.DouyinNotInstalled -> _state.value.copy(
                inProgress = false,
                statusText = "未检测到抖音，请安装后重试",
            )
            ShareLaunchResult.ContactShareUnsupported -> _state.value.copy(
                inProgress = false,
                statusText = "当前抖音版本不支持好友/群分享",
            )
            ShareLaunchResult.SdkRejectedRequest -> _state.value.copy(
                inProgress = false,
                statusText = "抖音拒绝了分享请求，请稍后重试",
            )
        }
    }

    /** Maps the narrow public callback extras into safe, user-facing copy. */
    fun onCallback(resultType: String?, errorCode: Int?, @Suppress("UNUSED_PARAMETER") rawMessage: String?) {
        val codeSuffix = errorCode?.takeIf { it != 0 }?.let { "（错误码 $it）" }.orEmpty()
        val status = when (resultType) {
            "SUCCESS" -> "抖音已返回分享结果，请确认接收方是否看到卡片"
            "CANCELLED" -> "已取消分享"
            "NOT_SUPPORTED" -> "当前抖音版本不支持好友/群分享"
            "URL_NOT_APPROVED" -> "分享链接尚未通过抖音审核"
            "NETWORK_ERROR" -> "网络暂不可用，请检查后重试"
            "PERMISSION_OR_PACKAGE_MISMATCH" -> "抖音权限或应用配置不匹配"
            "UNKNOWN_ERROR" -> "抖音返回了未识别的结果$codeSuffix"
            else -> "分享未完成，请稍后重试$codeSuffix"
        }
        _state.value = _state.value.copy(inProgress = false, statusText = status)
    }
}
