package com.jixingwangluo.jifengassistant.douyin

sealed interface ShareResult {
    data object Success : ShareResult
    data object Cancelled : ShareResult
    data class PermissionOrPackageMismatch(val code: Int, val message: String?) : ShareResult
    data class UrlNotApproved(val code: Int, val message: String?) : ShareResult
    data class NetworkError(val code: Int, val message: String?) : ShareResult
    data class Unknown(val code: Int, val message: String?) : ShareResult
}

object ShareResultMapper {
    fun from(errorCode: Int, isCancel: Boolean, errorMessage: String?): ShareResult {
        val message = sanitize(errorMessage)
        return when {
            errorCode == 20000 -> ShareResult.Success
            isCancel || errorCode == 20004 || errorCode == 20013 -> ShareResult.Cancelled
            errorCode == 20003 -> ShareResult.PermissionOrPackageMismatch(errorCode, message)
            errorCode == 20006 -> ShareResult.NetworkError(errorCode, message)
            errorCode == 20017 -> ShareResult.UrlNotApproved(errorCode, message)
            else -> ShareResult.Unknown(errorCode, message)
        }
    }

    private fun sanitize(message: String?): String? = message
        ?.filterNot { Character.isISOControl(it) }
        ?.take(200)
}
