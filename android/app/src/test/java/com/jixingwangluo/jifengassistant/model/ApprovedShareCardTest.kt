package com.jixingwangluo.jifengassistant.model

import java.net.URI
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Test

class ApprovedShareCardTest {
    @Test
    fun acceptsApprovedHttpsCard() {
        val card = ApprovedShareCard.create(
            "https://link.example.test/douyin/jifeng-assistant",
            "极风小助手",
            "已审核链接的管理与分享工具",
            null,
        )

        assertEquals("link.example.test", URI(card.url).host)
    }

    @Test
    fun rejectsUnsafeOrIncompleteCards() {
        assertThrows(IllegalArgumentException::class.java) {
            ApprovedShareCard.create("http://link.example.test", "极风小助手", "描述", null)
        }
        assertThrows(IllegalArgumentException::class.java) {
            ApprovedShareCard.create("https://user@link.example.test/#x", "极风小助手", "描述", null)
        }
        assertThrows(IllegalArgumentException::class.java) {
            ApprovedShareCard.create("https://link.example.test", " ", "描述", null)
        }
    }

    @Test
    fun rejectsNonHttpsThumbnail() {
        assertThrows(IllegalArgumentException::class.java) {
            ApprovedShareCard.create(
                "https://link.example.test/douyin/jifeng-assistant",
                "极风小助手",
                "描述",
                "http://cdn.example.test/card.png",
            )
        }
    }

    @Test
    fun blankThumbnailNormalizesToNull() {
        val card = ApprovedShareCard.create(
            "https://link.example.test/douyin/jifeng-assistant",
            "极风小助手",
            "描述",
            " \t",
        )

        assertNull(card.thumbUrl)
    }

    @Test
    fun doesNotExposeCopyOrPublicInvariantBypass() {
        assertFalse(ApprovedShareCard::class.java.declaredMethods.any { it.name == "copy" })
        assertThrows(IllegalArgumentException::class.java) {
            ApprovedShareCard("http://link.example.test", "极风小助手", "描述", null)
        }
    }
}
