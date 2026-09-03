package com.jixingwangluo.jifengassistant.douyin

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.bytedance.sdk.open.aweme.common.handler.IApiEventHandler
import com.bytedance.sdk.open.aweme.common.model.BaseReq
import com.bytedance.sdk.open.aweme.common.model.BaseResp
import com.bytedance.sdk.open.douyin.DouYinOpenApiFactory
import com.bytedance.sdk.open.douyin.ShareToContact
import com.jixingwangluo.jifengassistant.MainActivity

class DouYinEntryActivity : AppCompatActivity(), IApiEventHandler {
    private val pendingShareStore: PendingShareStore by lazy {
        SharedPreferencesPendingShareStore(applicationContext)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        handleSdkIntent(intent)
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        if (intent != null) {
            setIntent(intent)
            handleSdkIntent(intent)
        }
    }

    override fun onReq(request: BaseReq) = Unit

    override fun onResp(response: BaseResp) {
        if (response !is ShareToContact.Response) {
            return
        }
        val state = response.mState ?: return
        if (!pendingShareStore.consumeIfMatches(state)) {
            return
        }
        val result = ShareResultMapper.from(response.errorCode, response.isCancel, response.errorMsg)
        val callbackIntent = Intent(this, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra(EXTRA_RESULT_TYPE, resultType(result))
            putExtra(EXTRA_ERROR_CODE, response.errorCode)
            resultMessage(result)?.let { putExtra(EXTRA_ERROR_MESSAGE, it) }
        }
        startActivity(callbackIntent)
        finish()
    }

    override fun onErrorIntent(intent: Intent) = Unit

    private fun handleSdkIntent(intent: Intent) {
        DouYinOpenApiFactory.create(this).handleIntent(intent, this)
    }

    private fun resultType(result: ShareResult): String = when (result) {
        ShareResult.Success -> "SUCCESS"
        ShareResult.Cancelled -> "CANCELLED"
        is ShareResult.PermissionOrPackageMismatch -> "PERMISSION_OR_PACKAGE_MISMATCH"
        is ShareResult.UrlNotApproved -> "URL_NOT_APPROVED"
        is ShareResult.NetworkError -> "NETWORK_ERROR"
        is ShareResult.Unknown -> "UNKNOWN_ERROR"
    }

    private fun resultMessage(result: ShareResult): String? = when (result) {
        ShareResult.Success, ShareResult.Cancelled -> null
        is ShareResult.PermissionOrPackageMismatch -> result.message
        is ShareResult.UrlNotApproved -> result.message
        is ShareResult.NetworkError -> result.message
        is ShareResult.Unknown -> result.message
    }

    companion object {
        const val EXTRA_RESULT_TYPE = "douyin_result_type"
        const val EXTRA_ERROR_CODE = "douyin_error_code"
        const val EXTRA_ERROR_MESSAGE = "douyin_error_message"
    }
}
