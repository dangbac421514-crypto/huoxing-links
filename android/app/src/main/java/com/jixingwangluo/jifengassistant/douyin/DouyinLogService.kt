package com.jixingwangluo.jifengassistant.douyin

import android.util.Log
import com.bytedance.sdk.open.aweme.core.OpenLogService
import com.jixingwangluo.jifengassistant.BuildConfig

class DouyinLogService : OpenLogService {
    override fun d(tag: String, message: String) = write(Log.DEBUG, tag, message)

    override fun i(tag: String, message: String) = write(Log.INFO, tag, message)

    override fun w(tag: String, message: String) = write(Log.WARN, tag, message)

    override fun e(tag: String, message: String) = write(Log.ERROR, tag, message)

    private fun write(priority: Int, rawTag: String?, rawMessage: String?) {
        if (!BuildConfig.DEBUG) {
            return
        }
        val tag = sanitize(rawTag).take(64).ifBlank { "DouyinSdk" }
        val message = sanitize(rawMessage).take(200)
        Log.println(priority, tag, message)
    }

    private fun sanitize(value: String?): String = value.orEmpty()
        .filterNot { it.isISOControl() }
        .replace(Regex("[\\r\\n\\t]+"), " ")
        .replace(Regex("https?://\\S+", RegexOption.IGNORE_CASE), "[redacted-url]")
        .replace(
            Regex("(?i)(state|token|secret|password|device(?:id)?|install(?:id)?|extras?)\\s*[:=]\\s*\\S+"),
            "$1=[redacted]",
        )
}
