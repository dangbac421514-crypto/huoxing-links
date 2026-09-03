package com.jixingwangluo.jifengassistant.douyin

import android.app.Activity
import com.bytedance.sdk.open.douyin.DouYinOpenApiFactory
import com.bytedance.sdk.open.douyin.ShareToContact
import com.bytedance.sdk.open.douyin.model.ContactHtmlObject
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard

class DouyinContactShareClient(private val activity: Activity) : ContactShareClient {
    override fun isInstalled(): Boolean = api().isAppInstalled

    override fun supportsContactShare(): Boolean = api().isAppSupportShareToContacts

    override fun launch(card: ApprovedShareCard, state: String, callbackClassName: String): Boolean {
        val htmlObject = ContactHtmlObject().apply {
            setHtml(card.url)
            setTitle(card.title)
            setDiscription(card.description)
            card.thumbUrl?.let(::setThumbUrl)
        }
        val request = ShareToContact.Request().apply {
            callerLocalEntry = callbackClassName
            mState = state
            this.htmlObject = htmlObject
        }
        return api().shareToContacts(request)
    }

    private fun api() = DouYinOpenApiFactory.create(activity)
}
