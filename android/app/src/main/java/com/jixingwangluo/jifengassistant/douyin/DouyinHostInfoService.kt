package com.jixingwangluo.jifengassistant.douyin

import android.content.Context
import android.content.pm.PackageInfo
import android.os.Build
import android.util.SparseArray
import com.bytedance.sdk.open.aweme.core.OpenHostInfoService

class DouyinHostInfoService(context: Context) : OpenHostInfoService {
    private val appContext = context.applicationContext
    private val packageInfo: PackageInfo = appContext.packageManager.getPackageInfo(appContext.packageName, 0)

    override fun getDeviceId(): String = ""

    override fun getChannel(): String = ""

    override fun getAppId(): String = appContext.packageName

    override fun getAppName(): String = appContext.applicationInfo
        .loadLabel(appContext.packageManager)
        .toString()

    override fun getUpdateVersionCode(): String = versionCode()

    override fun getVersionCode(): String = versionCode()

    override fun getVersionName(): String = packageInfo.versionName.orEmpty()

    override fun getInstallId(): String = ""

    override fun extraInfo(): SparseArray<String> = SparseArray()

    private fun versionCode(): String = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        packageInfo.longVersionCode.toString()
    } else {
        @Suppress("DEPRECATION")
        packageInfo.versionCode.toString()
    }
}
