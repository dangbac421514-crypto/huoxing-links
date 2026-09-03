package com.jixingwangluo.jifengassistant.douyin

import android.app.Activity
import com.jixingwangluo.jifengassistant.model.ApprovedShareCard
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DouyinShareGatewayTest {
    private val card = ApprovedShareCard.create(
        url = "https://share.example/jifeng",
        title = "极风小助手",
        description = "固定审核卡片",
        thumbUrl = null,
    )

    @Test
    fun rejectsWhenDouyinIsNotInstalled() {
        val client = FakeContactShareClient(installed = false)
        val pending = InMemoryPendingShareStore()

        val result = gateway(client, pending).launch(Activity(), card)

        assertEquals(ShareLaunchResult.DouyinNotInstalled, result)
        assertNull(pending.peek())
        assertNull(client.state)
    }

    @Test
    fun rejectsWhenContactShareIsUnsupported() {
        val client = FakeContactShareClient(supportsContactShare = false)
        val pending = InMemoryPendingShareStore()

        val result = gateway(client, pending).launch(Activity(), card)

        assertEquals(ShareLaunchResult.ContactShareUnsupported, result)
        assertNull(pending.peek())
        assertNull(client.state)
    }

    @Test
    fun persistsRandomStateBeforeLaunching() {
        val pendingState = InMemoryPendingShareStore()
        val client = FakeContactShareClient(onLaunch = { _, state, _ ->
            assertEquals(state, pendingState.peek())
            true
        })
        val gateway = gateway(client, pendingState)

        val first = gateway.launch(Activity(), card)
        val firstState = (first as ShareLaunchResult.Launched).state

        assertNotNull(firstState)
        assertEquals(firstState, pendingState.peek())
        assertEquals(card, client.card)
        assertEquals("com.example.Callback", client.callbackClassName)

        val second = gateway.launch(Activity(), card)
        val secondState = (second as ShareLaunchResult.Launched).state
        assertNotEquals(firstState, secondState)
    }

    @Test
    fun clearsPendingStateWhenSdkRejectsLaunch() {
        val client = FakeContactShareClient(onLaunch = { _, _, _ -> false })
        val pending = InMemoryPendingShareStore()

        val result = gateway(client, pending).launch(Activity(), card)

        assertEquals(ShareLaunchResult.SdkRejectedRequest, result)
        assertNull(pending.peek())
    }

    private fun gateway(
        client: ContactShareClient,
        pending: InMemoryPendingShareStore,
    ): DouyinShareGateway = DouyinShareGateway(
        client = client,
        pendingShareStore = pending,
        callbackClassName = "com.example.Callback",
    )

    private class FakeContactShareClient(
        private val installed: Boolean = true,
        private val supportsContactShare: Boolean = true,
        private val onLaunch: (ApprovedShareCard, String, String) -> Boolean = { _, _, _ -> true },
    ) : ContactShareClient {
        var card: ApprovedShareCard? = null
        var state: String? = null
        var callbackClassName: String? = null

        override fun isInstalled(): Boolean = installed

        override fun supportsContactShare(): Boolean = supportsContactShare

        override fun launch(card: ApprovedShareCard, state: String, callbackClassName: String): Boolean {
            this.card = card
            this.state = state
            this.callbackClassName = callbackClassName
            return onLaunch(card, state, callbackClassName)
        }
    }
}
