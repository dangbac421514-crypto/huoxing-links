package com.jixingwangluo.jifengassistant

import org.junit.Assert.assertEquals
import org.junit.Test

class BuildConfigEncodingTest {
    @Test
    fun configuredCardCopyIsExactUtf8OrIntentionallyBlank() {
        if (BuildConfig.DOUYIN_CARD_TITLE.isBlank() && BuildConfig.DOUYIN_CARD_DESCRIPTION.isBlank()) {
            return
        }

        assertEquals("极风小助手", BuildConfig.DOUYIN_CARD_TITLE)
        assertEquals("已审核链接的管理与分享工具", BuildConfig.DOUYIN_CARD_DESCRIPTION)
    }
}
